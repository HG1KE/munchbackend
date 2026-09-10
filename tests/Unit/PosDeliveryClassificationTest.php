<?php

namespace Tests\Unit;

use App\CentralLogics\CustomerOrderStatusSms;
use App\CentralLogics\PosDeliveryCustomerSms;
use App\Model\Order;
use App\Services\DashboardOrderOperationsService;
use App\Support\PosOrderTypes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosDeliveryClassificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('branch_id')->default(1);
            $table->string('order_status')->default('pending');
            $table->string('order_type')->default('delivery');
            $table->string('sales_channel')->nullable();
            $table->date('delivery_date')->nullable();
            $table->boolean('checked')->default(false);
            $table->timestamps();
        });
    }

    public function test_pos_delivery_is_stored_as_pos_with_delivery_channel(): void
    {
        $this->assertSame('pos', PosOrderTypes::databaseType('delivery'));
        $this->assertSame('delivery', PosOrderTypes::salesChannel('delivery'));
        $this->assertTrue(PosOrderTypes::isPosDeliveryOrder('pos', 'delivery'));
        $this->assertFalse(PosOrderTypes::isPosDeliveryOrder('delivery', null));
        $this->assertFalse(PosOrderTypes::isOnlineOrder('pos', 'delivery'));
        $this->assertTrue(PosOrderTypes::isOnlineOrder('delivery', null));
    }

    public function test_pos_delivery_appears_only_in_pos_orders(): void
    {
        $this->insert(1, 'pos', 'delivery');
        $this->insert(2, 'delivery', null);
        $this->insert(3, 'pos', 'takeaway');
        $this->insert(4, 'pos', 'glovo');
        $this->insert(5, 'delivery', 'delivery');

        $this->assertSame([1, 3, 4, 5], Order::query()->pos()->orderBy('id')->pluck('id')->all());
        $this->assertSame([2], Order::query()->onlineOrders()->orderBy('id')->pluck('id')->all());
        $this->assertSame([2], Order::query()->notPos()->notDineIn()->orderBy('id')->pluck('id')->all());
    }

    public function test_online_orders_count_and_alert_ignore_pos_delivery(): void
    {
        $this->insert(10, 'delivery', null, 'pending');
        $this->insert(11, 'pos', 'delivery', 'confirmed');
        $this->insert(12, 'delivery', 'delivery', 'pending');
        $this->insert(13, 'pos', 'uber', 'pending');
        $this->insert(14, 'pos', 'bolt_food', 'pending');
        $this->insert(15, 'pos', 'takeaway', 'pending');
        $this->insert(16, 'dine_in', 'dine_in', 'confirmed');

        $ops = app(DashboardOrderOperationsService::class);

        $this->assertSame([10], $ops->pendingQueueQuery(null)->orderBy('id')->pluck('id')->all());
        $this->assertSame(1, $ops->onlineActiveCount(null));
        $this->assertSame(1, $ops->dashboardCounts(null)['online']);
        $this->assertSame(1, $ops->pendingOrderAlertPayload(null)['new_order']);
    }

    public function test_website_delivery_still_qualifies_as_an_online_order(): void
    {
        $this->insert(20, 'delivery', null, 'pending');
        $this->insert(21, 'take_away', null, 'pending');

        $ops = app(DashboardOrderOperationsService::class);
        $this->assertSame([20, 21], $ops->pendingQueueQuery(null)->orderBy('id')->pluck('id')->all());
        $this->assertTrue(PosOrderTypes::isOnlineOrder('delivery', null));
        $this->assertTrue(PosOrderTypes::isOnlineOrder('take_away', null));
        $this->assertFalse(PosDeliveryCustomerSms::isPosDeliveryOrder(Order::query()->find(20)));
    }

    public function test_marketplace_orders_remain_pos_only(): void
    {
        foreach (['glovo', 'uber', 'bolt_food'] as $i => $channel) {
            $this->insert(30 + $i, 'pos', $channel, 'delivered');
            $this->assertFalse(PosOrderTypes::isOnlineOrder('pos', $channel), $channel);
            $this->assertTrue(PosOrderTypes::isPosFamily('pos', $channel), $channel);
            $this->assertFalse(PosOrderTypes::isPosDeliveryOrder('pos', $channel), $channel);
        }

        $this->assertSame([], app(DashboardOrderOperationsService::class)->pendingQueueQuery(null)->pluck('id')->all());
        $this->assertSame([30, 31, 32], Order::query()->pos()->orderBy('id')->pluck('id')->all());
    }

    public function test_pos_delivery_sends_only_pos_delivery_customer_sms(): void
    {
        $posDelivery = new Order();
        $posDelivery->order_type = 'pos';
        $posDelivery->sales_channel = 'delivery';
        $this->assertTrue(PosDeliveryCustomerSms::isPosDeliveryOrder($posDelivery));

        $sms = file_get_contents(app_path('CentralLogics/CustomerOrderStatusSms.php'));
        $this->assertStringContainsString('PosOrderTypes::isPosFamily', $sms);
        $this->assertGreaterThan(
            strpos($sms, 'function dispatchPlacement'),
            strpos($sms, 'isPosFamily')
        );

        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $this->assertStringContainsString('dispatchPosDeliveryCustomerSms($order)', $controller);
        $this->assertStringContainsString('! PosOrderTypes::isPosFamily($order->order_type, $order->sales_channel)', $controller);
        $this->assertStringNotContainsString('textsms_ke_not', $controller);
        $this->assertStringNotContainsString('SmsTemplateCatalog::ORDER_PLACED', $controller);
        $this->assertStringNotContainsString('SmsTemplateCatalog::PROCESSING', $controller);
    }

    public function test_customer_status_sms_skips_pos_family_without_sending(): void
    {
        $order = new Order();
        $order->id = 99;
        $order->order_type = 'pos';
        $order->sales_channel = 'delivery';
        $order->order_status = 'confirmed';
        $order->customer_placement_sms_sent_at = null;

        CustomerOrderStatusSms::dispatchPlacement($order);
        CustomerOrderStatusSms::dispatchProcessing($order, 'confirmed');

        $this->assertNull($order->customer_placement_sms_sent_at);
        $this->assertNull($order->customer_processing_sms_sent_at);
    }

    private function insert(int $id, string $type, ?string $channel, string $status = 'pending'): void
    {
        DB::table('orders')->insert([
            'id' => $id,
            'branch_id' => 1,
            'order_status' => $status,
            'order_type' => $type,
            'sales_channel' => $channel,
            'delivery_date' => now()->format('Y-m-d'),
            'checked' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
