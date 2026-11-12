<?php

namespace Tests\Unit;

use App\CentralLogics\CustomerOrderStatusSms;
use App\Model\Order;
use App\Services\OnlineOrderPosAcceptService;
use App\Support\PosOrderTypes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerOrderStatusSmsPosAcceptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['order_details', 'orders', 'branches', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('readable_order_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->default(1);
            $table->string('order_status')->default('pending');
            $table->string('order_type')->default('delivery');
            $table->string('sales_channel')->nullable();
            $table->string('payment_status')->default('unpaid');
            $table->string('payment_method')->nullable();
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->decimal('delivery_charge', 24, 2)->default(0);
            $table->text('delivery_address')->nullable();
            $table->boolean('is_guest')->default(true);
            $table->boolean('checked')->default(false);
            $table->timestamp('customer_placement_sms_sent_at')->nullable();
            $table->timestamp('customer_processing_sms_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->timestamps();
        });

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Mtwapa', 'phone' => '0700000001', 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Bamburi', 'phone' => '0700000003', 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_accept_service_dispatches_processing_sms_only_on_first_convert(): void
    {
        $service = file_get_contents(app_path('Services/OnlineOrderPosAcceptService.php'));
        $this->assertStringContainsString('use App\\CentralLogics\\CustomerOrderStatusSms;', $service);
        $this->assertStringContainsString('CustomerOrderStatusSms::dispatchProcessingOnPosAccept', $service);
        $this->assertStringContainsString('empty($result[\'idempotent\'])', $service);
        $this->assertStringContainsString('empty($result[\'duplicate\'])', $service);

        $sms = file_get_contents(app_path('CentralLogics/CustomerOrderStatusSms.php'));
        $this->assertStringContainsString('function dispatchProcessingOnPosAccept', $sms);
        $this->assertStringContainsString('SmsTemplateCatalog::PROCESSING', $sms);
        $this->assertStringContainsString('customer_processing_sms_sent_at', $sms);
        $this->assertStringContainsString('function sendProcessingSms', $sms);

        // Keep catalog constant off the Branch POS controller (classification contract).
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $this->assertStringNotContainsString('SmsTemplateCatalog::PROCESSING', $controller);
        $this->assertStringNotContainsString('dispatchProcessingOnPosAccept', $controller);
    }

    public function test_transfer_then_accept_template_data_uses_target_branch(): void
    {
        $this->seedOnlineOrder(901, 1);

        // Simulate MN-56 reassign: only branch_id changes while still pending online.
        Order::query()->whereKey(901)->update(['branch_id' => 3]);

        $result = app(OnlineOrderPosAcceptService::class)->accept(901, 3);
        $this->assertTrue($result['success']);
        $this->assertFalse($result['idempotent']);

        /** @var Order $order */
        $order = $result['order'];
        $this->assertSame(3, (int) $order->branch_id);
        $this->assertTrue(PosOrderTypes::isPosFamily($order->order_type, $order->sales_channel));

        $data = CustomerOrderStatusSms::buildTemplateData($order->fresh(['customer', 'branch']));
        $this->assertSame('Bamburi', $data['branch_name']);
        $this->assertSame('0700000003', $data['branch_phone']);
        $this->assertNotSame('Mtwapa', $data['branch_name']);
        $this->assertNotSame('0700000001', $data['branch_phone']);
    }

    public function test_dispatch_processing_on_pos_accept_skips_when_already_sent(): void
    {
        $this->seedOnlineOrder(902, 1, [
            'order_type' => 'pos',
            'sales_channel' => 'delivery',
            'order_status' => 'confirmed',
            'checked' => 1,
            'customer_processing_sms_sent_at' => now()->subMinute(),
        ]);

        $order = Order::query()->find(902);
        $sentAt = $order->customer_processing_sms_sent_at?->toDateTimeString();

        CustomerOrderStatusSms::dispatchProcessingOnPosAccept($order);

        $fresh = Order::query()->find(902);
        $this->assertSame($sentAt, $fresh->customer_processing_sms_sent_at?->toDateTimeString());
    }

    public function test_normal_dispatch_processing_still_skips_pos_family(): void
    {
        $order = new Order();
        $order->id = 903;
        $order->order_type = 'pos';
        $order->sales_channel = 'delivery';
        $order->order_status = 'processing';
        $order->customer_processing_sms_sent_at = null;

        CustomerOrderStatusSms::dispatchProcessing($order, 'confirmed');

        $this->assertNull($order->customer_processing_sms_sent_at);
    }

    public function test_dispatch_processing_on_pos_accept_noop_when_template_disabled(): void
    {
        $this->seedOnlineOrder(904, 3, [
            'order_type' => 'pos',
            'sales_channel' => 'delivery',
            'order_status' => 'confirmed',
            'checked' => 1,
        ]);

        $order = Order::query()->with(['customer', 'branch'])->find(904);
        CustomerOrderStatusSms::dispatchProcessingOnPosAccept($order);

        $fresh = Order::query()->find(904);
        // No gateway/template seeded in this unit schema → isTemplateSendable false → no stamp.
        $this->assertNull($fresh->customer_processing_sms_sent_at);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedOnlineOrder(int $id, int $branchId, array $overrides = []): void
    {
        $now = now();
        DB::table('orders')->insert(array_merge([
            'id' => $id,
            'readable_order_id' => 'A'.$id,
            'user_id' => null,
            'branch_id' => $branchId,
            'order_status' => 'pending',
            'order_type' => 'delivery',
            'sales_channel' => null,
            'payment_status' => 'paid',
            'payment_method' => 'mpesa',
            'order_amount' => 1200,
            'delivery_charge' => 100,
            'delivery_address' => json_encode([
                'contact_person_name' => 'Ada Munch',
                'contact_person_number' => '0712345678',
                'address' => 'Near Total',
            ]),
            'is_guest' => 1,
            'checked' => 0,
            'customer_placement_sms_sent_at' => null,
            'customer_processing_sms_sent_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }
}
