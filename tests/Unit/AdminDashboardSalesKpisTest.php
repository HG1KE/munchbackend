<?php

namespace Tests\Unit;

use App\Services\AdminDashboardSalesKpiService;
use App\Support\AdminDashboardSalesKpis;
use App\Support\AdminSaleReportSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminDashboardSalesKpisTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_today_range(): void
    {
        $now = Carbon::parse('2026-09-10 15:30:00');
        $period = AdminDashboardSalesKpis::period('today', null, null, $now);

        $this->assertSame('2026-09-10 00:00:00', $period['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-10 23:59:59', $period['to']->format('Y-m-d H:i:s'));
    }

    public function test_yesterday_range(): void
    {
        $now = Carbon::parse('2026-09-10 15:30:00');
        $period = AdminDashboardSalesKpis::period('yesterday', null, null, $now);

        $this->assertSame('2026-09-09 00:00:00', $period['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-09 23:59:59', $period['to']->format('Y-m-d H:i:s'));
    }

    public function test_last_7_days_range_includes_today(): void
    {
        $now = Carbon::parse('2026-09-10 15:30:00');
        $period = AdminDashboardSalesKpis::period('last_7_days', null, null, $now);

        $this->assertSame('2026-09-04 00:00:00', $period['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-10 23:59:59', $period['to']->format('Y-m-d H:i:s'));
    }

    public function test_this_month_range(): void
    {
        $now = Carbon::parse('2026-09-10 15:30:00');
        $period = AdminDashboardSalesKpis::period('this_month', null, null, $now);

        $this->assertSame('2026-09-01 00:00:00', $period['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-30 23:59:59', $period['to']->format('Y-m-d H:i:s'));
    }

    public function test_cash_card_and_mpesa_aggregate_to_munch_sales(): void
    {
        $totals = AdminDashboardSalesKpis::fromGroupedRows([
            ['payment_method' => 'cash', 'sales_channel' => 'pos', 'total' => 82000],
            ['payment_method' => 'card', 'sales_channel' => 'delivery', 'total' => 44000],
            ['payment_method' => 'mpesa', 'sales_channel' => 'takeaway', 'total' => 60450],
            ['payment_method' => 'glovo', 'sales_channel' => 'glovo', 'total' => 73210],
        ]);

        $this->assertSame(82000.0, $totals['cash']);
        $this->assertSame(44000.0, $totals['card']);
        $this->assertSame(60450.0, $totals['mpesa']);
        $this->assertSame(186450.0, $totals['munch_sales']);
        $this->assertSame(
            AdminSaleReportSummary::fromPaymentTotals($totals)['munch_sales'],
            $totals['munch_sales']
        );
        $this->assertSame(186450.0, $totals['cash'] + $totals['card'] + $totals['mpesa']);
    }

    public function test_marketplace_channels_are_aggregated_separately_and_excluded_from_munch(): void
    {
        $totals = AdminDashboardSalesKpis::fromGroupedRows([
            ['payment_method' => 'cash', 'sales_channel' => 'pos', 'total' => 100],
            ['payment_method' => 'glovo', 'sales_channel' => 'glovo', 'total' => 73210],
            ['payment_method' => 'uber', 'sales_channel' => 'uber', 'total' => 38420],
            ['payment_method' => 'bolt_food', 'sales_channel' => 'bolt_food', 'total' => 16980],
        ]);

        $this->assertSame(100.0, $totals['munch_sales']);
        $this->assertSame(73210.0, $totals['glovo']);
        $this->assertSame(38420.0, $totals['uber']);
        $this->assertSame(16980.0, $totals['bolt_food']);
        $this->assertNotEquals($totals['munch_sales'], $totals['glovo'] + $totals['uber'] + $totals['bolt_food']);
    }

    public function test_empty_dataset_returns_zeros(): void
    {
        $totals = AdminDashboardSalesKpis::fromGroupedRows([]);

        $this->assertSame(0.0, $totals['munch_sales']);
        $this->assertSame(0.0, $totals['cash']);
        $this->assertSame(0.0, $totals['card']);
        $this->assertSame(0.0, $totals['mpesa']);
        $this->assertSame(0.0, $totals['glovo']);
        $this->assertSame(0.0, $totals['uber']);
        $this->assertSame(0.0, $totals['bolt_food']);
    }

    public function test_all_branches_omits_branch_filter_and_one_branch_applies_it(): void
    {
        $service = new AdminDashboardSalesKpiService();
        $period = AdminDashboardSalesKpis::period('today', null, null, Carbon::parse('2026-09-10 12:00:00'));

        $all = $service->aggregatedQuery(null, $period);
        $allSql = strtolower($all->toSql());
        $this->assertStringNotContainsString('branch_id', $allSql);
        $this->assertStringContainsString('group by', $allSql);
        $this->assertStringContainsString('payment_method', $allSql);
        $this->assertStringContainsString('sales_channel', $allSql);
        $this->assertStringContainsString('sum(order_amount)', $allSql);
        $this->assertStringContainsString('order_status', $allSql);

        $one = $service->aggregatedQuery(7, $period);
        $this->assertStringContainsString('branch_id', $one->toSql());
        $this->assertContains(7, $one->getBindings());
    }

    public function test_request_defaults_to_all_branches_and_today(): void
    {
        $request = Request::create('/admin/dashboard/sales-kpis', 'GET');
        $this->assertSame('all', $request->input('branch_id', 'all'));
        $this->assertSame('today', $request->input('timeframe', 'today'));

        $service = file_get_contents(app_path('Services/AdminDashboardSalesKpiService.php'));
        $this->assertStringContainsString("input('branch_id', 'all')", $service);
        $this->assertStringContainsString("input('timeframe', 'today')", $service);
        $this->assertStringContainsString('earningReport()', $service);
        $this->assertStringContainsString("groupBy('payment_method', 'sales_channel')", $service);
    }

    public function test_dashboard_exposes_kpi_filters_and_does_not_change_sale_report(): void
    {
        $page = file_get_contents(resource_path('views/admin-views/dashboard.blade.php'));
        $partial = file_get_contents(resource_path('views/admin-views/partials/_dashboard-sales-kpis.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Admin/DashboardController.php'));
        $saleReport = file_get_contents(resource_path('views/admin-views/report/sale-report.blade.php'));

        $this->assertStringContainsString("partials._dashboard-sales-kpis", $page);
        $this->assertStringContainsString('munch-dashboard-kpis.js', $page);
        $this->assertStringContainsString("summarize(null, 'today')", $controller);
        $this->assertStringContainsString("value=\"all\" selected", $partial);
        $this->assertStringContainsString("value=\"today\" selected", $partial);
        $this->assertStringContainsString('id="kpi-munch-sales"', $partial);
        $this->assertStringContainsString('id="kpi-glovo"', $partial);
        $this->assertStringContainsString('id="kpi-uber"', $partial);
        $this->assertStringContainsString('id="kpi-bolt-food"', $partial);
        $this->assertStringContainsString('Last 7 Days', $partial);
        $this->assertStringContainsString('This Month', $partial);
        $this->assertStringContainsString('sale-report-filter', $saleReport);
        $this->assertStringNotContainsString('munch-dash-kpis', $saleReport);
    }
}
