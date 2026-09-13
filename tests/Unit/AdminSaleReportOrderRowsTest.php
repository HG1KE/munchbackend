<?php

namespace Tests\Unit;

use App\Support\AdminSaleReportExport;
use App\Support\AdminSaleReportSummary;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use ZipArchive;

class AdminSaleReportOrderRowsTest extends TestCase
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

        Schema::dropIfExists('order_details');
        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('branch_id')->default(1);
            $table->string('order_type')->default('pos');
            $table->string('sales_channel')->nullable();
            $table->string('readable_order_id')->nullable();
            $table->string('platform_order_number')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('order_status')->default('delivered');
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('order_amount', 12, 2)->default(0);
            $table->decimal('extra_discount', 12, 2)->default(0);
            $table->decimal('coupon_discount_amount', 12, 2)->default(0);
            $table->decimal('referral_discount', 12, 2)->default(0);
            $table->decimal('total_tax_amount', 12, 2)->default(0);
            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('discount_on_product', 12, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->decimal('add_on_tax_amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('order_details');
        Schema::dropIfExists('orders');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_single_and_multi_line_orders_each_produce_one_listing_row(): void
    {
        $day = Carbon::parse('2026-09-13 12:15:00', 'Africa/Nairobi');
        $this->insertOrder(10, 'pos', 'takeaway', 3250, 1, $day, 'A10001');
        $this->insertDetail(10, 3250, 8);
        $this->insertOrder(114997, 'pos', 'glovo', 1440, 1, $day, 'A10587', '269');
        $this->insertDetail(114997, 490, 2);
        $this->insertDetail(114997, 100, 2);
        $this->insertDetail(114997, 100, 2);
        $this->insertDetail(114997, 60, 1);

        $orders = DB::table('orders')->orderBy('id')->get();
        $quantities = AdminSaleReportExport::quantitiesByOrderId([10, 114997]);
        $rows = AdminSaleReportExport::listingRows($orders, $quantities);

        $this->assertCount(2, $rows);
        $this->assertSame('10', $rows[0]['order_id']);
        $this->assertSame('A10001', $rows[0]['order_display_id']);
        $this->assertSame('', $rows[0]['platform_order_number']);
        $this->assertSame(8, $rows[0]['quantity']);
        $this->assertSame(3250.0, $rows[0]['price']);
        $this->assertSame('114997', $rows[1]['order_id']);
        $this->assertSame('A10587', $rows[1]['order_display_id']);
        $this->assertSame('269', $rows[1]['platform_order_number']);
        $this->assertSame('Glovo', $rows[1]['sales_channel_label']);
        $this->assertSame(7, $rows[1]['quantity']);
        $this->assertSame(1440.0, $rows[1]['price']);
        $this->assertSame(1, substr_count(implode(' ', array_column($rows, 'order_display_id')), 'A10587'));
    }

    public function test_a10587_glovo_order_is_one_export_row_with_qty_seven_and_order_amount(): void
    {
        $day = Carbon::parse('2026-09-13 12:15:00', 'Africa/Nairobi');
        $this->insertOrder(114997, 'pos', 'glovo', 1440, 1, $day, 'A10587', '269');
        $this->insertDetail(114997, 490, 2);
        $this->insertDetail(114997, 100, 2);
        $this->insertDetail(114997, 100, 2);
        $this->insertDetail(114997, 60, 1);

        $lineSold = 0.0;
        $lineQty = 0;
        foreach (DB::table('order_details')->where('order_id', 114997)->get() as $detail) {
            $lineSold += ((float) $detail->price - (float) $detail->discount_on_product) * (int) $detail->quantity;
            $lineQty += (int) $detail->quantity;
        }

        $order = [
            'id' => 114997,
            'order_type' => 'pos',
            'sales_channel' => 'glovo',
            'readable_order_id' => 'A10587',
            'platform_order_number' => '269',
            'order_amount' => 1440,
            'payment_method' => 'glovo',
            'order_status' => 'delivered',
            'created_at' => $day,
        ];
        $quantities = AdminSaleReportExport::quantitiesByOrderId([114997]);
        $listing = AdminSaleReportExport::listingRows([$order], $quantities);
        $report = AdminSaleReportExport::build([$order], [
            'branch_name' => 'Munch Bamburi',
            'from' => '2026-09-13',
            'to' => '2026-09-13',
            'payment_totals' => ['glovo' => 1440],
            'quantities' => $quantities,
        ]);

        $this->assertCount(1, $listing);
        $this->assertSame(7, $listing[0]['quantity']);
        $this->assertSame(1440.0, $listing[0]['price']);
        $this->assertSame(7, $lineQty);
        $this->assertSame(1440.0, $lineSold);
        $this->assertCount(1, $report['sections']['glovo']['orders']);
        $this->assertSame(1, $report['order_counts']['glovo']);
        $this->assertSame(1440.0, $report['totals']['glovo']);
        $this->assertSame(1440.0, $report['payment_totals']['glovo']);
        $this->assertSame(7, $report['sections']['glovo']['orders'][0]['quantity']);
        $this->assertSame(1440.0, $report['sections']['glovo']['orders'][0]['amount']);
        $this->assertSame('269', $report['sections']['glovo']['orders'][0]['platform_order_number']);
        $this->assertSame('A10587', $report['sections']['glovo']['orders'][0]['order_number']);

        $cells = AdminSaleReportExport::orderCells($report['sections']['glovo']['orders'][0], 'glovo');
        $this->assertSame(['12:15 PM', 'A10587', '269', 'Glovo', '7', AdminSaleReportExport::formatAmount(1440)], $cells);

        $csv = AdminSaleReportExport::csvString($report);
        $html = view('admin-views.report.partials._sale-report-export', compact('report'))->render();
        $xlsxPath = sys_get_temp_dir().'/munch-sale-report-a10587.xlsx';
        AdminSaleReportExport::writeXlsx($xlsxPath, $report);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($xlsxPath) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($xlsxPath);

        foreach ([$csv, $html, $sheet] as $output) {
            $this->assertSame(1, substr_count($output, 'A10587'), $output);
            $this->assertStringContainsString('269', $output);
            $this->assertStringNotContainsString('Marketplace Order #', $output);
        }
        $this->assertStringContainsString(',7,', $csv);
        $pdf = Pdf::loadView('admin-views.report.partials._sale-report-export', compact('report'))->output();
        $this->assertSame('%PDF', substr($pdf, 0, 4));
    }

    public function test_marketplace_and_cancelled_multi_line_orders_stay_one_row_each(): void
    {
        $day = Carbon::parse('2026-09-13 10:00:00', 'Africa/Nairobi');
        $this->insertOrder(1, 'pos', 'takeaway', 3250, 1, $day, 'A10001');
        $this->insertDetail(1, 400, 4);
        $this->insertDetail(1, 450, 4);
        $this->insertOrder(2, 'pos', 'uber', 2100, 1, $day, 'A10002', 'UBER-123');
        $this->insertDetail(2, 300, 3);
        $this->insertDetail(2, 600, 2);
        $this->insertOrder(3, 'pos', 'bolt_food', 1850, 1, $day, 'A10003', 'BOLT-456');
        $this->insertDetail(3, 200, 3);
        $this->insertDetail(3, 250, 3);
        $this->insertOrder(114739, 'pos', 'takeaway', 850, 1, $day, 'A10329', '', 'canceled', $day);
        $this->insertDetail(114739, 200, 2);
        $this->insertDetail(114739, 450, 1);

        $valid = DB::table('orders')->whereIn('id', [1, 2, 3])->orderBy('id')->get();
        $cancelled = DB::table('orders')->where('id', 114739)->get();
        $quantities = AdminSaleReportExport::quantitiesByOrderId([1, 2, 3, 114739]);
        $listing = AdminSaleReportExport::listingRows($valid, $quantities);
        $payments = ['cash' => 3250, 'uber' => 2100, 'bolt_food' => 1850, 'mpesa' => 0];
        $report = AdminSaleReportExport::build($valid, [
            'branch_name' => 'Munch Bamburi',
            'from' => $day,
            'to' => $day,
            'payment_totals' => $payments,
            'cancelled_orders' => $cancelled,
            'quantities' => $quantities,
        ]);

        $this->assertCount(3, $listing);
        $this->assertSame(['1', '2', '3'], array_column($listing, 'order_id'));
        $this->assertSame([8, 5, 6], array_column($listing, 'quantity'));
        $this->assertSame([3250.0, 2100.0, 1850.0], array_column($listing, 'price'));
        $this->assertCount(1, $report['sections']['munch_sales']['orders']);
        $this->assertCount(1, $report['sections']['uber']['orders']);
        $this->assertCount(1, $report['sections']['bolt_food']['orders']);
        $this->assertCount(1, $report['sections']['cancelled']['orders']);
        $this->assertSame(1, $report['order_counts']['munch_sales']);
        $this->assertSame(1, $report['order_counts']['uber']);
        $this->assertSame(1, $report['order_counts']['bolt_food']);
        $this->assertSame(1, $report['cancelled']['order_count']);
        $this->assertSame(3250.0, $report['totals']['munch_sales']);
        $this->assertSame(2100.0, $report['totals']['uber']);
        $this->assertSame(1850.0, $report['totals']['bolt_food']);
        $this->assertSame(850.0, $report['cancelled']['total']);
        $this->assertSame(0.0, $report['payment_totals']['mpesa']);
        $this->assertSame(3250.0, $report['payment_totals']['cash']);
        $this->assertNotContains('114739', array_map('strval', array_keys($report['assigned_order_ids'])));
        $this->assertSame(8, $report['sections']['munch_sales']['orders'][0]['quantity']);
        $this->assertSame(5, $report['sections']['uber']['orders'][0]['quantity']);
        $this->assertSame(6, $report['sections']['bolt_food']['orders'][0]['quantity']);
        $this->assertSame(3, $report['sections']['cancelled']['orders'][0]['quantity']);
        $this->assertSame('UBER-123', $report['sections']['uber']['orders'][0]['platform_order_number']);
        $this->assertSame('BOLT-456', $report['sections']['bolt_food']['orders'][0]['platform_order_number']);
        $this->assertSame(850.0, $report['sections']['cancelled']['orders'][0]['amount']);
        $this->assertArrayNotHasKey('total_sales', $report['totals']);
        $this->assertSame(3250.0, AdminSaleReportSummary::fromPaymentTotals($payments)['munch_sales']);
    }

    public function test_displayed_amount_uses_order_total_while_line_kpi_total_stays_the_same(): void
    {
        $day = Carbon::parse('2026-09-13 09:00:00', 'Africa/Nairobi');
        $this->insertOrder(50, 'pos', 'takeaway', 1440, 1, $day, 'A15000');
        $this->insertDetail(50, 500, 2);
        $this->insertDetail(50, 250, 2);

        $details = DB::table('order_details')->where('order_id', 50)->get();
        $totalSold = 0.0;
        $qty = 0;
        foreach ($details as $detail) {
            $price = $detail->price - $detail->discount_on_product;
            $totalSold += $price * $detail->quantity;
            $qty += (int) $detail->quantity;
        }

        $order = DB::table('orders')->where('id', 50)->first();
        $quantities = AdminSaleReportExport::quantitiesByOrderId([50]);
        $listing = AdminSaleReportExport::listingRows([$order], $quantities);
        $report = AdminSaleReportExport::build([$order], [
            'branch_name' => 'Munch Bamburi',
            'from' => $day,
            'to' => $day,
            'payment_totals' => ['cash' => 1440],
            'quantities' => $quantities,
        ]);
        $summary = AdminSaleReportSummary::fromParts([
            'gross' => 1500,
            'item_discount' => 0,
            'total_sales' => $totalSold,
        ]);

        $this->assertSame(1500.0, $totalSold);
        $this->assertSame(4, $qty);
        $this->assertSame(1440.0, $listing[0]['price']);
        $this->assertSame(4, $listing[0]['quantity']);
        $this->assertSame(1440.0, $report['totals']['munch_sales']);
        $this->assertSame(1500.0, $summary['total_sales']);
        $this->assertSame(0.0, $summary['total_discounts']);
        $this->assertNotSame($listing[0]['price'], $summary['total_sales']);
    }

    public function test_branch_and_date_filters_still_limit_which_orders_are_listed(): void
    {
        $today = Carbon::parse('2026-09-13 12:00:00', 'Africa/Nairobi');
        $yesterday = Carbon::parse('2026-09-12 12:00:00', 'Africa/Nairobi');
        $this->insertOrder(1, 'pos', 'takeaway', 100, 1, $today, 'A1');
        $this->insertDetail(1, 100, 1);
        $this->insertOrder(2, 'pos', 'takeaway', 200, 2, $today, 'A2');
        $this->insertDetail(2, 200, 2);
        $this->insertOrder(3, 'pos', 'takeaway', 300, 1, $yesterday, 'A3');
        $this->insertDetail(3, 300, 3);

        $filtered = DB::table('orders')
            ->where('branch_id', 1)
            ->whereBetween('created_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->get();
        $rows = AdminSaleReportExport::listingRows($filtered, AdminSaleReportExport::quantitiesByOrderId([1, 2, 3]));

        $this->assertCount(1, $rows);
        $this->assertSame('A1', $rows[0]['order_display_id']);
        $this->assertSame(1, $rows[0]['quantity']);
        $this->assertNotContains('A2', array_column($rows, 'order_display_id'));
        $this->assertNotContains('A3', array_column($rows, 'order_display_id'));
    }

    public function test_listing_rows_remain_searchable_by_order_and_platform_number(): void
    {
        $day = Carbon::parse('2026-09-13 12:15:00', 'Africa/Nairobi');
        $this->insertOrder(114997, 'pos', 'glovo', 1440, 1, $day, 'A10587', '269');
        $this->insertDetail(114997, 490, 2);
        $this->insertDetail(114997, 100, 2);
        $this->insertDetail(114997, 100, 2);
        $this->insertDetail(114997, 60, 1);

        $row = AdminSaleReportExport::listingRows(
            DB::table('orders')->where('id', 114997)->get(),
            AdminSaleReportExport::quantitiesByOrderId([114997])
        )[0];
        $haystack = implode(' ', [
            $row['order_display_id'],
            $row['sales_channel_label'],
            $row['platform_order_number'],
            (string) $row['quantity'],
            (string) $row['price'],
        ]);

        $this->assertStringContainsString('A10587', $haystack);
        $this->assertStringContainsString('Glovo', $haystack);
        $this->assertStringContainsString('269', $haystack);
        $this->assertStringContainsString('7', $haystack);
        $this->assertStringContainsString('1440', $haystack);
    }

    public function test_duplicate_order_input_does_not_create_a_second_listing_row(): void
    {
        $order = [
            'id' => 114997,
            'order_type' => 'pos',
            'sales_channel' => 'glovo',
            'readable_order_id' => 'A10587',
            'platform_order_number' => '269',
            'order_amount' => 1440,
            'quantity' => 7,
            'created_at' => Carbon::parse('2026-09-13 12:15:00', 'Africa/Nairobi'),
        ];

        $rows = AdminSaleReportExport::listingRows([$order, $order], ['114997' => 7]);

        $this->assertCount(1, $rows);
        $this->assertSame(7, $rows[0]['quantity']);
        $this->assertSame(1440.0, $rows[0]['price']);
    }

    private function insertOrder(
        int $id,
        string $type,
        ?string $channel,
        float $amount,
        int $branchId,
        Carbon $createdAt,
        string $readableId,
        string $platform = '',
        string $status = 'delivered',
        ?Carbon $cancelledAt = null
    ): void {
        DB::table('orders')->insert([
            'id' => $id,
            'branch_id' => $branchId,
            'order_type' => $type,
            'sales_channel' => $channel,
            'readable_order_id' => $readableId,
            'platform_order_number' => $platform,
            'payment_method' => $channel === 'glovo' ? 'glovo' : ($channel === 'uber' ? 'uber' : ($channel === 'bolt_food' ? 'bolt_food' : 'cash')),
            'order_status' => $status,
            'order_amount' => $amount,
            'cancelled_at' => $cancelledAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function insertDetail(int $orderId, float $price, int $quantity): void
    {
        DB::table('order_details')->insert([
            'order_id' => $orderId,
            'price' => $price,
            'discount_on_product' => 0,
            'quantity' => $quantity,
            'add_on_tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
