<?php

namespace Tests\Unit;

use App\CentralLogics\Helpers;
use App\CentralLogics\PosOrderCancelledSms;
use App\Model\Branch;
use App\Model\Order;
use App\Model\OrderCancellationAuditLog;
use App\Services\PosOrderCancellationService;
use App\Support\PosCancellationNotificationSettings;
use App\Support\SmsTemplateCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosOrderCancellationTest extends TestCase
{
    private PosOrderCancellationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
        Helpers::forgetBusinessSettingsRuntimeCache();
        $this->service = app(PosOrderCancellationService::class);
    }

    public function test_cancellation_requires_a_reason_of_five_to_five_hundred_characters(): void
    {
        $this->assertNotNull(PosOrderCancellationService::reasonError(''));
        $this->assertNotNull(PosOrderCancellationService::reasonError('abcd'));
        $this->assertNull(PosOrderCancellationService::reasonError('Wrong item'));
        $this->assertNotNull(PosOrderCancellationService::reasonError(str_repeat('a', 501)));
        $this->assertNull(PosOrderCancellationService::reasonError(str_repeat('a', 500)));
    }

    public function test_completed_and_delivered_orders_cannot_be_cancelled(): void
    {
        $completed = $this->makeOrder(['order_status' => 'completed', 'sales_channel' => 'dine_in', 'order_type' => 'dine_in']);
        $this->assertFalse(PosOrderCancellationService::isCancellable($completed));

        $deliveredDelivery = $this->makeOrder([
            'order_status' => 'delivered',
            'sales_channel' => 'delivery',
            'order_type' => 'pos',
        ]);
        $this->assertFalse(PosOrderCancellationService::isCancellable($deliveredDelivery));

        $refunded = $this->makeOrder(['order_status' => 'refunded', 'sales_channel' => 'takeaway']);
        $this->assertFalse(PosOrderCancellationService::isCancellable($refunded));
    }

    public function test_already_cancelled_orders_cannot_be_cancelled_again(): void
    {
        $order = $this->makeOrder(['order_status' => 'canceled', 'sales_channel' => 'takeaway']);
        $this->assertFalse(PosOrderCancellationService::isCancellable($order));
        $this->assertSame('Order is already cancelled.', PosOrderCancellationService::cancellableError($order));
    }

    public function test_takeaway_pos_orders_remain_cancellable_after_placement(): void
    {
        $takeaway = $this->makeOrder([
            'order_status' => 'delivered',
            'sales_channel' => 'takeaway',
            'order_type' => 'pos',
        ]);
        $this->assertTrue(PosOrderCancellationService::isCancellable($takeaway));

        $confirmedDelivery = $this->makeOrder([
            'order_status' => 'confirmed',
            'sales_channel' => 'delivery',
            'order_type' => 'pos',
        ]);
        $this->assertTrue(PosOrderCancellationService::isCancellable($confirmedDelivery));

        $dineIn = $this->makeOrder([
            'order_status' => 'confirmed',
            'sales_channel' => 'dine_in',
            'order_type' => 'dine_in',
        ]);
        $this->assertTrue(PosOrderCancellationService::isCancellable($dineIn));
    }

    public function test_marketplace_orders_are_not_cancellable_from_pos(): void
    {
        foreach (['glovo', 'uber', 'bolt_food'] as $channel) {
            $order = $this->makeOrder([
                'order_status' => 'delivered',
                'sales_channel' => $channel,
                'order_type' => 'pos',
            ]);
            $this->assertFalse(PosOrderCancellationService::isCancellable($order), $channel);
            $this->assertSame(
                'Marketplace orders cannot be cancelled from POS.',
                PosOrderCancellationService::cancellableError($order),
                $channel
            );
        }
    }

    public function test_marketplace_cancel_does_not_change_the_order_or_write_audit(): void
    {
        $order = $this->persistOrder([
            'order_status' => 'delivered',
            'sales_channel' => 'glovo',
            'order_type' => 'pos',
        ]);
        $result = $this->service->cancel($order, 'Cancelled on Glovo app', 'branch', 3);

        $this->assertFalse($result['success']);
        $this->assertSame('not_cancellable', $result['code']);
        $this->assertSame('delivered', $order->fresh()->order_status);
        $this->assertSame(0, OrderCancellationAuditLog::query()->where('order_id', $order->id)->count());
    }

    public function test_cancel_sets_canceled_status_saves_reason_and_writes_audit_log(): void
    {
        $order = $this->persistOrder([
            'order_status' => 'confirmed',
            'sales_channel' => 'delivery',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'order_amount' => 590,
        ]);

        $result = $this->service->cancel($order, 'Customer changed mind', 'branch', 3, 'cancel-uuid-1', 'pos');

        $this->assertTrue($result['success']);
        $this->assertFalse($result['duplicate']);
        $fresh = Order::query()->find($order->id);
        $this->assertSame('canceled', $fresh->order_status);
        $this->assertSame('Customer changed mind', $fresh->cancellation_reason);
        $this->assertSame(3, (int) $fresh->cancelled_by);
        $this->assertSame('branch', $fresh->cancelled_by_type);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertSame('cash', $fresh->payment_method);
        $this->assertSame(590.0, (float) $fresh->order_amount);

        $log = OrderCancellationAuditLog::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($log);
        $this->assertSame('confirmed', $log->previous_status);
        $this->assertSame('canceled', $log->new_status);
        $this->assertSame('Customer changed mind', $log->reason);
        $this->assertSame('branch', $log->actor_type);
        $this->assertSame(3, (int) $log->actor_id);
        $this->assertSame(7, (int) $log->branch_id);
    }

    public function test_duplicate_cancellation_is_prevented_and_keeps_the_original_reason(): void
    {
        $order = $this->persistOrder(['order_status' => 'confirmed', 'sales_channel' => 'dine_in']);
        $first = $this->service->cancel($order, 'First reason here', 'branch', 3, 'uuid-a');
        $this->assertTrue($first['success']);
        $this->assertFalse($first['duplicate']);

        $second = $this->service->cancel($order->fresh(), 'Second reason here', 'branch', 3, 'uuid-b');
        $this->assertTrue($second['success']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame('First reason here', $order->fresh()->cancellation_reason);
        $this->assertSame(1, OrderCancellationAuditLog::query()->where('order_id', $order->id)->count());
    }

    public function test_offline_cancellation_client_uuid_syncs_once(): void
    {
        $order = $this->persistOrder(['order_status' => 'confirmed', 'sales_channel' => 'takeaway']);
        $first = $this->service->cancel($order, 'Offline void now', 'branch', 3, 'offline-once', 'pos_offline');
        $replay = $this->service->cancel($order->fresh(), 'Offline void now', 'branch', 3, 'offline-once', 'pos_offline');

        $this->assertTrue($first['success']);
        $this->assertFalse($first['duplicate']);
        $this->assertTrue($replay['success']);
        $this->assertTrue($replay['duplicate']);
        $this->assertSame(1, OrderCancellationAuditLog::query()->where('order_id', $order->id)->count());
    }

    public function test_short_reason_does_not_change_the_order(): void
    {
        $order = $this->persistOrder(['order_status' => 'confirmed', 'sales_channel' => 'takeaway']);
        $result = $this->service->cancel($order, 'no', 'branch', 3);

        $this->assertFalse($result['success']);
        $this->assertSame('reason', $result['code']);
        $this->assertSame('confirmed', $order->fresh()->order_status);
        $this->assertSame(0, OrderCancellationAuditLog::query()->where('order_id', $order->id)->count());
    }

    public function test_sms_is_addressed_only_to_the_admin_notification_number(): void
    {
        $this->assertNull(PosOrderCancelledSms::recipient());
        PosCancellationNotificationSettings::save('0712345678');
        $this->assertSame('0712345678', PosOrderCancelledSms::recipient());

        $sms = file_get_contents(app_path('CentralLogics/PosOrderCancelledSms.php'));
        $this->assertStringContainsString('PosCancellationNotificationSettings::phone()', $sms);
        $this->assertStringNotContainsString('resolveCustomerPhone', $sms);
        $this->assertStringNotContainsString('customer->phone', $sms);
        $this->assertStringNotContainsString('rider_phone', $sms);

        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $cancel = $this->methodBody($controller, 'cancelOrder');
        $this->assertStringContainsString('dispatchSms($fresh)', $cancel);
        $this->assertStringNotContainsString('CustomerOrderStatusSms', $cancel);
        $this->assertStringNotContainsString('PosDeliveryCustomerSms', $cancel);
    }

    public function test_sms_variables_match_the_admin_template(): void
    {
        $branch = new Branch();
        $branch->name = 'Westlands';
        $order = new Order();
        $order->id = 88;
        $order->readable_order_id = 'M-88';
        $order->order_amount = 649;
        $order->cancellation_reason = 'Out of stock';
        $order->setRelation('branch', $branch);

        $vars = PosOrderCancelledSms::buildVariables($order);
        $this->assertArrayHasKey('order_number', $vars);
        $this->assertSame('Westlands', $vars['branch_name']);
        $this->assertSame('Out of stock', $vars['cancellation_reason']);
        $this->assertMatchesRegularExpression('/649/', $vars['total_amount']);

        $def = SmsTemplateCatalog::definition(SmsTemplateCatalog::POS_ORDER_CANCELLED);
        $this->assertSame('POS Order Cancelled SMS', $def['label']);
        $this->assertStringContainsString('{order_number}', $def['default_message']);
        $this->assertStringContainsString('{cancellation_reason}', $def['default_message']);
    }

    public function test_notification_phone_is_required_only_when_the_template_is_enabled(): void
    {
        $this->assertNull(PosCancellationNotificationSettings::validationError('', false));
        $this->assertNotNull(PosCancellationNotificationSettings::validationError('', true));
        $this->assertSame('Invalid phone number', PosCancellationNotificationSettings::validationError('12', true));
        $this->assertNull(PosCancellationNotificationSettings::validationError('0712345678', true));
    }

    public function test_reports_still_include_cancelled_pos_orders_and_revenue_rules_are_unchanged(): void
    {
        $today = file_get_contents(app_path('Services/BranchPosTodayOrdersService.php'));
        $this->assertStringContainsString("whereIn('order_status', ['canceled', 'cancelled', 'failed', 'returned'])", $today);
        $this->assertStringContainsString("'cancellable'", $today);
        $this->assertStringContainsString('cancellation_reason', $today);

        $report = file_get_contents(app_path('Http/Controllers/Admin/ReportController.php'));
        $this->assertStringContainsString("where(['order_status' => 'delivered'])", $report);

        $orderModel = file_get_contents(app_path('Model/Order.php'));
        $this->assertStringContainsString("whereIn('order_status', ['delivered', 'completed'])", $orderModel);
        $this->assertStringContainsString('cancellationAuditLogs', $orderModel);
    }

    public function test_receipts_remain_accessible_and_kitchen_tickets_are_blocked(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $print = $this->methodBody($controller, 'markTicketPrinted');
        $invoice = $this->methodBody($controller, 'generateInvoice');
        $this->assertStringContainsString("ticket === 'kitchen' && PosOrderCancellationService::isCancelledStatus", $print);
        $this->assertStringNotContainsString('order_status', $invoice);

        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $this->assertStringContainsString('data-cancel-order', $js);
        $page = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));
        $this->assertStringContainsString('pos-cancel-modal', $page);
        $ordersBlock = substr($page, strpos($page, 'id="pos-orders-modal"'), strpos($page, 'id="pos-success-modal"') - strpos($page, 'id="pos-orders-modal"'));
        $this->assertStringContainsString('id="pos-cancel-modal"', $ordersBlock);
        $this->assertStringContainsString('munch-pos-orders__nested', $ordersBlock);
        $this->assertStringContainsString('orderAllowsCancel(order)', $js);
        $this->assertStringContainsString('!isMarketplaceChannel(order.sales_channel)', $js);
        $this->assertStringContainsString('syncPosOverlayState', $js);
        $this->assertStringContainsString('is-nested-open', $js);
        $this->assertStringContainsString('munch-pos-overlay-open', $js);
        $this->assertStringContainsString('trapCancelFocus', $js);
        $css = file_get_contents(public_path('assets/admin/css/munch-pos.css'));
        $this->assertStringContainsString('.munch-pos-orders__nested', $css);
        $this->assertStringContainsString('.munch-pos-orders.is-nested-open', $css);
        $nestedCss = substr($css, strpos($css, '.munch-pos-orders__nested'), 280);
        $this->assertStringNotContainsString('z-index', $nestedCss);
        $this->assertStringContainsString('isCancelledOrder(order)', $js);
        $this->assertStringContainsString("kind === 'kitchen' && (job.kitchenPrinted || isCancelledJob(job))", $js);
        $this->assertStringContainsString("payload.action === 'cancel' ? postCancel(payload) : postOrder(payload)", $js);
        $this->assertStringContainsString('if (cancelUi.submitting) return', $js);
        $this->assertStringContainsString('munch-pos-place__spin', $js);
    }

    public function test_online_orders_and_other_pos_flows_are_untouched(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $this->assertStringContainsString('function placeOrder', $js);
        $this->assertStringContainsString("state.cart.orderType === 'delivery'", $js);
        $this->assertStringContainsString('function printOneTicket', $js);
        $this->assertStringContainsString('CFG.urls.order', $js);
        $this->assertStringContainsString('CFG.urls.cancelOrder', $js);
    }

    private function makeOrder(array $attrs): Order
    {
        $order = new Order();
        $order->order_type = $attrs['order_type'] ?? 'pos';
        $order->sales_channel = $attrs['sales_channel'] ?? 'takeaway';
        $order->order_status = $attrs['order_status'] ?? 'confirmed';
        $order->payment_status = $attrs['payment_status'] ?? 'paid';

        return $order;
    }

    private function persistOrder(array $attrs): Order
    {
        $id = DB::table('orders')->insertGetId([
            'user_id' => 0,
            'order_amount' => $attrs['order_amount'] ?? 100,
            'payment_status' => $attrs['payment_status'] ?? 'paid',
            'order_status' => $attrs['order_status'] ?? 'confirmed',
            'payment_method' => $attrs['payment_method'] ?? 'cash',
            'order_type' => $attrs['order_type'] ?? 'pos',
            'sales_channel' => $attrs['sales_channel'] ?? 'takeaway',
            'branch_id' => 7,
            'readable_order_id' => 'M-'.random_int(1000, 9999),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::query()->findOrFail($id);
    }

    private function methodBody(string $source, string $name): string
    {
        $start = strpos($source, 'function '.$name);
        $this->assertNotFalse($start, $name.' missing');

        return substr($source, $start, 1800);
    }

    private function ensureSchema(): void
    {
        foreach (['order_cancellation_audit_logs', 'orders', 'business_settings', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });
        DB::table('branches')->insert(['id' => 7, 'name' => 'Westlands']);

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->float('order_amount')->default(0);
            $table->string('payment_status')->nullable();
            $table->string('order_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('order_type')->nullable();
            $table->string('sales_channel')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('readable_order_id')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancelled_by_type', 16)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->string('pos_cancel_client_uuid', 64)->nullable();
            $table->timestamp('pos_cancelled_sms_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_cancellation_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('actor_type', 16)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();
            $table->string('reason', 500);
            $table->string('source', 32)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
