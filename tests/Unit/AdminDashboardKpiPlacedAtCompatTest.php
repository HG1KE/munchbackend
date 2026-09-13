<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Services\AdminDashboardSalesKpiService;
use App\Support\AdminDashboardSalesKpis;
use App\Support\AdminSaleReportExport;
use App\Support\AdminSaleReportSummary;
use App\Support\PosOrderTypes;
use App\Support\PosSaleTime;
use App\Support\TimezoneDisplay;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminDashboardKpiPlacedAtCompatTest extends TestCase
{
    private string $previousConnection = 'mysql';

    protected function setUp(): void
    {
        parent::setUp();
        TimezoneDisplay::resetCache();
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
        TimezoneDisplay::resetCache();
        parent::tearDown();
    }

    public function test_kpi_sql_never_compares_timestamp_placed_at_to_empty_string(): void
    {
        $period = AdminDashboardSalesKpis::period('today', null, null, Carbon::parse('2026-09-13 15:00:00', 'Africa/Nairobi'));
        $sql = (new AdminDashboardSalesKpiService())->aggregatedQuery(null, $period)->toSql();

        $this->assertStringContainsString('placed_at', $sql);
        $this->assertStringContainsString('is null', strtolower($sql));
        $this->assertStringNotContainsString("placed_at` !=", $sql);
        $this->assertStringNotContainsString("placed_at` =", $sql);
        $this->assertStringNotContainsString("placed_at = ''", $sql);
        $this->assertStringNotContainsString("placed_at != ''", file_get_contents(app_path('Support/PosSaleTime.php')));
    }

    public function test_new_pos_order_with_placed_at_is_included_on_the_nairobi_sale_date(): void
    {
        $this->insertPos(1, 'takeaway', 'cash', 690, '2026-09-13 08:18:29', '2026-09-13 11:18:29');

        $this->assertSame(690.0, $this->munch('2026-09-13'));
        $this->assertSame(0.0, $this->munch('2026-09-12'));
    }

    public function test_legacy_pos_with_null_placed_at_falls_back_to_created_at(): void
    {
        $this->insertPos(1, 'takeaway', 'card', 2000, null, '2026-09-13 12:00:00');

        $this->assertSame(2000.0, $this->munch('2026-09-13'));
        $this->assertSame(0.0, $this->munch('2026-09-12'));
    }

    public function test_online_next_orders_stay_out_even_when_placed_at_is_null(): void
    {
        $this->insertPos(1, 'takeaway', 'cash', 50, '2026-09-13 10:00:00', '2026-09-13 13:00:00');
        DB::table('orders')->insert([
            'id' => 2,
            'branch_id' => 1,
            'order_type' => 'delivery',
            'sales_channel' => null,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'order_status' => 'delivered',
            'order_amount' => 9999,
            'placed_at' => null,
            'created_at' => '2026-09-13 12:00:00',
            'updated_at' => '2026-09-13 12:00:00',
        ]);
        DB::table('orders')->insert([
            'id' => 3,
            'branch_id' => 1,
            'order_type' => 'take_away',
            'sales_channel' => '',
            'payment_method' => 'mpesa',
            'payment_status' => 'paid',
            'order_status' => 'delivered',
            'order_amount' => 8888,
            'placed_at' => null,
            'created_at' => '2026-09-13 12:00:00',
            'updated_at' => '2026-09-13 12:00:00',
        ]);

        $this->assertFalse(PosOrderTypes::isPosFamily('delivery', null));
        $this->assertSame(50.0, $this->munch('2026-09-13'));
        $this->assertSame(50.0, $this->totals('2026-09-13')['munch_sales']);
    }

    /**
     * @dataProvider munchMatrix
     */
    public function test_pos_munch_channels_enter_munch_kpi(string $type, string $channel, string $method): void
    {
        $this->insertPos(1, $channel, $method, 100, '2026-09-13 10:00:00', '2026-09-13 13:00:00', 1, $type);
        $this->assertSame(100.0, $this->munch('2026-09-13'), $type.' '.$channel.' '.$method);
        $this->assertSame(0.0, $this->totals('2026-09-13')['glovo']);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function munchMatrix(): array
    {
        return [
            ['dine_in', 'dine_in', 'cash'],
            ['pos', 'takeaway', 'mpesa'],
            ['pos', 'delivery', 'cash'],
            ['pos', 'delivery', 'paystack'],
        ];
    }

    /**
     * @dataProvider marketplaceMatrix
     */
    public function test_marketplace_stays_on_its_own_kpi(string $channel): void
    {
        $this->insertPos(1, $channel, $channel, 250, '2026-09-13 10:00:00', '2026-09-13 13:00:00');
        $totals = $this->totals('2026-09-13');
        $this->assertSame(0.0, $totals['munch_sales'], $channel);
        $this->assertSame(250.0, $totals[$channel], $channel);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function marketplaceMatrix(): array
    {
        return [['glovo'], ['uber'], ['bolt_food']];
    }

    public function test_cancelled_failed_returned_and_refunded_are_excluded(): void
    {
        $this->insertPos(1, 'takeaway', 'cash', 100, '2026-09-13 10:00:00', '2026-09-13 13:00:00', 1, 'pos', 'canceled', '2026-09-13 14:00:00');
        $this->insertPos(2, 'takeaway', 'cash', 80, '2026-09-13 10:00:00', '2026-09-13 13:00:00', 1, 'pos', 'failed');
        $this->insertPos(3, 'takeaway', 'cash', 70, '2026-09-13 10:00:00', '2026-09-13 13:00:00', 1, 'pos', 'returned');
        $this->insertPos(4, 'takeaway', 'cash', 60, '2026-09-13 10:00:00', '2026-09-13 13:00:00', 1, 'pos', 'refunded');
        $this->insertPos(5, 'takeaway', 'cash', 50, '2026-09-13 10:00:00', '2026-09-13 13:00:00');

        $this->assertSame(50.0, $this->munch('2026-09-13'));
    }

    public function test_nairobi_day_uses_utc_bounds_for_placed_at(): void
    {
        $this->insertPos(1, 'takeaway', 'cash', 850, '2026-09-12 20:59:00', '2026-09-12 21:05:00');
        $this->insertPos(2, 'takeaway', 'cash', 100, '2026-09-12 21:05:00', '2026-09-12 21:10:00');

        $this->assertSame(850.0, $this->munch('2026-09-12'));
        $this->assertSame(100.0, $this->munch('2026-09-13'));
    }

    public function test_offline_sync_does_not_duplicate_the_kpi(): void
    {
        $this->insertPos(1, 'takeaway', 'cash', 690, '2026-09-13 08:18:29', '2026-09-13 11:18:29');
        $totals = $this->totals('2026-09-13');
        $this->assertSame(690.0, $totals['munch_sales']);
        $this->assertSame(1, $this->qualifyingIds('2026-09-13')->count());
    }

    public function test_branch_and_timeframe_filters_stay_correct(): void
    {
        $this->insertPos(1, 'takeaway', 'cash', 100, '2026-09-13 10:00:00', '2026-09-13 13:00:00', 1);
        $this->insertPos(2, 'takeaway', 'cash', 200, '2026-09-13 10:00:00', '2026-09-13 13:00:00', 4);
        $this->insertPos(3, 'takeaway', 'cash', 50, '2026-09-12 10:00:00', '2026-09-12 13:00:00', 1);

        $this->assertSame(300.0, $this->munch('2026-09-13'));
        $this->assertSame(100.0, $this->munch('2026-09-13', 1));
        $this->assertSame(200.0, $this->munch('2026-09-13', 4));
        $this->assertSame(50.0, $this->munch('2026-09-12', 1));

        $now = Carbon::parse('2026-09-13 15:00:00', 'Africa/Nairobi');
        $today = AdminDashboardSalesKpis::period('today', null, null, $now);
        $yesterday = AdminDashboardSalesKpis::period('yesterday', null, null, $now);
        $service = new AdminDashboardSalesKpiService();
        $this->assertSame(300.0, AdminDashboardSalesKpis::fromGroupedRows($this->rows($service->aggregatedQuery(null, $today)))['munch_sales']);
        $this->assertSame(50.0, AdminDashboardSalesKpis::fromGroupedRows($this->rows($service->aggregatedQuery(null, $yesterday)))['munch_sales']);
    }

    public function test_summarize_returns_real_currency_values_when_sales_exist(): void
    {
        $this->insertPos(1, 'takeaway', 'cash', 12670, '2026-09-13 08:18:29', '2026-09-13 11:18:29');
        $this->insertPos(2, 'delivery', 'paystack', 1500, '2026-09-13 10:00:00', '2026-09-13 13:00:00');

        $totals = $this->totals('2026-09-13', 1);
        $this->assertSame(14170.0, $totals['munch_sales']);
        $this->assertSame(12670.0, $totals['cash']);
        $this->assertSame(1500.0, $totals['paystack']);
        $this->assertGreaterThan(0, $totals['munch_sales']);
        $this->assertSame(14170.0, AdminSaleReportSummary::fromPaymentTotals([
            'cash' => 12670,
            'paystack' => 1500,
        ])['munch_sales']);
    }

    public function test_sale_report_and_dashboard_share_the_same_business_date_for_new_and_legacy_pos(): void
    {
        $this->insertPos(1, 'takeaway', 'cash', 690, '2026-09-12 20:59:00', '2026-09-12 21:05:00');
        $this->insertPos(2, 'takeaway', 'card', 200, null, '2026-09-12 23:00:00');

        $dashboard = $this->munch('2026-09-12');
        $query = PosSaleTime::constrainBusinessPeriod(Order::query()->pos(), '2026-09-12', '2026-09-12');
        $saleIds = AdminDashboardSalesKpis::constrainNotVoided($query)->pluck('id')->all();
        $report = AdminSaleReportExport::build(Order::query()->whereIn('id', $saleIds)->get(), [
            'from' => '2026-09-12',
            'to' => '2026-09-12',
            'payment_totals' => ['cash' => 690, 'card' => 200, 'mpesa' => 0, 'paystack' => 0, 'glovo' => 0, 'uber' => 0, 'bolt_food' => 0],
        ]);

        $this->assertSame([1, 2], array_map('intval', $saleIds));
        $this->assertSame(890.0, $dashboard);
        $this->assertSame(890.0, $report['totals']['munch_sales']);
    }

    public function test_frontend_still_writes_munch_and_marketplace_values(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-dashboard-kpis.js'));
        $this->assertStringContainsString("$('#kpi-munch-sales').text(data.munch_sales)", $js);
        $this->assertStringContainsString("$('#kpi-glovo').text(data.glovo)", $js);
        $this->assertStringContainsString('function loadSalesKpis()', $js);
        $this->assertStringContainsString('event.date', $js);
    }

    private function munch(string $day, ?int $branchId = null): float
    {
        return $this->totals($day, $branchId)['munch_sales'];
    }

    /**
     * @return array<string, float>
     */
    private function totals(string $day, ?int $branchId = null): array
    {
        $period = AdminDashboardSalesKpis::period('custom', $day, $day, Carbon::parse($day.' 12:00:00', 'Africa/Nairobi'));
        $service = new AdminDashboardSalesKpiService();

        return AdminDashboardSalesKpis::fromGroupedRows($this->rows($service->aggregatedQuery($branchId, $period)));
    }

    /**
     * @return list<array{payment_method: mixed, sales_channel: mixed, order_type: mixed, total: float}>
     */
    private function rows(\Illuminate\Database\Eloquent\Builder $query): array
    {
        return $query->get()->map(fn ($row) => [
            'payment_method' => $row->payment_method,
            'sales_channel' => $row->sales_channel,
            'order_type' => $row->order_type,
            'total' => (float) $row->total,
        ])->all();
    }

    private function qualifyingIds(string $day): \Illuminate\Support\Collection
    {
        $period = AdminDashboardSalesKpis::period('custom', $day, $day, Carbon::parse($day.' 12:00:00', 'Africa/Nairobi'));

        return PosSaleTime::constrainBusinessPeriod(
            AdminDashboardSalesKpis::constrainQualifying(Order::query()),
            $period['from'],
            $period['to']
        )->pluck('id');
    }

    private function insertPos(
        int $id,
        string $channel,
        string $method,
        float $amount,
        ?string $placedAtUtc,
        string $createdAt,
        int $branchId = 1,
        string $orderType = 'pos',
        string $status = 'delivered',
        ?string $cancelledAt = null
    ): void {
        DB::table('orders')->insert([
            'id' => $id,
            'branch_id' => $branchId,
            'order_type' => $channel === 'dine_in' ? 'dine_in' : $orderType,
            'sales_channel' => $channel,
            'payment_method' => $method,
            'payment_status' => 'paid',
            'order_status' => $status,
            'order_amount' => $amount,
            'placed_at' => $placedAtUtc,
            'cancelled_at' => $cancelledAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
