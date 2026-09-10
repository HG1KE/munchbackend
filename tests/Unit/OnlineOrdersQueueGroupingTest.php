<?php

namespace Tests\Unit;

use App\CentralLogics\Helpers;
use App\Model\Order;
use App\Services\DashboardOrderOperationsService;
use App\Support\OrderDispatchedTime;
use App\Support\OrderPlacementTime;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OnlineOrdersQueueGroupingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'order_details',
            'order_areas',
            'delivery_charge_by_areas',
            'delivery_men',
            'guest_users',
            'orders',
            'branches',
            'users',
            'business_settings',
            'currencies',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->text('value')->nullable();
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('currency_code');
            $table->string('currency_symbol')->default('Ksh');
            $table->string('country')->nullable();
            $table->decimal('exchange_rate', 8, 2)->default(1);
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->timestamps();
        });

        Schema::create('guest_users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_men', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_charge_by_areas', function (Blueprint $table) {
            $table->id();
            $table->string('area_name')->nullable();
            $table->timestamps();
        });

        Schema::create('order_areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('area_id')->nullable();
            $table->timestamps();
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->text('product_details')->nullable();
            $table->integer('quantity')->default(1);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('readable_order_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->default(1);
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->string('order_status')->default('pending');
            $table->string('order_type')->default('delivery');
            $table->string('sales_channel')->nullable();
            $table->string('payment_status')->default('unpaid');
            $table->string('payment_method')->nullable();
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->decimal('delivery_charge', 24, 2)->default(0);
            $table->text('delivery_address')->nullable();
            $table->date('delivery_date')->nullable();
            $table->boolean('is_guest')->default(true);
            $table->boolean('checked')->default(false);
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
        });

        DB::table('business_settings')->insert([
            ['key' => 'currency', 'value' => 'KES'],
            ['key' => 'decimal_point_settings', 'value' => '0'],
            ['key' => 'currency_symbol_position', 'value' => 'left'],
        ]);
        DB::table('currencies')->insert([
            'currency_code' => 'KES',
            'currency_symbol' => 'Ksh',
        ]);
        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Westlands', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'CBD', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('delivery_men')->insert([
            'id' => 7,
            'f_name' => 'John',
            'l_name' => 'Rider',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Helpers::forgetBusinessSettingsRuntimeCache();
        OrderPlacementTime::resetColumnCache();
        OrderDispatchedTime::resetColumnCache();
    }

    public function test_pending_and_confirmed_appear_only_in_pending_queue(): void
    {
        $this->insertOrder(1, ['order_status' => 'pending']);
        $this->insertOrder(2, ['order_status' => 'confirmed']);

        $this->assertSame([1, 2], $this->service()->expressPendingQueue(null)->pluck('id')->all());
        $this->assertSame([], $this->service()->expressPackingQueue(null)->pluck('id')->all());
        $this->assertSame([], $this->service()->expressDispatchedQueue(null)->pluck('id')->all());
    }

    public function test_processing_orders_appear_only_in_packing_queue(): void
    {
        $this->insertOrder(3, ['order_status' => 'processing']);
        $this->insertOrder(4, ['order_status' => 'packing']);

        $this->assertSame([], $this->service()->expressPendingQueue(null)->pluck('id')->all());
        $this->assertSame([3, 4], $this->service()->expressPackingQueue(null)->pluck('id')->all());
        $this->assertSame([], $this->service()->expressDispatchedQueue(null)->pluck('id')->all());
    }

    public function test_out_for_delivery_orders_appear_only_in_dispatched_queue(): void
    {
        $this->insertOrder(5, [
            'order_status' => 'out_for_delivery',
            'dispatched_at' => '2026-09-09 10:30:00',
            'delivery_man_id' => 7,
        ]);

        $this->assertSame([], $this->service()->expressPendingQueue(null)->pluck('id')->all());
        $this->assertSame([], $this->service()->expressPackingQueue(null)->pluck('id')->all());
        $this->assertSame([5], $this->service()->expressDispatchedQueue(null)->pluck('id')->all());
    }

    public function test_pos_dine_in_and_future_scheduled_orders_are_excluded(): void
    {
        $this->insertOrder(6, ['order_type' => 'pos']);
        $this->insertOrder(7, ['order_type' => 'dine_in']);
        $this->insertOrder(8, ['delivery_date' => now()->addDay()->format('Y-m-d')]);
        $this->insertOrder(9, ['order_status' => 'pending']);
        $this->insertOrder(61, ['order_type' => 'pos', 'sales_channel' => 'delivery', 'order_status' => 'confirmed']);
        $this->insertOrder(62, ['order_type' => 'delivery', 'sales_channel' => 'delivery', 'order_status' => 'pending']);
        $this->insertOrder(63, ['order_type' => 'pos', 'sales_channel' => 'glovo', 'order_status' => 'delivered']);

        $this->assertSame([9], $this->service()->expressPendingQueue(null)->pluck('id')->all());
        $this->assertSame(1, $this->service()->dashboardCounts(null)['online']);
        $this->assertSame(1, $this->service()->pendingOrderAlertPayload(null)['new_order']);
    }

    public function test_pending_order_alert_matches_pending_queue_and_ignores_other_statuses(): void
    {
        $this->insertOrder(20, ['order_status' => 'pending', 'checked' => 0]);
        $this->insertOrder(21, ['order_status' => 'confirmed', 'checked' => 0]);
        $this->insertOrder(22, ['order_status' => 'processing', 'checked' => 0]);
        $this->insertOrder(23, ['order_status' => 'out_for_delivery', 'checked' => 0]);
        $this->insertOrder(24, ['order_status' => 'delivered', 'checked' => 0]);
        $this->insertOrder(25, ['order_status' => 'canceled', 'checked' => 0]);
        $this->insertOrder(26, ['order_status' => 'failed', 'checked' => 0]);
        $this->insertOrder(27, ['order_status' => 'returned', 'checked' => 0]);
        $this->insertOrder(28, ['order_type' => 'pos', 'checked' => 0]);
        $this->insertOrder(29, ['order_type' => 'dine_in', 'checked' => 0]);
        $this->insertOrder(30, ['delivery_date' => now()->addDay()->format('Y-m-d'), 'checked' => 0]);

        $payload = $this->service()->pendingOrderAlertPayload(null);

        $this->assertSame(2, $payload['new_order']);
        $this->assertSame(
            $this->service()->expressPendingQueue(null)->pluck('id')->all(),
            [20, 21]
        );

        $this->insertOrder(31, ['order_status' => 'pending', 'checked' => 1]);
        $this->assertSame(3, $this->service()->pendingOrderAlertPayload(null)['new_order']);
        $this->assertSame(3, $this->service()->expressPendingQueue(null)->count());
    }

    public function test_delivered_orders_are_not_in_the_live_count(): void
    {
        $this->insertOrder(10, ['order_status' => 'pending']);
        $this->insertOrder(11, ['order_status' => 'delivered']);

        $this->assertSame(1, $this->service()->onlineActiveCount(null));
    }

    public function test_cards_include_customer_branch_items_address_payment_and_rider(): void
    {
        $this->insertOrder(12, [
            'order_status' => 'processing',
            'delivery_man_id' => 7,
            'payment_method' => 'cash_on_delivery',
            'order_amount' => 500,
            'delivery_charge' => 100,
        ]);
        DB::table('order_details')->insert([
            'order_id' => 12,
            'product_id' => 1,
            'product_details' => json_encode(['name' => 'Beef Burger']),
            'quantity' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $card = $this->service()->mapExpressOrderCards(
            $this->service()->expressPackingQueue(null),
            'packing'
        )->first();

        $this->assertNotNull($card);
        $this->assertSame('Customer 12', $card['customer_name']);
        $this->assertSame('Westlands', $card['branch_name']);
        $this->assertSame('Area 12', $card['area']);
        $this->assertSame(['Beef Burger ×2'], $card['item_lines']);
        $this->assertSame('John Rider', $card['rider_name']);
        $this->assertSame('Payment On Delivery', $card['payment_method_label']);
        $this->assertFalse($card['timer_frozen']);
        $this->assertSame('A10012', $card['order_display_id']);
        $this->assertSame('DELIVERY', $card['fulfillment_label']);
    }

    public function test_takeaway_cards_show_pickup_badge_not_online_orders(): void
    {
        $this->insertOrder(13, [
            'order_status' => 'pending',
            'order_type' => 'take_away',
        ]);

        $card = $this->service()->mapExpressOrderCards(
            $this->service()->expressPendingQueue(null),
            'pending'
        )->first();

        $this->assertSame('PICKUP', $card['fulfillment_label']);
        $this->assertNotSame('Online Orders', $card['fulfillment_label']);
    }

    public function test_online_orders_ui_uses_meatco_classes_and_munch_name(): void
    {
        $dashboard = file_get_contents(resource_path('views/partials/_dashboard-online-orders.blade.php'));
        $this->assertStringContainsString("translate('Online Orders')", $dashboard);
        $this->assertStringContainsString("translate('POS')", $dashboard);
        $this->assertStringContainsString('posRoute', $dashboard);
        $this->assertStringContainsString('meatco-ops-tile--express', $dashboard);
        $this->assertStringContainsString('data-live-card="online"', $dashboard);
        $this->assertStringNotContainsString('Express Orders', $dashboard);

        $sections = file_get_contents(resource_path('views/partials/order-operations/_online-sections.blade.php'));
        $this->assertStringContainsString("translate('Pending')", $sections);
        $this->assertStringContainsString("translate('Preparing')", $sections);
        $this->assertStringContainsString("translate('Dispatched')", $sections);
        $this->assertStringNotContainsString("translate('Packing')", $sections);
        $this->assertStringContainsString("section' => 'packing'", $sections);

        $grid = file_get_contents(resource_path('views/partials/order-operations/_online-grid.blade.php'));
        $this->assertStringContainsString('data-meatco-order-timer', $grid);
        $this->assertStringContainsString('data-placed-at', $grid);
        $this->assertStringContainsString("translate('DELIVERY')", $grid);
        $this->assertStringNotContainsString("translate('Online Orders')", $grid);

        $js = file_get_contents(public_path('assets/admin/js/meatco-order-operations.js'));
        $this->assertStringContainsString('var SLA_DELAY_SEC = 30 * 60', $js);
        $this->assertStringContainsString('var SLA_ESCALATED_SEC = 45 * 60', $js);
        $this->assertStringContainsString('var SLA_CRITICAL_SEC = 60 * 60', $js);

        $workflow = file_get_contents(resource_path('views/admin-views/order/partials/_workflow-actions.blade.php'));
        $this->assertStringContainsString('Begin Preparing', $workflow);
        $this->assertStringContainsString('Cancel Order', $workflow);
        $this->assertStringContainsString("order_status' => 'canceled'", $workflow);
        $this->assertStringContainsString("order_status' => 'processing'", $workflow);
        $this->assertStringNotContainsString('<select', $workflow);

        $placeOrder = file_get_contents(app_path('Http/Controllers/Api/V1/OrderController.php'));
        $this->assertStringContainsString('OrderPlacementTime::insertAttributes()', $placeOrder);

        $adminRoutes = file_get_contents(base_path('routes/admin.php'));
        $this->assertStringContainsString('OrderOperationsController', $adminRoutes);
        $this->assertStringContainsString("->name('online')", $adminRoutes);
        $this->assertStringContainsString('DashboardLiveCardsController', $adminRoutes);

        $branchRoutes = file_get_contents(base_path('routes/branch.php'));
        $this->assertStringContainsString('OrderOperationsController', $branchRoutes);
        $this->assertStringContainsString("->name('online')", $branchRoutes);
        $this->assertStringContainsString('DashboardLiveCardsController', $branchRoutes);
        $this->assertStringContainsString("->name('today-orders')", $branchRoutes);
        $this->assertStringContainsString("->name('print-ticket')", $branchRoutes);
        $this->assertStringContainsString("->name('catalog')", $branchRoutes);
        $this->assertStringContainsString("->name('heartbeat')", $branchRoutes);

        $posPage = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));
        $this->assertStringContainsString('pos-view-orders', $posPage);
        $this->assertStringContainsString('pos-success-modal', $posPage);
        $this->assertStringContainsString('pos-success-kitchen', $posPage);
        $this->assertStringContainsString('pos-success-receipt', $posPage);
        $this->assertStringContainsString("translate('Print Kitchen Order')", $posPage);
        $this->assertStringContainsString("translate('Print Receipt')", $posPage);
        $this->assertStringNotContainsString('pos-dine-in', $posPage);
        $this->assertStringNotContainsString('pos-table', $posPage);
        $this->assertStringNotContainsString('pos-people', $posPage);
        $this->assertStringContainsString('pos-discount-wrap', $posPage);
        $this->assertStringContainsString('pos-print-frame', $posPage);
        $this->assertStringContainsString('openSuccessModal(snapshotPrintJob(body))', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('printOneTicket', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('allowsDiscount', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('orderTypeBannerHtml', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('kitchenTicketHtml', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('receiptTicketHtml', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $posJs = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $this->assertStringContainsString("L('mpesaTill', 'M-PESA Till')", $posJs);
        $this->assertStringContainsString('job.mpesa_till', $posJs);
        $this->assertStringContainsString('posMpesaEnabled', $posJs);
        $receiptFn = substr($posJs, strpos($posJs, 'function receiptTicketHtml'), strpos($posJs, 'function printTicket') - strpos($posJs, 'function receiptTicketHtml'));
        $kitchenFn = substr($posJs, strpos($posJs, 'function kitchenTicketHtml'), strpos($posJs, 'function receiptTicketHtml') - strpos($posJs, 'function kitchenTicketHtml'));
        $this->assertStringContainsString('mpesa_till', $receiptFn);
        $this->assertStringNotContainsString('mpesa_till', $kitchenFn);
        $this->assertStringContainsString('Enable M-PESA Payments on POS', file_get_contents(resource_path('views/admin-views/branch/edit.blade.php')));
        $this->assertStringContainsString('pos_mpesa_enabled', file_get_contents(resource_path('views/admin-views/branch/edit.blade.php')));
        $this->assertStringContainsString("munch-pos-app.js') }}?v=2.5", file_get_contents(resource_path('views/branch-views/pos/index.blade.php')));
        $this->assertStringContainsString('munch-pos-submit-guard.js', file_get_contents(resource_path('views/branch-views/pos/index.blade.php')));
        $this->assertStringContainsString('isMarketplaceOrderType', $posJs);
        $this->assertStringContainsString('els.pay.hidden = true', $posJs);
        $this->assertStringContainsString("return ['glovo']", $posJs);
        $this->assertStringContainsString("L('glovo', 'Glovo')", $posJs);
        $this->assertStringContainsString('pos-success-done', file_get_contents(resource_path('views/branch-views/pos/index.blade.php')));
        $this->assertStringContainsString('150', $posJs);
        $this->assertStringNotContainsString('PAID VIA GLOVO', $kitchenFn);
        $this->assertStringNotContainsString('money(item.line_total)', $kitchenFn);
        $this->assertStringContainsString('data-print-kitchen', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('data-print-receipt', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('markTicketPrinted', file_get_contents(app_path('Http/Controllers/Branch/POSController.php')));
        $this->assertStringContainsString('allowsManualDiscount', file_get_contents(app_path('Http/Controllers/Branch/POSController.php')));
        $this->assertStringContainsString('kitchen_printed_at', file_get_contents(app_path('Services/BranchPosTodayOrdersService.php')));
        $this->assertStringContainsString('L(\'queuedSaved\', \'Order saved offline\')', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringNotContainsString("clearCart();\n                toast(CFG.labels.placed);", file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('@media print', file_get_contents(public_path('assets/admin/css/munch-pos.css')));
        $this->assertStringContainsString('80mm', file_get_contents(public_path('assets/admin/css/munch-pos.css')));
        $this->assertStringContainsString('z-index: 46', file_get_contents(public_path('assets/admin/css/munch-pos.css')));
        $this->assertStringContainsString('variationOptionLabels', file_get_contents(app_path('Services/BranchPosTodayOrdersService.php')));
        $this->assertStringContainsString("'options' => self::variationOptionLabels", file_get_contents(app_path('Services/BranchPosTodayOrdersService.php')));
        $this->assertStringContainsString('pos-orders-modal', $posPage);
        $this->assertStringContainsString('todayOrders:', $posPage);
        $this->assertStringContainsString("translate('View Orders')", $posPage);
        $this->assertStringContainsString("translate('Cart empty')", $posPage);
        $this->assertStringContainsString('pos-del-fee', $posPage);
        $this->assertStringNotContainsString('pos-del-area', $posPage);
        $this->assertStringContainsString('delivery_charge: deliveryCharge()', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringNotContainsString('selected_area_id', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('resolvePosDeliveryCharge', file_get_contents(app_path('Http/Controllers/Branch/POSController.php')));
        $this->assertStringNotContainsString('customer_id', $posPage);
        $this->assertStringNotContainsString('Select Customer', $posPage);
        $this->assertStringContainsString('[hidden]', file_get_contents(public_path('assets/admin/css/munch-pos.css')));
        $this->assertStringContainsString('munch-pos-shell-v7', file_get_contents(public_path('assets/admin/js/munch-pos-sw.js')));
        $this->assertStringContainsString('indexedDB', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('catalog_version', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('fetchTodayOrders', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('15000', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('els.ordersList.scrollTop', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('whereIn(\'sales_channel\', PosOrderTypes::salesChannels())', file_get_contents(app_path('Services/BranchPosTodayOrdersService.php')));
        $this->assertStringContainsString('PER_PAGE = 50', file_get_contents(app_path('Services/BranchPosTodayOrdersService.php')));
        $this->assertStringNotContainsString('scopePos', file_get_contents(app_path('Services/BranchPosTodayOrdersService.php')));
        $this->assertStringContainsString('z-index: 45', file_get_contents(public_path('assets/admin/css/munch-pos.css')));
        $this->assertStringContainsString('munch-pos-card__plus', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('updateProductCard', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('refreshCartUi', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('data-card-delta', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringNotContainsString('munch-pos-card__add', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringNotContainsString('munch-pos-card__add', file_get_contents(public_path('assets/admin/css/munch-pos.css')));
        $this->assertStringContainsString('productNeedsVariation', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('productInCategory', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('pos-tabs-prev', file_get_contents(resource_path('views/branch-views/pos/index.blade.php')));
        $this->assertStringContainsString('scrollBy', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringContainsString('COALESCE(pos_sold.qty_sold, 0) DESC', file_get_contents(app_path('Services/BranchPosCatalogService.php')));
        $this->assertStringContainsString('whereIn(\'orders.sales_channel\', PosOrderTypes::salesChannels())', file_get_contents(app_path('Services/BranchPosCatalogService.php')));
        $this->assertStringNotContainsString("(int) (\$row['position'] ?? 0) === 0", file_get_contents(app_path('Services/BranchPosCatalogService.php')));
        $this->assertStringNotContainsString('data-addon', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertStringNotContainsString('addon_ids', file_get_contents(app_path('Services/BranchPosCatalogService.php')));
        $this->assertStringNotContainsString('AddOn::', file_get_contents(app_path('Services/BranchPosCatalogService.php')));
        $this->assertStringContainsString('pos-catalog-popularity-1', file_get_contents(app_path('Services/BranchPosCatalogService.php')));
        $this->assertStringContainsString('pos-mpesa-settings-1', file_get_contents(app_path('Services/BranchPosCatalogService.php')));
        $this->assertStringContainsString("'pos_mpesa_enabled'", file_get_contents(app_path('Services/BranchPosCatalogService.php')));
        $this->assertStringContainsString("'mpesa_till'", file_get_contents(app_path('Services/BranchPosCatalogService.php')));
        $this->assertStringContainsString('client_uuid', file_get_contents(app_path('Http/Controllers/Branch/POSController.php')));
        $this->assertStringNotContainsString("order_note = PosOrderTypes", file_get_contents(app_path('Http/Controllers/Branch/POSController.php')));
        $this->assertStringNotContainsString('pos:\'.$clientUuid', file_get_contents(app_path('Http/Controllers/Branch/POSController.php')));
    }

    public function test_pos_kitchen_variation_labels_parse_stored_and_cart_shapes(): void
    {
        $this->assertSame(['BBQ', 'Extra Cheese'], \App\Services\BranchPosTodayOrdersService::variationOptionLabels([
            ['name' => 'Sauce', 'values' => [['label' => 'BBQ', 'optionPrice' => 0]]],
            ['name' => 'Extras', 'values' => ['label' => ['Extra Cheese']]],
        ]));
        $this->assertSame(['BBQ'], \App\Services\BranchPosTodayOrdersService::variationOptionLabels(
            json_encode([['name' => 'Sauce', 'values' => [['label' => 'BBQ']]]])
        ));
        $this->assertSame([], \App\Services\BranchPosTodayOrdersService::variationOptionLabels(null));
    }

    private function service(): DashboardOrderOperationsService
    {
        return app(DashboardOrderOperationsService::class);
    }

    private function insertOrder(int $id, array $overrides = []): void
    {
        $createdAt = $overrides['created_at'] ?? '2026-09-09 10:00:00';

        DB::table('orders')->insert(array_merge([
            'id' => $id,
            'readable_order_id' => 'A'.(10000 + $id),
            'branch_id' => 1,
            'order_status' => 'pending',
            'order_type' => 'delivery',
            'payment_status' => 'unpaid',
            'payment_method' => 'cash_on_delivery',
            'order_amount' => 1000,
            'delivery_charge' => 0,
            'delivery_address' => json_encode([
                'contact_person_name' => 'Customer '.$id,
                'address' => 'Area '.$id,
            ]),
            'delivery_date' => now()->format('Y-m-d'),
            'is_guest' => 1,
            'checked' => 0,
            'placed_at' => $createdAt,
            'dispatched_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], $overrides));
    }
}
