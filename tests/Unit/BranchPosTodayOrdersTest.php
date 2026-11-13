<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Services\BranchPosTodayOrdersService;
use App\Support\PosOrderTypes;
use App\Support\TimezoneDisplay;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchPosTodayOrdersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TimezoneDisplay::resetCache();
        $this->ensureSchema();
    }

    public function test_all_filter_does_not_narrow_by_channel(): void
    {
        $source = file_get_contents(app_path('Services/BranchPosTodayOrdersService.php'));
        $apply = $this->methodBody($source, 'applyFilter');

        $this->assertStringContainsString("\$filter === '' || \$filter === 'all'", $apply);
        $this->assertStringContainsString('return;', $apply);
        $this->assertStringContainsString("in_array(\$filter, PosOrderTypes::salesChannels(), true)", $apply);
        $this->assertStringContainsString('customer_delivery_address', $source);
        $this->assertStringContainsString('function addressField', $source);
        $this->assertStringNotContainsString('$address?->contact_person_name', $source);
    }

    public function test_delivery_json_address_does_not_crash_serialize(): void
    {
        $order = $this->makeOrder([
            'id' => 501,
            'sales_channel' => 'delivery',
            'order_type' => 'pos',
            'delivery_address' => json_encode([
                'contact_person_name' => 'Amina Delivery',
                'contact_person_number' => '0712345678',
                'address' => 'Nyali Road',
            ]),
        ]);

        $payload = (new BranchPosTodayOrdersService())->serializeOrder($order, 'Nyali');

        $this->assertSame('Amina Delivery', $payload['customer']);
        $this->assertSame('0712345678', $payload['phone']);
        $this->assertSame('Nyali Road', $payload['address']);
        $this->assertSame('delivery', $payload['sales_channel']);
        $this->assertSame('Delivery', $payload['sales_channel_label']);
    }

    public function test_view_orders_date_uses_ordinal_full_month_in_business_timezone(): void
    {
        $order = $this->makeOrder([
            'id' => 512,
            'created_at' => '2026-11-12 09:30:00',
            'updated_at' => '2026-11-12 09:30:00',
        ]);

        $payload = (new BranchPosTodayOrdersService())->serializeOrder($order, 'Nyali');
        $local = Carbon::parse('2026-11-12 09:30:00', 'UTC')->timezone(TimezoneDisplay::businessTimezone());

        $this->assertSame($local->format('jS F Y'), $payload['date']);
        $this->assertSame($local->format('H:i'), $payload['time']);
        $this->assertSame($local->format('jS F Y H:i'), $payload['created_at']);
        $this->assertMatchesRegularExpression('/^\d{1,2}(st|nd|rd|th) [A-Za-z]+ \d{4}$/', $payload['date']);

        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $this->assertStringContainsString('function orderListWhenLabel(order)', $js);
        $this->assertStringContainsString('escapeHtml(orderListWhenLabel(order))', $js);
        $this->assertStringNotContainsString("escapeHtml(order.time) + '</span><span>' + channelBadgeHtml", $js);
    }

    public function test_all_tab_includes_every_pos_channel_and_excludes_website_orders(): void
    {
        $now = Carbon::now('UTC');
        $channels = [
            'delivery' => ['order_type' => 'pos', 'address' => ['contact_person_name' => 'Del', 'contact_person_number' => '0700000001', 'address' => 'Link Rd']],
            'takeaway' => ['order_type' => 'pos'],
            'dine_in' => ['order_type' => 'dine_in'],
            'glovo' => ['order_type' => 'pos'],
            'uber' => ['order_type' => 'pos'],
            'bolt_food' => ['order_type' => 'pos'],
        ];

        $id = 10;
        foreach ($channels as $channel => $meta) {
            $this->insertOrder($id++, [
                'sales_channel' => $channel,
                'order_type' => $meta['order_type'],
                'delivery_address' => isset($meta['address']) ? json_encode($meta['address']) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->insertOrder(99, [
            'sales_channel' => null,
            'order_type' => 'delivery',
            'delivery_address' => json_encode(['contact_person_name' => 'Website', 'address' => 'App']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = new BranchPosTodayOrdersService();
        $all = $service->forBranch(1, 'Nyali', null, 'all', 1);

        $this->assertSame(6, $all['total']);
        $this->assertSame(
            ['bolt_food', 'uber', 'glovo', 'dine_in', 'takeaway', 'delivery'],
            array_column($all['orders'], 'sales_channel')
        );
        $this->assertNotContains('Website', array_column($all['orders'], 'customer'));

        $delivery = $service->forBranch(1, 'Nyali', null, 'delivery', 1);
        $this->assertSame(1, $delivery['total']);
        $this->assertSame('Del', $delivery['orders'][0]['customer']);
        $this->assertSame('Link Rd', $delivery['orders'][0]['address']);

        foreach (['takeaway', 'dine_in', 'glovo', 'uber', 'bolt_food'] as $channel) {
            $page = $service->forBranch(1, 'Nyali', null, $channel, 1);
            $this->assertSame(1, $page['total'], $channel);
            $this->assertSame($channel, $page['orders'][0]['sales_channel']);
        }
    }

    public function test_empty_view_orders_stays_on_today_and_search_finds_historical_pos(): void
    {
        $now = Carbon::now('UTC');
        $yesterday = $now->copy()->subDay();

        $this->insertOrder(201, [
            'readable_order_id' => 'A10651',
            'sales_channel' => 'takeaway',
            'order_type' => 'pos',
            'created_at' => $yesterday,
            'updated_at' => $yesterday,
        ]);
        $this->insertOrder(202, [
            'readable_order_id' => 'A10680',
            'sales_channel' => 'takeaway',
            'order_type' => 'pos',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->insertOrder(203, [
            'readable_order_id' => 'A10651',
            'branch_id' => 2,
            'sales_channel' => 'takeaway',
            'order_type' => 'pos',
            'created_at' => $yesterday,
            'updated_at' => $yesterday,
        ]);
        $this->insertOrder(204, [
            'readable_order_id' => 'A10690',
            'sales_channel' => null,
            'order_type' => 'delivery',
            'created_at' => $yesterday,
            'updated_at' => $yesterday,
        ]);

        $service = new BranchPosTodayOrdersService();
        $today = $service->forBranch(1, 'Nyali', null, 'all', 1);
        $this->assertSame(1, $today['total']);
        $this->assertSame(202, $today['orders'][0]['id']);
        $this->assertSame('A10680', $today['orders'][0]['number']);

        $search = $service->forBranch(1, 'Nyali', 'A10651', 'all', 1);
        $this->assertSame(1, $search['total']);
        $this->assertSame(201, $search['orders'][0]['id']);
        $this->assertSame('A10651', $search['orders'][0]['number']);

        $website = $service->forBranch(1, 'Nyali', 'A10690', 'all', 1);
        $this->assertSame(0, $website['total']);
    }

    public function test_sales_channels_cover_the_all_tab_contract(): void
    {
        $this->assertSame(
            ['pos', 'delivery', 'takeaway', 'dine_in', 'glovo', 'uber', 'bolt_food'],
            PosOrderTypes::salesChannels()
        );
        $this->assertFalse(PosOrderTypes::isOnlineOrder('pos', 'delivery'));
        $this->assertTrue(PosOrderTypes::isOnlineOrder('delivery', null));
    }

    public function test_online_orders_filter_lists_originated_and_pending_with_value_total(): void
    {
        $now = Carbon::now('UTC');
        $service = new BranchPosTodayOrdersService();

        // Accepted-into-POS online sale (uuid survives ACCEPT).
        $this->insertOrder(301, [
            'sales_channel' => 'delivery',
            'order_type' => 'pos',
            'order_amount' => 1000,
            'delivery_charge' => 180,
            'online_checkout_uuid' => 'online-accepted-1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // Still-pending website order (no POS channel).
        $this->insertOrder(302, [
            'sales_channel' => null,
            'order_type' => 'delivery',
            'order_amount' => 500,
            'delivery_charge' => 100,
            'online_checkout_uuid' => 'online-pending-1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // Pure cashier POS takeaway — must stay out of Online Orders.
        $this->insertOrder(303, [
            'sales_channel' => 'takeaway',
            'order_type' => 'pos',
            'order_amount' => 999,
            'online_checkout_uuid' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // Pending takeaway without uuid still counts as online family.
        // Pickup zeros delivery_charge in OrderPayableAmount even if stored.
        $this->insertOrder(304, [
            'sales_channel' => null,
            'order_type' => 'take_away',
            'order_amount' => 250,
            'delivery_charge' => 50,
            'online_checkout_uuid' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        // Canceled online order stays in the list but is excluded from value.
        $this->insertOrder(305, [
            'sales_channel' => null,
            'order_type' => 'delivery',
            'order_amount' => 400,
            'delivery_charge' => 80,
            'order_status' => 'canceled',
            'online_checkout_uuid' => 'online-canceled-1',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $online = $service->forBranch(1, 'Nyali', null, 'online', 1);
        $this->assertSame(4, $online['total']);
        // Payable: 1000+180 + 500+100 + 250+0 (pickup) = 2030; canceled excluded.
        $this->assertSame(2030.0, $online['orders_value']);
        $this->assertSame([305, 304, 302, 301], array_column($online['orders'], 'id'));
        $byId = collect($online['orders'])->keyBy('id');
        $this->assertSame(1180.0, $byId[301]['grand_total']);
        $this->assertSame(600.0, $byId[302]['grand_total']);
        $this->assertSame(250.0, $byId[304]['grand_total']);
        $this->assertSame(180.0, $byId[301]['delivery_fee']);
        $this->assertSame(0.0, $byId[304]['delivery_fee']);

        $all = $service->forBranch(1, 'Nyali', null, 'all', 1);
        $this->assertSame(2, $all['total']);
        $this->assertNull($all['orders_value']);
        $this->assertContains(301, array_column($all['orders'], 'id'));
        $this->assertContains(303, array_column($all['orders'], 'id'));
        $this->assertNotContains(302, array_column($all['orders'], 'id'));

        $delivery = $service->forBranch(1, 'Nyali', null, 'delivery', 1);
        $this->assertNull($delivery['orders_value']);

        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $this->assertStringContainsString("{ id: 'online', label: L('onlineOrders', 'Online Orders') }", $js);
        $this->assertStringContainsString("{ id: 'dine_in', label: L('dineIn', 'Dine In') }", $js);
        $this->assertTrue(
            strpos($js, "{ id: 'dine_in', label: L('dineIn', 'Dine In') }")
                < strpos($js, "{ id: 'online', label: L('onlineOrders', 'Online Orders') }")
        );
        $this->assertStringNotContainsString("{ id: 'active', label: L('active', 'Active') }", $js);
        $this->assertStringContainsString("ordersUi.filter === 'online'", $js);
        $this->assertStringContainsString("getElementById('pos-orders-value')", $js);
        $this->assertStringContainsString('els.ordersValue', $js);
        $this->assertStringContainsString("L('totalPaidOnline', 'Total Paid Online')", $js);
        $this->assertStringNotContainsString('is-orders-value', $js);
        $blade = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));
        $this->assertStringContainsString('id="pos-orders-value"', $blade);
        $this->assertStringContainsString("totalPaidOnline: @json(translate('Total Paid Online'))", $blade);
        $this->assertTrue(
            strpos($blade, 'id="pos-orders-filters"') < strpos($blade, 'id="pos-orders-value"')
            && strpos($blade, 'id="pos-orders-value"') < strpos($blade, 'id="pos-orders-list"')
        );
        $css = file_get_contents(public_path('assets/admin/css/munch-pos.css'));
        $this->assertMatchesRegularExpression('/\.munch-pos-orders__value\s*\{[^}]*font-size:\s*1\.35rem/s', $css);
        $this->assertTrue($service->isOnlineOrdersFilter('online'));
        $this->assertTrue($service->isOnlineOrdersFilter('online_orders'));
        $this->assertFalse($service->isOnlineOrdersFilter('delivery'));
    }

    public function test_online_orders_includes_evening_pos_accepted_via_placed_at(): void
    {
        // Prod stores placed_at in UTC and created_at in APP_TIMEZONE (Nairobi).
        // A24725-style: 21:50 Nairobi → placed_at 18:50 UTC / created_at 21:50 local.
        // The old UTC-only created_at day window excluded anything after 21:00 Nairobi.
        $tz = TimezoneDisplay::businessTimezone();
        Carbon::setTestNow(Carbon::parse('2026-11-12 22:30:00', $tz));

        $this->insertOrder(401, [
            'readable_order_id' => 'A24725',
            'sales_channel' => 'delivery',
            'order_type' => 'pos',
            'order_amount' => 1040,
            'delivery_charge' => 180,
            'online_checkout_uuid' => 'd110f399-9afd-400a-ad2d-80b6bfa59655',
            'placed_at' => '2026-11-12 18:50:47',
            'created_at' => '2026-11-12 21:50:47',
            'updated_at' => '2026-11-12 22:03:44',
        ]);
        $this->insertOrder(402, [
            'readable_order_id' => 'A24730',
            'sales_channel' => 'takeaway',
            'order_type' => 'pos',
            'order_amount' => 1580,
            'online_checkout_uuid' => null,
            'placed_at' => '2026-11-12 19:16:40',
            'created_at' => '2026-11-12 22:16:41',
            'updated_at' => '2026-11-12 22:16:41',
        ]);

        $service = new BranchPosTodayOrdersService();
        $online = $service->forBranch(1, 'Bamburi', null, 'online', 1);
        $this->assertSame(1, $online['total']);
        // Payable includes delivery (1040 + 180), same class as A24807.
        $this->assertSame(1220.0, $online['orders_value']);
        $this->assertSame(401, $online['orders'][0]['id']);
        $this->assertSame('A24725', $online['orders'][0]['number']);
        $this->assertSame(1220.0, $online['orders'][0]['grand_total']);
        $this->assertSame(180.0, $online['orders'][0]['delivery_fee']);

        $all = $service->forBranch(1, 'Bamburi', null, 'all', 1);
        $this->assertSame(2, $all['total']);
        $this->assertContains(401, array_column($all['orders'], 'id'));
        $this->assertContains(402, array_column($all['orders'], 'id'));

        Carbon::setTestNow();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeOrder(array $attrs): Order
    {
        $order = new Order();
        $order->setRawAttributes(array_merge([
            'id' => 1,
            'branch_id' => 1,
            'order_amount' => 100,
            'delivery_charge' => 0,
            'extra_discount' => 0,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'order_status' => 'confirmed',
            'order_type' => 'pos',
            'sales_channel' => 'takeaway',
            'order_note' => null,
            'rider_name' => null,
            'rider_phone' => null,
            'kitchen_printed_at' => null,
            'receipt_printed_at' => null,
            'created_at' => now()->utc()->toDateTimeString(),
            'updated_at' => now()->utc()->toDateTimeString(),
            'delivery_address' => null,
        ], $attrs), true);
        $order->exists = true;
        $order->setRelation('details', collect());
        $order->setRelation('customer', null);
        $order->setRelation('customer_delivery_address', null);
        $order->setRelation('order_change_amount', null);
        $order->setRelation('branch', null);
        $order->setRelation('cancelledByBranch', null);
        $order->setRelation('cancelledByAdmin', null);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertOrder(int $id, array $overrides = []): void
    {
        DB::table('orders')->insert(array_merge([
            'id' => $id,
            'readable_order_id' => 'M-'.$id,
            'branch_id' => 1,
            'order_amount' => 500,
            'delivery_charge' => 0,
            'extra_discount' => 0,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'order_status' => 'confirmed',
            'order_type' => 'pos',
            'sales_channel' => 'takeaway',
            'online_checkout_uuid' => null,
            'delivery_address' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function methodBody(string $source, string $name): string
    {
        $start = strpos($source, 'function '.$name);
        $this->assertNotFalse($start, $name.' missing');

        return substr($source, $start, 900);
    }

    private function ensureSchema(): void
    {
        foreach ([
            'order_details', 'order_change_amounts', 'customer_addresses', 'users', 'admins', 'branches', 'orders',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('mpesa_till')->nullable();
            $table->timestamps();
        });
        DB::table('branches')->insert(['id' => 1, 'name' => 'Nyali']);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
        });

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->string('contact_person_name')->nullable();
            $table->string('contact_person_number')->nullable();
            $table->string('address')->nullable();
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->text('product_details')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 24, 2)->default(0);
            $table->decimal('discount_on_product', 24, 2)->default(0);
            $table->text('variation')->nullable();
            $table->text('add_on_prices')->nullable();
            $table->text('add_on_qtys')->nullable();
        });

        Schema::create('order_change_amounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->decimal('paid_amount', 24, 2)->default(0);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('readable_order_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->default(1);
            $table->unsignedBigInteger('delivery_address_id')->nullable();
            $table->string('order_status')->nullable();
            $table->string('order_type')->nullable();
            $table->string('sales_channel')->nullable();
            $table->string('online_checkout_uuid', 64)->nullable();
            $table->string('payment_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->decimal('delivery_charge', 24, 2)->default(0);
            $table->decimal('extra_discount', 24, 2)->default(0);
            $table->text('delivery_address')->nullable();
            $table->string('order_note')->nullable();
            $table->string('rider_name')->nullable();
            $table->string('rider_phone')->nullable();
            $table->timestamp('kitchen_printed_at')->nullable();
            $table->timestamp('receipt_printed_at')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancelled_by_type')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }
}
