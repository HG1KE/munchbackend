<?php

namespace Tests\Unit;

use App\Events\AdminDashboardSaleRecorded;
use App\Http\Controllers\Admin\DashboardSalesKpiEventsController;
use App\Listeners\PublishAdminDashboardSaleRecorded;
use App\Model\Order;
use App\Observers\OrderObserver;
use App\Services\AdminDashboardSalesKpiBus;
use App\Services\AdminDashboardSalesKpiPublisher;
use App\Support\AdminDashboardSalesKpis;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AdminDashboardSalesKpiRealtimeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        AdminDashboardSalesKpiBus::flush();
        Carbon::setTestNow(Carbon::parse('2026-09-11 15:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_channel_and_event_names(): void
    {
        $this->assertSame('admin.dashboard.sale-recorded', AdminDashboardSaleRecorded::NAME);
        $this->assertSame('admin.dashboard.sales-kpis', AdminDashboardSaleRecorded::CHANNEL);
    }

    public function test_event_is_registered_on_the_listener(): void
    {
        $provider = file_get_contents(app_path('Providers/EventServiceProvider.php'));
        $this->assertStringContainsString('AdminDashboardSaleRecorded::class', $provider);
        $this->assertStringContainsString('PublishAdminDashboardSaleRecorded::class', $provider);
    }

    public function test_delivered_cash_sale_dispatches_once_and_reaches_the_bus(): void
    {
        Event::fake([AdminDashboardSaleRecorded::class]);

        $order = $this->makeOrder([
            'id' => 501,
            'branch_id' => 2,
            'order_status' => 'delivered',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'sales_channel' => 'takeaway',
            'order_type' => 'pos',
            'order_amount' => 690,
            'created_at' => Carbon::parse('2026-09-11 14:10:00'),
        ], true);

        $first = AdminDashboardSalesKpiPublisher::publish($order);
        $replay = AdminDashboardSalesKpiPublisher::publish($order);

        $this->assertNotNull($first);
        $this->assertNull($replay);
        $this->assertSame('munch', $first['category']);
        $this->assertSame(AdminDashboardSaleRecorded::NAME, $first['event']);
        $this->assertSame(AdminDashboardSaleRecorded::CHANNEL, $first['channel']);

        Event::assertDispatchedTimes(AdminDashboardSaleRecorded::class, 1);
        Event::assertDispatched(AdminDashboardSaleRecorded::class, function (AdminDashboardSaleRecorded $event) {
            return (int) $event->sale['order_id'] === 501
                && (int) $event->sale['branch_id'] === 2
                && $event->sale['category'] === 'munch'
                && $event->sale['payment_method'] === 'cash';
        });
    }

    public function test_listener_publishes_to_the_realtime_bus(): void
    {
        $payload = AdminDashboardSalesKpis::realtimePayload($this->makeOrder([
            'id' => 77,
            'branch_id' => 1,
            'order_status' => 'delivered',
            'payment_status' => 'paid',
            'payment_method' => 'card',
            'sales_channel' => 'pos',
            'order_type' => 'pos',
            'order_amount' => 500,
            'created_at' => Carbon::now(),
        ], true));

        event(new AdminDashboardSaleRecorded($payload));

        $snapshot = AdminDashboardSalesKpiBus::snapshot(0);
        $this->assertSame(1, $snapshot['version']);
        $this->assertSame(AdminDashboardSaleRecorded::CHANNEL, $snapshot['channel']);
        $this->assertCount(1, AdminDashboardSalesKpiBus::since(0));
        $this->assertSame(77, AdminDashboardSalesKpiBus::since(0)[0]['order_id']);
    }

    public function test_unpaid_pos_order_does_not_dispatch_until_paid(): void
    {
        Event::fake([AdminDashboardSaleRecorded::class]);

        $unpaid = $this->makeOrder([
            'id' => 80,
            'branch_id' => 1,
            'order_status' => 'confirmed',
            'payment_status' => 'unpaid',
            'payment_method' => 'cash',
            'sales_channel' => 'dine_in',
            'order_type' => 'dine_in',
            'order_amount' => 900,
            'created_at' => Carbon::now(),
        ], true);

        $this->assertFalse(AdminDashboardSalesKpis::qualifies($unpaid));
        $this->assertNull(AdminDashboardSalesKpiPublisher::publish($unpaid));
        Event::assertNotDispatched(AdminDashboardSaleRecorded::class);

        $unpaid->wasRecentlyCreated = false;
        $unpaid->syncOriginal();
        $unpaid->payment_status = 'paid';

        $this->assertTrue(AdminDashboardSalesKpis::qualifies($unpaid));
        $this->assertNotNull(AdminDashboardSalesKpiPublisher::publish($unpaid));
        Event::assertDispatchedTimes(AdminDashboardSaleRecorded::class, 1);
    }

    public function test_confirmed_paid_pos_delivery_is_a_munch_kpi_sale(): void
    {
        Event::fake([AdminDashboardSaleRecorded::class]);

        $order = $this->makeOrder([
            'id' => 81,
            'branch_id' => 3,
            'order_status' => 'confirmed',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'sales_channel' => 'delivery',
            'order_type' => 'pos',
            'order_amount' => 500,
            'created_at' => Carbon::now(),
        ], true);

        $this->assertTrue(AdminDashboardSalesKpis::qualifies($order));
        $first = AdminDashboardSalesKpiPublisher::publish($order);
        $this->assertNotNull($first);
        $this->assertSame('munch', $first['category']);
        Event::assertDispatchedTimes(AdminDashboardSaleRecorded::class, 1);
    }

    public function test_marketplace_categories_are_separated_from_munch(): void
    {
        $this->assertSame('glovo', AdminDashboardSalesKpis::category('glovo', 'glovo'));
        $this->assertSame('uber', AdminDashboardSalesKpis::category('uber', 'uber'));
        $this->assertSame('bolt_food', AdminDashboardSalesKpis::category('bolt_food', 'bolt_food'));
        $this->assertSame('munch', AdminDashboardSalesKpis::category('mpesa', 'takeaway', 'pos'));
        $this->assertSame('munch', AdminDashboardSalesKpis::category('card', 'pos', 'pos'));
        $this->assertSame('munch', AdminDashboardSalesKpis::category('paystack', 'delivery', 'pos'));
        $this->assertSame('other', AdminDashboardSalesKpis::category('cash', '', 'delivery'));
    }

    public function test_filters_respect_branch_and_timeframe(): void
    {
        $now = Carbon::parse('2026-09-11 15:00:00');
        $kilimaniToday = [
            'branch_id' => 4,
            'created_at' => '2026-09-11 10:00:00',
            'category' => 'munch',
        ];
        $nyaliToday = [
            'branch_id' => 9,
            'created_at' => '2026-09-11 10:00:00',
            'category' => 'munch',
        ];
        $kilimaniYesterday = [
            'branch_id' => 4,
            'created_at' => '2026-09-10 10:00:00',
            'category' => 'munch',
        ];

        $this->assertTrue(AdminDashboardSalesKpis::eventMatchesFilters($kilimaniToday, 'all', 'today', null, null, $now));
        $this->assertTrue(AdminDashboardSalesKpis::eventMatchesFilters($kilimaniToday, 4, 'today', null, null, $now));
        $this->assertFalse(AdminDashboardSalesKpis::eventMatchesFilters($nyaliToday, 4, 'today', null, null, $now));
        $this->assertFalse(AdminDashboardSalesKpis::eventMatchesFilters($kilimaniToday, 4, 'yesterday', null, null, $now));
        $this->assertTrue(AdminDashboardSalesKpis::eventMatchesFilters($kilimaniYesterday, 4, 'yesterday', null, null, $now));
        $this->assertTrue(AdminDashboardSalesKpis::eventMatchesFilters($kilimaniToday, 4, 'this_week', null, null, $now));
        $this->assertTrue(AdminDashboardSalesKpis::eventMatchesFilters($kilimaniToday, 4, 'this_month', null, null, $now));
        $this->assertFalse(AdminDashboardSalesKpis::eventMatchesFilters($kilimaniYesterday, 4, 'today', null, null, $now));
    }

    public function test_offline_retry_of_the_same_snapshot_does_not_emit_again(): void
    {
        Event::fake([AdminDashboardSaleRecorded::class]);

        $order = $this->makeOrder([
            'id' => 90,
            'branch_id' => 2,
            'order_status' => 'delivered',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'sales_channel' => 'takeaway',
            'order_type' => 'pos',
            'order_amount' => 690,
            'created_at' => Carbon::parse('2026-09-11 11:00:00'),
        ], true);

        $this->assertNotNull(AdminDashboardSalesKpiPublisher::publish($order));

        $order->wasRecentlyCreated = false;
        $order->syncOriginal();
        $this->assertNull(AdminDashboardSalesKpiPublisher::publish($order));

        Event::assertDispatchedTimes(AdminDashboardSaleRecorded::class, 1);
    }

    public function test_observer_saved_hook_publishes_without_blocking(): void
    {
        Event::fake([AdminDashboardSaleRecorded::class]);

        $observer = new OrderObserver();
        $order = $this->makeOrder([
            'id' => 91,
            'branch_id' => 1,
            'order_status' => 'delivered',
            'payment_status' => 'paid',
            'payment_method' => 'glovo',
            'sales_channel' => 'glovo',
            'order_type' => 'pos',
            'order_amount' => 500,
            'created_at' => Carbon::now(),
        ], true);

        $observer->saved($order);

        Event::assertDispatched(AdminDashboardSaleRecorded::class, function (AdminDashboardSaleRecorded $event) {
            return $event->sale['category'] === 'glovo' && (int) $event->sale['order_id'] === 91;
        });
    }

    public function test_paystack_pos_delivery_updates_munch_not_marketplace(): void
    {
        Event::fake([AdminDashboardSaleRecorded::class]);

        $order = $this->makeOrder([
            'id' => 93,
            'branch_id' => 14,
            'order_status' => 'confirmed',
            'payment_status' => 'paid',
            'payment_method' => 'paystack',
            'sales_channel' => 'delivery',
            'order_type' => 'pos',
            'order_amount' => 750,
            'created_at' => Carbon::now(),
        ], true);

        $payload = AdminDashboardSalesKpiPublisher::publish($order);
        $this->assertNotNull($payload);
        $this->assertSame('munch', $payload['category']);
        Event::assertDispatched(AdminDashboardSaleRecorded::class, function (AdminDashboardSaleRecorded $event) {
            return $event->sale['category'] === 'munch'
                && $event->sale['payment_method'] === 'paystack'
                && (int) $event->sale['order_id'] === 93;
        });
    }

    public function test_cancelled_pos_sale_publishes_removal_once(): void
    {
        Event::fake([AdminDashboardSaleRecorded::class]);

        $order = $this->makeOrder([
            'id' => 94,
            'branch_id' => 1,
            'order_status' => 'confirmed',
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'sales_channel' => 'dine_in',
            'order_type' => 'dine_in',
            'order_amount' => 200,
            'created_at' => Carbon::now(),
        ], true);

        $this->assertNotNull(AdminDashboardSalesKpiPublisher::publish($order));

        $order->wasRecentlyCreated = false;
        $order->syncOriginal();
        $order->order_status = 'canceled';
        $order->cancelled_at = Carbon::now();

        $removed = AdminDashboardSalesKpiPublisher::publish($order);
        $this->assertNotNull($removed);
        $this->assertSame('removed', $removed['category']);
        Event::assertDispatchedTimes(AdminDashboardSaleRecorded::class, 2);
    }

    public function test_events_endpoint_returns_new_sales_without_a_page_reload(): void
    {
        $payload = AdminDashboardSalesKpis::realtimePayload($this->makeOrder([
            'id' => 92,
            'branch_id' => 1,
            'order_status' => 'delivered',
            'payment_status' => 'paid',
            'payment_method' => 'mpesa',
            'sales_channel' => 'takeaway',
            'order_type' => 'pos',
            'order_amount' => 500,
            'created_at' => Carbon::now(),
        ], true));
        (new PublishAdminDashboardSaleRecorded())->handle(new AdminDashboardSaleRecorded($payload));

        $controller = new DashboardSalesKpiEventsController();
        $handshake = $controller(Request::create('/admin/dashboard/sales-kpis/events', 'GET', ['since' => 0]));
        $this->assertSame(200, $handshake->getStatusCode());
        $body = $handshake->getData(true);
        $this->assertTrue($body['connected']);
        $this->assertSame(AdminDashboardSaleRecorded::CHANNEL, $body['channel']);
        $this->assertSame([], $body['events']);

        $poll = $controller(Request::create('/admin/dashboard/sales-kpis/events', 'GET', [
            'since' => 0,
            'poll' => 1,
        ]));
        $events = $poll->getData(true)['events'];
        $this->assertCount(1, $events);
        $this->assertSame(92, $events[0]['order_id']);
        $this->assertSame('munch', $events[0]['category']);
    }

    public function test_duplicate_bus_events_still_leave_kpi_query_authoritative(): void
    {
        $service = file_get_contents(app_path('Services/AdminDashboardSalesKpiService.php'));
        $js = file_get_contents(public_path('assets/admin/js/munch-dashboard-kpis.js'));
        $observer = file_get_contents(app_path('Observers/OrderObserver.php'));

        $this->assertStringNotContainsString('earningReport()', $service);
        $this->assertStringContainsString('constrainQualifying', $service);
        $this->assertStringContainsString('loadSalesKpis()', $js);
        $this->assertStringContainsString('handleEvents', $js);
        $this->assertStringNotContainsString('location.reload', $js);
        $this->assertStringNotContainsString('window.location', $js);
        $this->assertStringContainsString('AdminDashboardSalesKpiPublisher::publish', $observer);
        $this->assertStringContainsString('dashboard/sales-kpis/events', file_get_contents(base_path('routes/admin.php')));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOrder(array $attributes, bool $created): Order
    {
        $order = new Order();
        $order->forceFill($attributes);
        $order->id = (int) $attributes['id'];
        $order->exists = true;
        $order->wasRecentlyCreated = $created;
        if (! $created) {
            $order->syncOriginal();
        }

        return $order;
    }
}
