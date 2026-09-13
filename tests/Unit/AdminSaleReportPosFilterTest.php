<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Support\AdminDashboardSalesKpis;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Support\AdminSaleReportExport;
use App\Support\AdminSaleReportSummary;
use App\Support\PosOrderTypes;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminSaleReportPosFilterTest extends TestCase
{
    private string $previousConnection = 'mysql';

    protected function setUp(): void
    {
        parent::setUp();

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
            $table->decimal('order_amount', 12, 2)->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('orders');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_pos_filter_includes_every_pos_channel_and_excludes_website_orders(): void
    {
        $today = Carbon::parse('2026-09-12 10:00:00');
        $yesterday = Carbon::parse('2026-09-11 10:00:00');

        $this->insert(1, 'dine_in', 'dine_in', 100, 1, $today);
        $this->insert(2, 'pos', 'takeaway', 200, 1, $today);
        $this->insert(3, 'pos', 'delivery', 300, 1, $today);
        $this->insert(4, 'pos', 'uber', 40, 1, $today);
        $this->insert(5, 'pos', 'glovo', 50, 1, $today);
        $this->insert(6, 'pos', 'bolt_food', 60, 1, $today);
        $this->insert(7, 'delivery', null, 999, 1, $today);
        $this->insert(8, 'take_away', null, 888, 1, $today);
        $this->insert(9, 'pos', 'takeaway', 15, 2, $today);
        $this->insert(10, 'pos', 'takeaway', 25, 1, $yesterday);

        $ids = $this->filteredIds('pos', 1, $today->copy()->startOfDay(), $today->copy()->endOfDay());

        $this->assertSame([1, 2, 3, 4, 5, 6], $ids);
        $this->assertContains(1, $ids, 'POS Dine In');
        $this->assertContains(2, $ids, 'POS Take Away');
        $this->assertContains(3, $ids, 'POS Delivery');
        $this->assertContains(4, $ids, 'POS Uber');
        $this->assertContains(5, $ids, 'POS Glovo');
        $this->assertContains(6, $ids, 'POS Bolt Food');
        $this->assertNotContains(7, $ids, 'website delivery must stay out of POS');
        $this->assertNotContains(8, $ids, 'website take away must stay out of POS');
        $this->assertNotContains(9, $ids, 'other branch must stay out when branch is filtered');
        $this->assertNotContains(10, $ids, 'outside the date range must stay out');
        $this->assertCount(6, $ids);
        $this->assertSame(6, count(array_unique($ids)), 'POS rows must not be double-counted');
        $this->assertSame(750.0, $this->amountTotal($ids));
    }

    public function test_specific_channel_filters_still_use_sales_channel(): void
    {
        $today = Carbon::parse('2026-09-12 10:00:00');
        $this->insert(1, 'pos', 'delivery', 300, 1, $today);
        $this->insert(2, 'delivery', null, 999, 1, $today);
        $this->insert(3, 'pos', 'uber', 40, 1, $today);
        $this->insert(4, 'pos', 'takeaway', 200, 1, $today);
        $this->insert(5, 'dine_in', 'dine_in', 100, 1, $today);

        $from = $today->copy()->startOfDay();
        $to = $today->copy()->endOfDay();

        $this->assertSame([1], $this->filteredIds('delivery', 1, $from, $to));
        $this->assertSame([3], $this->filteredIds('uber', 1, $from, $to));
        $this->assertSame([4], $this->filteredIds('takeaway', 1, $from, $to));
        $this->assertSame([5], $this->filteredIds('dine_in', 1, $from, $to));
        $this->assertSame([1, 2, 3, 4, 5], $this->filteredIds('all', 1, $from, $to));
    }

    public function test_sale_report_controller_uses_the_pos_family_constraint(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/ReportController.php'));
        $this->assertStringContainsString('PosOrderTypes::constrainSaleReportChannel($query, $channel)', $controller);
        $this->assertStringContainsString('$this->saleReportOrderQuery($request, $fromDate, $toDate)', $controller);
        $this->assertStringContainsString("session()->put('export_sale_data', \$data)", $controller);
        $this->assertStringContainsString("session()->put('export_sale_summary', \$summaryDisplay)", $controller);
        $this->assertStringContainsString("session()->put('export_sale_report', \$exportReport)", $controller);
        $this->assertStringContainsString('AdminSaleReportExport::build(', $controller);
        $this->assertStringContainsString('AdminDashboardSalesKpis::constrainNotVoided($query)', $controller);
        $this->assertStringNotContainsString('earningReport()', $controller);
        $this->assertTrue(method_exists(PosOrderTypes::class, 'constrainSaleReportChannel'));
        $this->assertTrue(method_exists(Order::class, 'scopePos'));
        $this->assertTrue(method_exists(AdminDashboardSalesKpis::class, 'constrainNotVoided'));
    }

    public function test_cancelled_and_voided_pos_orders_are_excluded_from_sale_report(): void
    {
        $day = Carbon::parse('2026-09-12 12:00:00');
        $this->insert(1, 'pos', 'takeaway', 200, 1, $day, 'cash', 'delivered');
        $this->insert(2, 'pos', 'takeaway', 850, 1, $day, 'mpesa', 'canceled', $day, 'A10329');
        $this->insert(3, 'dine_in', 'dine_in', 400, 1, $day, 'cash', 'cancelled');
        $this->insert(4, 'pos', 'delivery', 500, 1, $day, 'cash', 'canceled', $day);
        $this->insert(5, 'pos', 'takeaway', 300, 1, $day, 'cash', 'confirmed', $day);
        $this->insert(6, 'dine_in', 'dine_in', 570, 1, $day, 'mpesa', 'confirmed');
        $this->insert(7, 'pos', 'delivery', 1000, 1, $day, 'mpesa', 'confirmed');
        $this->insert(8, 'pos', 'delivery', 750, 1, $day, 'paystack', 'confirmed');
        $this->insert(9, 'pos', 'takeaway', 90, 1, $day, 'cash', 'failed');
        $this->insert(10, 'pos', 'takeaway', 80, 1, $day, 'cash', 'returned');
        $this->insert(11, 'pos', 'takeaway', 70, 1, $day, 'cash', 'refunded');
        $this->insert(12, 'pos', 'glovo', 50, 1, $day, 'glovo', 'delivered');
        $this->insert(13, 'pos', 'uber', 40, 1, $day, 'uber', 'delivered');
        $this->insert(14, 'pos', 'bolt_food', 30, 1, $day, 'bolt_food', 'delivered');
        $this->insert(15, 'delivery', null, 999, 1, $day, 'cash', 'delivered');
        $this->insert(16, 'take_away', null, 888, 1, $day, 'cash', 'delivered');

        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();
        $ids = $this->filteredIds('pos', 1, $from, $to);

        $this->assertNotContains(2, $ids, 'Cancelled POS Takeaway');
        $this->assertNotContains(3, $ids, 'Cancelled POS Dine In');
        $this->assertNotContains(4, $ids, 'Cancelled POS Delivery');
        $this->assertNotContains(5, $ids, 'cancelled_at populated');
        $this->assertNotContains(9, $ids, 'failed');
        $this->assertNotContains(10, $ids, 'returned');
        $this->assertNotContains(11, $ids, 'refunded');
        $this->assertContains(1, $ids);
        $this->assertContains(6, $ids, 'Confirmed paid POS Dine In');
        $this->assertContains(7, $ids, 'Confirmed paid POS Delivery');
        $this->assertContains(8, $ids, 'Paid Paystack POS Delivery');
        $this->assertContains(12, $ids, 'Glovo stays in the POS family set');
        $this->assertContains(13, $ids);
        $this->assertContains(14, $ids);
        $this->assertNotContains(15, $ids, 'website delivery');
        $this->assertNotContains(16, $ids, 'website take away');

        $orders = Order::query()->whereIn('id', $ids)->orderBy('id')->get();
        $paymentTotals = ['cash' => 0.0, 'card' => 0.0, 'mpesa' => 0.0, 'paystack' => 0.0, 'glovo' => 0.0, 'uber' => 0.0, 'bolt_food' => 0.0];
        foreach ($orders as $order) {
            $method = (string) $order->payment_method;
            if (array_key_exists($method, $paymentTotals)) {
                $paymentTotals[$method] += (float) $order->order_amount;
            }
        }
        $report = AdminSaleReportExport::build($orders, [
            'branch_name' => 'Munch Bamburi',
            'from' => $from,
            'to' => $to,
            'payment_totals' => $paymentTotals,
        ]);

        $this->assertSame(2520.0, $report['totals']['munch_sales']);
        $this->assertSame(4, $report['order_counts']['munch_sales']);
        $this->assertSame(50.0, $report['totals']['glovo']);
        $this->assertSame(40.0, $report['totals']['uber']);
        $this->assertSame(30.0, $report['totals']['bolt_food']);
        $this->assertSame(750.0, $report['payment_totals']['paystack']);
        $this->assertArrayHasKey(8, $report['assigned_order_ids']);
        $this->assertSame('delivery', $report['assigned_order_ids'][8] ?? $report['assigned_order_ids']['8'] ?? null);
        $this->assertArrayNotHasKey(2, $report['assigned_order_ids']);
        $this->assertSame('glovo', AdminSaleReportExport::classify('pos', 'glovo'));
        $this->assertSame('uber', AdminSaleReportExport::classify('pos', 'uber'));
        $this->assertSame('bolt_food', AdminSaleReportExport::classify('pos', 'bolt_food'));

        $csv = AdminSaleReportExport::csvString($report);
        $this->assertStringNotContainsString('A10329', $csv);
        $this->assertStringContainsString('Paystack', $csv);
        $html = view('admin-views.report.partials._sale-report-export', compact('report'))->render();
        $this->assertStringNotContainsString('A10329', $html);
        $pdf = Pdf::loadView('admin-views.report.partials._sale-report-export', compact('report'))->output();
        $this->assertSame('%PDF', substr($pdf, 0, 4));
    }

    public function test_bamburi_12_september_excludes_cancelled_takeaway_a10329(): void
    {
        $day = Carbon::parse('2026-09-12 12:48:48');
        $remaining = 49620.0;
        for ($i = 1; $i <= 75; $i++) {
            $amount = ($i === 75) ? $remaining : 660.0;
            $remaining -= $amount;
            $this->insert($i, 'pos', 'takeaway', $amount, 10, $day, 'cash', 'delivered');
        }
        $this->insert(114739, 'pos', 'takeaway', 850, 10, $day, 'mpesa', 'canceled', $day, 'A10329');

        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();
        $ids = $this->filteredIds('pos', 10, $from, $to);

        $this->assertCount(75, $ids);
        $this->assertNotContains(114739, $ids);
        $this->assertSame(49620.0, $this->amountTotal($ids));

        $orders = Order::query()->whereIn('id', $ids)->orderBy('id')->get();
        $paymentTotals = ['cash' => 49620.0, 'card' => 0.0, 'mpesa' => 0.0, 'paystack' => 0.0, 'glovo' => 0.0, 'uber' => 0.0, 'bolt_food' => 0.0];
        $report = AdminSaleReportExport::build($orders, [
            'branch_name' => 'Munch Bamburi',
            'from' => $from,
            'to' => $to,
            'payment_totals' => $paymentTotals,
        ]);

        $this->assertSame(49620.0, $report['totals']['munch_sales']);
        $this->assertSame(75, $report['order_counts']['munch_sales']);
        $this->assertSame(49620.0, AdminSaleReportSummary::fromPaymentTotals($paymentTotals)['munch_sales']);
        $this->assertSame(49620.0, AdminDashboardSalesKpis::fromGroupedRows([
            ['payment_method' => 'cash', 'sales_channel' => 'takeaway', 'order_type' => 'pos', 'total' => 49620],
        ])['munch_sales']);

        $csv = AdminSaleReportExport::csvString($report);
        $html = view('admin-views.report.partials._sale-report-export', compact('report'))->render();
        $xlsxPath = sys_get_temp_dir().'/munch-bamburi-sale-report-voided.xlsx';
        AdminSaleReportExport::writeXlsx($xlsxPath, $report);
        $xlsx = (string) file_get_contents($xlsxPath);
        @unlink($xlsxPath);
        $this->assertStringNotContainsString('A10329', $csv);
        $this->assertStringNotContainsString('A10329', $html);
        $this->assertSame('PK', substr($xlsx, 0, 2));
        $this->assertStringNotContainsString('A10329', $xlsx);
    }

    /**
     * @return list<int>
     */
    private function filteredIds(string $channel, int|string $branchId, Carbon $from, Carbon $to): array
    {
        $query = Order::query()->whereBetween('created_at', [$from, $to]);
        if ($branchId !== 'all') {
            $query->where('branch_id', $branchId);
        }
        PosOrderTypes::constrainSaleReportChannel($query, $channel);
        AdminDashboardSalesKpis::constrainNotVoided($query);

        return $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $ids
     */
    private function amountTotal(array $ids): float
    {
        return (float) Order::query()->whereIn('id', $ids)->sum('order_amount');
    }

    private function insert(
        int $id,
        string $type,
        ?string $channel,
        float $amount,
        int $branchId,
        Carbon $createdAt,
        string $paymentMethod = 'cash',
        string $status = 'delivered',
        ?Carbon $cancelledAt = null,
        ?string $readable = null
    ): void {
        DB::table('orders')->insert([
            'id' => $id,
            'branch_id' => $branchId,
            'order_type' => $type,
            'sales_channel' => $channel,
            'payment_method' => $paymentMethod,
            'payment_status' => 'paid',
            'order_status' => $status,
            'readable_order_id' => $readable,
            'order_amount' => $amount,
            'cancelled_at' => $cancelledAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
