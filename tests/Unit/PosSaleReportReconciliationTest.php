<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Services\AdminDashboardSalesKpiService;
use App\Support\AdminDashboardSalesKpis;
use App\Support\AdminSaleReportExport;
use App\Support\AdminSaleReportSummary;
use App\Support\OrderPlacementTime;
use App\Support\PosOrderTypes;
use App\Support\PosSaleTime;
use App\Support\TimezoneDisplay;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosSaleReportReconciliationTest extends TestCase
{
    private string $previousConnection = 'mysql';

    protected function setUp(): void
    {
        parent::setUp();
        TimezoneDisplay::resetCache();
        OrderPlacementTime::resetColumnCache();

        $this->previousConnection = (string) config('database.default');
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('branch_id')->default(1);
            $table->string('order_type')->default('pos');
            $table->string('sales_channel')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_status')->default('paid');
            $table->string('order_status')->default('delivered');
            $table->string('readable_order_id')->nullable();
            $table->string('client_uuid')->nullable();
            $table->decimal('order_amount', 12, 2)->default(0);
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('orders');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        Carbon::setTestNow();
        TimezoneDisplay::resetCache();
        OrderPlacementTime::resetColumnCache();
        parent::tearDown();
    }

    public function test_apply_to_order_preserves_client_placed_at_and_leaves_created_at_unset(): void
    {
        $order = new Order();
        $placed = Carbon::parse('2026-09-12T10:20:00Z');
        OrderPlacementTime::applyToOrder($order, $placed);

        $this->assertSame('2026-09-12 10:20:00', $order->getAttributes()['placed_at']);
        $this->assertTrue($order->isDirty('placed_at'));
        $this->assertArrayNotHasKey('created_at', $order->getAttributes());

        OrderPlacementTime::applyToOrder($order);
        $this->assertSame('2026-09-12 10:20:00', $order->getAttributes()['placed_at']);
    }

    public function test_creating_hook_does_not_overwrite_an_already_set_placed_at(): void
    {
        $order = new Order();
        $order->placed_at = '2026-09-12 08:33:00';
        OrderPlacementTime::applyToOrder($order, Carbon::now('UTC'));

        $this->assertSame('2026-09-12 08:33:00', $order->getAttributes()['placed_at']);
    }

    public function test_utc_iso_sale_displays_as_nairobi_afternoon(): void
    {
        $this->assertSame('1:20 PM', AdminSaleReportExport::formatTime('2026-09-12T10:20:00Z'));
        $this->assertSame('1:20 PM', AdminSaleReportExport::formatTime('2026-09-12 10:20:00'));
        $this->assertSame(
            '12 Sep 2026 1:20 PM',
            AdminSaleReportExport::listingRows([[
                'id' => 1,
                'readable_order_id' => 'A1',
                'placed_at' => '2026-09-12T10:20:00Z',
                'created_at' => '2026-09-12 15:28:00',
                'order_amount' => 100,
                'sales_channel' => 'takeaway',
                'order_type' => 'pos',
            ]])[0]['date']
        );
    }

    public function test_sale_report_and_dashboard_use_placed_at_not_created_at_for_the_business_date(): void
    {
        $this->insertPos(1, 'takeaway', 'cash', 690, '2026-09-12 20:59:00', '2026-09-12 21:05:00');
        $this->insertPos(2, 'delivery', 'paystack', 1500, '2026-09-12 10:20:00', '2026-09-13 12:28:00');

        $sept12 = $this->saleIds('2026-09-12', '2026-09-12');
        $sept13 = $this->saleIds('2026-09-13', '2026-09-13');

        $this->assertSame([1, 2], $sept12);
        $this->assertSame([], $sept13);

        $dashboard = $this->dashboardTotals(1, '2026-09-12', '2026-09-12');
        $this->assertSame(2190.0, $dashboard['munch_sales']);
        $this->assertSame(0.0, $this->dashboardTotals(1, '2026-09-13', '2026-09-13')['munch_sales']);
    }

    public function test_offline_sync_after_midnight_stays_on_the_original_nairobi_date(): void
    {
        $this->insertPos(10398, 'takeaway', 'cash', 850, '2026-09-12 20:59:00', '2026-09-12 21:05:00', 1, 'A10398');

        $this->assertSame([10398], $this->saleIds('2026-09-12', '2026-09-12'));
        $this->assertSame([], $this->saleIds('2026-09-13', '2026-09-13'));

        $row = AdminSaleReportExport::listingRows(Order::query()->where('id', 10398)->get())[0];
        $this->assertSame('12 Sep 2026 11:59 PM', $row['date']);
        $this->assertSame('A10398', $row['order_display_id']);
    }

    /**
     * @dataProvider munchChannelProvider
     */
    public function test_pos_munch_channels_classify_as_munch(string $orderType, string $channel): void
    {
        $this->assertSame('munch', AdminDashboardSalesKpis::category('cash', $channel, $orderType));
        $this->assertContains(PosOrderTypes::saleReportCategory($orderType, $channel), ['dine_in', 'takeaway', 'delivery']);
        $this->assertTrue(PosOrderTypes::isPosFamily($orderType, $channel));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function munchChannelProvider(): array
    {
        return [
            ['dine_in', 'dine_in'],
            ['pos', 'takeaway'],
            ['pos', 'delivery'],
        ];
    }

    /**
     * @dataProvider marketplaceProvider
     */
    public function test_marketplace_channels_stay_platform_specific(string $channel): void
    {
        $this->assertSame($channel, AdminDashboardSalesKpis::category($channel, $channel, 'pos'));
        $this->assertSame($channel, PosOrderTypes::saleReportCategory('pos', $channel));
        $this->assertTrue(PosOrderTypes::isPosFamily('pos', $channel));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function marketplaceProvider(): array
    {
        return [['glovo'], ['uber'], ['bolt_food']];
    }

    public function test_online_next_orders_are_not_pos_munch(): void
    {
        $this->assertFalse(PosOrderTypes::isPosFamily('delivery', null));
        $this->assertFalse(PosOrderTypes::isPosFamily('take_away', null));
        $this->assertNull(PosOrderTypes::saleReportCategory('delivery', null));
        $this->assertNull(PosOrderTypes::saleReportCategory('take_away', ''));
        $this->assertSame('other', AdminDashboardSalesKpis::category('cash', null, 'delivery'));
        $this->assertSame('other', AdminDashboardSalesKpis::category('mpesa', '', 'take_away'));
    }

    public function test_munch_payment_totals_include_cash_card_mpesa_and_paystack(): void
    {
        $groups = AdminSaleReportSummary::fromPaymentTotals([
            'cash' => 100,
            'card' => 200,
            'mpesa' => 50,
            'paystack' => 400,
            'glovo' => 80,
            'uber' => 40,
            'bolt_food' => 30,
        ]);

        $this->assertSame(750.0, $groups['munch_sales']);
        $this->assertSame(150.0, $groups['marketplace_sales']);
    }

    public function test_cancelled_orders_use_original_sale_time_and_stay_out_of_valid_totals(): void
    {
        $this->insertPos(1, 'takeaway', 'cash', 690, '2026-09-12 10:20:00', '2026-09-12 15:28:00');
        $this->insertPos(2, 'takeaway', 'mpesa', 850, '2026-09-12 11:00:00', '2026-09-12 15:28:10', 1, 'A2', 'canceled', '2026-09-12 12:00:00');

        $valid = $this->saleQuery('2026-09-12', '2026-09-12')->get();
        $cancelled = $this->cancelledQuery('2026-09-12', '2026-09-12')->get();
        $report = AdminSaleReportExport::build($valid, [
            'branch_name' => 'Munch Nyali',
            'from' => '2026-09-12',
            'to' => '2026-09-12',
            'payment_totals' => ['cash' => 690, 'card' => 0, 'mpesa' => 0, 'paystack' => 0, 'glovo' => 0, 'uber' => 0, 'bolt_food' => 0],
            'cancelled_orders' => $cancelled,
        ]);

        $this->assertSame([1], $valid->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([2], $cancelled->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(690.0, $report['totals']['munch_sales']);
        $this->assertSame(1, $report['order_counts']['munch_sales']);
        $this->assertSame(850.0, $report['cancelled']['total']);
        $this->assertSame(1, $report['cancelled']['order_count']);
        $this->assertSame('1:20 PM', $report['sections']['munch_sales']['orders'][0]['time']);
        $this->assertSame('2:00 PM', $report['sections']['cancelled']['orders'][0]['time']);
        $this->assertSame(690.0, $this->dashboardTotals(1, '2026-09-12', '2026-09-12')['munch_sales']);
    }

    public function test_dashboard_munch_equals_sale_report_valid_munch_for_the_same_branch_date(): void
    {
        $this->insertPos(1, 'dine_in', 'cash', 1000, '2026-09-12 07:00:00', '2026-09-12 07:01:00', 1, 'A1', 'confirmed');
        $this->insertPos(2, 'takeaway', 'card', 2000, '2026-09-12 08:00:00', '2026-09-12 08:01:00');
        $this->insertPos(3, 'delivery', 'mpesa', 3000, '2026-09-12 09:00:00', '2026-09-12 09:01:00');
        $this->insertPos(4, 'delivery', 'paystack', 4000, '2026-09-12 10:00:00', '2026-09-12 15:28:00');
        $this->insertPos(5, 'glovo', 'glovo', 500, '2026-09-12 11:00:00', '2026-09-12 11:01:00');
        $this->insertPos(6, 'uber', 'uber', 600, '2026-09-12 12:00:00', '2026-09-12 12:01:00');
        $this->insertPos(7, 'bolt_food', 'bolt_food', 700, '2026-09-12 13:00:00', '2026-09-12 13:01:00');
        $this->insertPos(8, 'takeaway', 'cash', 111, '2026-09-12 14:00:00', '2026-09-12 14:01:00', 1, 'A8', 'canceled', '2026-09-12 14:10:00');
        $this->insertOnline(9, 'delivery', 9999, '2026-09-12 10:00:00');
        $this->insertOnline(10, 'take_away', 8888, '2026-09-12 10:00:00');

        $valid = $this->saleQuery('2026-09-12', '2026-09-12')->get();
        $payments = [
            'cash' => 1000.0,
            'card' => 2000.0,
            'mpesa' => 3000.0,
            'paystack' => 4000.0,
            'glovo' => 500.0,
            'uber' => 600.0,
            'bolt_food' => 700.0,
        ];
        foreach ($valid as $order) {
            $this->assertTrue(PosOrderTypes::isPosFamily($order->order_type, $order->sales_channel));
            $this->assertFalse(PosOrderTypes::isOnlineOrder($order->order_type, $order->sales_channel));
        }

        $report = AdminSaleReportExport::build($valid, [
            'branch_name' => 'Munch Nyali',
            'from' => '2026-09-12',
            'to' => '2026-09-12',
            'payment_totals' => $payments,
        ]);
        $dashboard = $this->dashboardTotals(1, '2026-09-12', '2026-09-12');
        $munchPayments = AdminSaleReportSummary::fromPaymentTotals($payments)['munch_sales'];

        $this->assertSame(10000.0, $report['totals']['munch_sales']);
        $this->assertSame(10000.0, $dashboard['munch_sales']);
        $this->assertSame(10000.0, $munchPayments);
        $this->assertSame(500.0, $report['totals']['glovo']);
        $this->assertSame(600.0, $report['totals']['uber']);
        $this->assertSame(700.0, $report['totals']['bolt_food']);
        $this->assertSame($dashboard['glovo'], $report['totals']['glovo']);
        $this->assertSame($dashboard['uber'], $report['totals']['uber']);
        $this->assertSame($dashboard['bolt_food'], $report['totals']['bolt_food']);
        $this->assertNotContains(9, $valid->pluck('id')->all());
        $this->assertNotContains(10, $valid->pluck('id')->all());
        $this->assertCount(7, $valid);
        $this->assertCount(7, array_unique($valid->pluck('id')->all()));
    }

    public function test_duplicate_client_uuid_lookup_does_not_change_placed_at(): void
    {
        $this->insertPos(1, 'takeaway', 'cash', 690, '2026-09-12 10:20:00', '2026-09-12 15:28:00', 1, 'A1');
        DB::table('orders')->where('id', 1)->update(['client_uuid' => 'same-uuid']);

        $existing = Order::query()->where('branch_id', 1)->where('client_uuid', 'same-uuid')->first();
        $this->assertNotNull($existing);
        $this->assertSame('2026-09-12 10:20:00', $existing->getAttributes()['placed_at']);
        $this->assertSame('2026-09-12 15:28:00', $existing->getAttributes()['created_at']);
    }

    public function test_future_flow_keeps_created_at_as_server_time_in_source(): void
    {
        $pos = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $placement = file_get_contents(app_path('Support/OrderPlacementTime.php'));
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));

        $this->assertStringNotContainsString('$order->created_at = $placedAt', $pos);
        $this->assertStringNotContainsString('$order->updated_at = $placedAt', $pos);
        $this->assertStringContainsString('resolvePosPlacedAt', $pos);
        $this->assertStringContainsString('TimezoneDisplay::parseStoredUtc', $pos);
        $this->assertStringContainsString('OrderPlacementTime::applyToOrder($order, $placedAt)', $pos);
        $this->assertStringNotContainsString('setRawAttributes', $placement);
        $this->assertStringContainsString("getAttributes()['placed_at']", $placement);
        $this->assertStringContainsString('buildPayload(uuid(), new Date().toISOString())', $js);
        $this->assertStringContainsString('placed_at: placedAt', $js);
        $this->assertStringContainsString('createdAt: payload.placed_at', $js);
    }

    public function test_dashboard_event_filters_use_placed_at_business_date(): void
    {
        $now = Carbon::parse('2026-09-13 00:30:00', 'Africa/Nairobi');
        $event = [
            'branch_id' => 1,
            'placed_at' => '2026-09-12T20:59:00Z',
            'created_at' => '2026-09-12 21:05:00',
            'date' => '2026-09-12',
        ];

        $this->assertTrue(AdminDashboardSalesKpis::eventMatchesFilters($event, 1, 'custom', '2026-09-12', '2026-09-12', $now));
        $this->assertFalse(AdminDashboardSalesKpis::eventMatchesFilters($event, 1, 'custom', '2026-09-13', '2026-09-13', $now));
        $this->assertFalse(AdminDashboardSalesKpis::eventMatchesFilters($event, 1, 'today', null, null, $now));
    }

    public function test_sale_report_exports_reuse_the_same_payload(): void
    {
        $this->insertPos(1, 'takeaway', 'cash', 690, '2026-09-12 10:20:00', '2026-09-12 15:28:00', 1, 'A1');
        $report = AdminSaleReportExport::build($this->saleQuery('2026-09-12', '2026-09-12')->get(), [
            'branch_name' => 'Munch Nyali',
            'from' => '2026-09-12',
            'to' => '2026-09-12',
            'payment_totals' => ['cash' => 690, 'card' => 0, 'mpesa' => 0, 'paystack' => 0, 'glovo' => 0, 'uber' => 0, 'bolt_food' => 0],
        ]);

        $csv = AdminSaleReportExport::csvString($report);
        $html = view('admin-views.report.partials._sale-report-export', compact('report'))->render();
        $this->assertSame('1:20 PM', $report['sections']['munch_sales']['orders'][0]['time']);
        $this->assertStringContainsString('1:20 PM', $csv);
        $this->assertStringContainsString('A1', $csv);
        $this->assertStringContainsString('1:20 PM', $html);
        $this->assertCount(1, $report['sections']['munch_sales']['orders']);
    }

    /**
     * @return list<int>
     */
    private function saleIds(string $from, string $to): array
    {
        return $this->saleQuery($from, $to)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function saleQuery(string $from, string $to): \Illuminate\Database\Eloquent\Builder
    {
        $query = PosSaleTime::constrainBusinessPeriod(Order::query()->pos()->where('branch_id', 1), $from, $to);
        PosOrderTypes::constrainSaleReportChannel($query, 'all');

        return AdminDashboardSalesKpis::constrainNotVoided($query);
    }

    private function cancelledQuery(string $from, string $to): \Illuminate\Database\Eloquent\Builder
    {
        $query = PosSaleTime::constrainBusinessPeriod(Order::query()->pos()->where('branch_id', 1), $from, $to);
        PosOrderTypes::constrainSaleReportChannel($query, 'all');

        return AdminDashboardSalesKpis::constrainVoided($query);
    }

    /**
     * @return array<string, float>
     */
    private function dashboardTotals(int $branchId, string $from, string $to): array
    {
        $period = AdminDashboardSalesKpis::period('custom', $from, $to, Carbon::parse($from.' 12:00:00', 'Africa/Nairobi'));
        $service = new AdminDashboardSalesKpiService();
        $rows = $service->aggregatedQuery($branchId, $period)->get()->map(fn ($row) => [
            'payment_method' => $row->payment_method,
            'sales_channel' => $row->sales_channel,
            'order_type' => $row->order_type,
            'total' => (float) $row->total,
        ])->all();

        return AdminDashboardSalesKpis::fromGroupedRows($rows);
    }

    private function insertPos(
        int $id,
        string $channel,
        string $method,
        float $amount,
        string $placedAtUtc,
        string $createdAtUtc,
        int $branchId = 1,
        string $readable = '',
        string $status = 'delivered',
        ?string $cancelledAt = null
    ): void {
        DB::table('orders')->insert([
            'id' => $id,
            'branch_id' => $branchId,
            'order_type' => $channel === 'dine_in' ? 'dine_in' : 'pos',
            'sales_channel' => $channel,
            'payment_method' => $method,
            'payment_status' => 'paid',
            'order_status' => $status,
            'readable_order_id' => $readable !== '' ? $readable : 'A'.$id,
            'order_amount' => $amount,
            'placed_at' => $placedAtUtc,
            'cancelled_at' => $cancelledAt,
            'created_at' => $createdAtUtc,
            'updated_at' => $createdAtUtc,
        ]);
    }

    private function insertOnline(int $id, string $type, float $amount, string $placedAtUtc): void
    {
        DB::table('orders')->insert([
            'id' => $id,
            'branch_id' => 1,
            'order_type' => $type,
            'sales_channel' => null,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'order_status' => 'delivered',
            'readable_order_id' => 'W'.$id,
            'order_amount' => $amount,
            'placed_at' => $placedAtUtc,
            'created_at' => $placedAtUtc,
            'updated_at' => $placedAtUtc,
        ]);
    }
}
