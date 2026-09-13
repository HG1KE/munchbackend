<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Support\AdminDashboardSalesKpis;
use App\Support\AdminSaleReportExport;
use App\Support\PosOrderTypes;
use App\Support\TimezoneDisplay;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use ZipArchive;

class AdminSaleReportExportTest extends TestCase
{
    private string $previousConnection = 'mysql';

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousConnection = (string) config('database.default');
        TimezoneDisplay::resetCache();
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
            $table->string('readable_order_id')->nullable();
            $table->string('platform_order_number')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('order_status')->default('delivered');
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('order_amount', 12, 2)->default(0);
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

    public function test_export_classifies_pos_family_and_excludes_website_orders(): void
    {
        $report = $this->sampleReport();

        $munchIds = $this->idsIn($report, 'munch_sales');
        $glovoIds = $this->idsIn($report, 'glovo');
        $uberIds = $this->idsIn($report, 'uber');
        $boltIds = $this->idsIn($report, 'bolt_food');

        $this->assertSame(['1'], $this->idsInCategory($report, 'munch_sales', 'dine_in'));
        $this->assertSame(['2'], $this->idsInCategory($report, 'munch_sales', 'takeaway'));
        $this->assertSame(['3'], $this->idsInCategory($report, 'munch_sales', 'delivery'));
        $this->assertSame(['4'], $glovoIds);
        $this->assertSame(['5'], $uberIds);
        $this->assertSame(['6'], $boltIds);
        $this->assertNotContains('7', $munchIds, 'website delivery must stay out of Munch Sales');
        $this->assertNotContains('8', $munchIds, 'website take away must stay out of Munch Sales');
        $this->assertNotContains('7', array_merge($glovoIds, $uberIds, $boltIds));
        $this->assertNotContains('8', array_merge($glovoIds, $uberIds, $boltIds));
        $this->assertSame(['1', '2', '3'], $munchIds);
        $this->assertSame(PosOrderTypes::saleReportCategory('dine_in', 'dine_in'), AdminSaleReportExport::classify('dine_in', 'dine_in'));
        $this->assertSame(PosOrderTypes::saleReportCategory('pos', 'glovo'), AdminSaleReportExport::classify('pos', 'glovo'));
        $this->assertNull(AdminSaleReportExport::classify('delivery', null));
    }

    public function test_no_order_appears_in_more_than_one_section_and_each_section_has_its_own_total(): void
    {
        $report = $this->sampleReport();
        $assigned = $report['assigned_order_ids'];

        $this->assertSame(6, count($assigned));
        $this->assertSame(6, count(array_unique(array_keys($assigned))));
        $this->assertSame(3550.0, $report['totals']['munch_sales']);
        $this->assertSame(1100.0, $report['totals']['glovo']);
        $this->assertSame(950.0, $report['totals']['uber']);
        $this->assertSame(1300.0, $report['totals']['bolt_food']);
        $this->assertArrayNotHasKey('total_sales', $report['totals']);
        $this->assertArrayNotHasKey('cancelled', $report['totals']);
        $this->assertSame(0.0, $report['cancelled']['total']);
        $this->assertSame(0, $report['cancelled']['order_count']);
        $this->assertSame($report['sections']['munch_sales']['total'], $report['totals']['munch_sales']);
        $this->assertSame($report['sections']['glovo']['total'], $report['totals']['glovo']);
        $this->assertSame($report['sections']['uber']['total'], $report['totals']['uber']);
        $this->assertSame($report['sections']['bolt_food']['total'], $report['totals']['bolt_food']);
        $this->assertSame(3, $report['order_counts']['munch_sales']);
        $this->assertSame(1, $report['order_counts']['glovo']);
        $this->assertSame(1, $report['order_counts']['uber']);
        $this->assertSame(1, $report['order_counts']['bolt_food']);
        $this->assertSame(count($report['sections']['munch_sales']['orders']), $report['order_counts']['munch_sales']);
        $this->assertSame(count($report['sections']['glovo']['orders']), $report['order_counts']['glovo']);
        $this->assertSame(count($report['sections']['uber']['orders']), $report['order_counts']['uber']);
        $this->assertSame(count($report['sections']['bolt_food']['orders']), $report['order_counts']['bolt_food']);
        $this->assertArrayNotHasKey('total_orders', $report);
    }

    public function test_duplicate_input_orders_are_not_double_counted(): void
    {
        $order = $this->order([
            'id' => 1,
            'order_type' => 'dine_in',
            'sales_channel' => 'dine_in',
            'order_amount' => 100,
            'readable_order_id' => 'A10123',
        ]);

        $report = AdminSaleReportExport::build([$order, $order], [
            'branch_name' => 'Munch Bamburi',
            'from' => '2026-09-11',
            'to' => '2026-09-11',
        ]);

        $this->assertCount(1, $report['sections']['munch_sales']['categories']['dine_in']['orders']);
        $this->assertCount(1, $report['sections']['munch_sales']['orders']);
        $this->assertSame(100.0, $report['totals']['munch_sales']);
        $this->assertSame(1, $report['order_counts']['munch_sales']);
    }

    public function test_section_order_counts_use_the_same_filtered_orders_as_sales_totals(): void
    {
        $report = AdminSaleReportExport::build([
            $this->order([
                'id' => 1,
                'order_type' => 'dine_in',
                'sales_channel' => 'dine_in',
                'order_amount' => 100,
                'readable_order_id' => 'A10123',
            ]),
            $this->order([
                'id' => 1,
                'order_type' => 'dine_in',
                'sales_channel' => 'dine_in',
                'order_amount' => 40,
                'readable_order_id' => 'A10123',
            ]),
            $this->order([
                'id' => 2,
                'order_type' => 'pos',
                'sales_channel' => 'takeaway',
                'order_amount' => 80,
                'readable_order_id' => 'A10124',
            ]),
            $this->order([
                'id' => 3,
                'order_type' => 'pos',
                'sales_channel' => 'glovo',
                'order_amount' => 50,
                'readable_order_id' => 'A10126',
                'platform_order_number' => 'GLV-1',
            ]),
            $this->order([
                'id' => 4,
                'order_type' => 'delivery',
                'sales_channel' => null,
                'order_amount' => 999,
                'readable_order_id' => 'W10001',
            ]),
            $this->order([
                'id' => 5,
                'order_type' => 'pos',
                'sales_channel' => 'uber',
                'order_amount' => 70,
                'readable_order_id' => 'A10127',
                'platform_order_number' => 'UBER-1',
            ]),
            $this->order([
                'id' => 6,
                'order_type' => 'pos',
                'sales_channel' => 'bolt_food',
                'order_amount' => 90,
                'readable_order_id' => 'A10128',
                'platform_order_number' => 'BOLT-1',
            ]),
        ], [
            'branch_name' => 'Munch Bamburi',
            'from' => '2026-09-11',
            'to' => '2026-09-11',
        ]);

        $this->assertSame(2, $report['order_counts']['munch_sales']);
        $this->assertSame(1, $report['order_counts']['glovo']);
        $this->assertSame(1, $report['order_counts']['uber']);
        $this->assertSame(1, $report['order_counts']['bolt_food']);
        $this->assertSame(180.0, $report['totals']['munch_sales']);
        $this->assertSame(50.0, $report['totals']['glovo']);
        $this->assertSame(70.0, $report['totals']['uber']);
        $this->assertSame(90.0, $report['totals']['bolt_food']);
        $this->assertSame(
            $report['order_counts']['munch_sales'],
            count($report['sections']['munch_sales']['orders'])
        );
        $this->assertNotContains('4', array_keys($report['assigned_order_ids']));
        $this->assertSame(0, $report['sections']['munch_sales']['order_count'] - count($this->idsIn($report, 'munch_sales')));
        $this->assertSame(['1', '2'], $this->idsIn($report, 'munch_sales'));
        $this->assertSame(AdminSaleReportExport::formatOrderCount(2), 'Total Orders: 2');

        $csv = AdminSaleReportExport::csvString($report);
        $this->assertStringContainsString('Total Orders: 2', $csv);
        $this->assertStringContainsString('Total Orders: 1', $csv);
        $this->assertStringNotContainsString('Total Orders: 6', $csv);
        $this->assertStringNotContainsString('Total Orders: 5', $csv);
    }

    public function test_single_day_rows_show_time_only_and_marketplace_numbers_stay_in_payload(): void
    {
        $created = Carbon::parse('2026-09-11 10:42:00', 'Africa/Nairobi');
        $report = AdminSaleReportExport::build([
            $this->order([
                'id' => 1,
                'order_type' => 'dine_in',
                'sales_channel' => 'dine_in',
                'order_amount' => 1250,
                'readable_order_id' => 'A10123',
                'created_at' => $created,
            ]),
            $this->order([
                'id' => 4,
                'order_type' => 'pos',
                'sales_channel' => 'glovo',
                'order_amount' => 1100,
                'readable_order_id' => 'A10126',
                'platform_order_number' => 'glv-12345',
                'created_at' => Carbon::parse('2026-09-11 14:31:00', 'Africa/Nairobi'),
            ]),
        ], [
            'branch_name' => 'Munch Bamburi',
            'from' => '2026-09-11',
            'to' => '2026-09-11',
        ]);

        $dineIn = $report['sections']['munch_sales']['categories']['dine_in']['orders'][0];
        $glovo = $report['sections']['glovo']['categories']['glovo']['orders'][0];

        $this->assertTrue($report['is_single_day']);
        $this->assertSame('10:42 AM', $dineIn['time']);
        $this->assertSame('10:42 AM', $dineIn['timestamp']);
        $this->assertStringNotContainsString('Sep', $dineIn['time']);
        $this->assertStringNotContainsString('2026', $dineIn['time']);
        $this->assertSame('A10123', $dineIn['order_number']);
        $this->assertSame('Munch Sales', $dineIn['sales_category']);
        $this->assertSame('Dine In', $dineIn['order_type']);
        $this->assertSame('', $dineIn['platform_order_number']);
        $this->assertSame('2:31 PM', $glovo['time']);
        $this->assertSame('A10126', $glovo['order_number']);
        $this->assertSame('GLV-12345', $glovo['platform_order_number']);
        $this->assertSame('Glovo', $glovo['sales_category']);
        $this->assertSame('Glovo', $glovo['order_type']);
    }

    public function test_multi_day_order_rows_keep_the_date_so_they_are_not_ambiguous(): void
    {
        $report = AdminSaleReportExport::build([
            $this->order([
                'id' => 1,
                'order_type' => 'dine_in',
                'sales_channel' => 'dine_in',
                'order_amount' => 100,
                'readable_order_id' => 'A10123',
                'created_at' => Carbon::parse('2026-09-11 10:42:00', 'Africa/Nairobi'),
            ]),
            $this->order([
                'id' => 2,
                'order_type' => 'pos',
                'sales_channel' => 'takeaway',
                'order_amount' => 80,
                'readable_order_id' => 'A10124',
                'created_at' => Carbon::parse('2026-09-12 09:15:00', 'Africa/Nairobi'),
            ]),
        ], [
            'branch_name' => 'Munch Bamburi',
            'from' => '2026-09-11',
            'to' => '2026-09-12',
        ]);

        $first = $report['sections']['munch_sales']['orders'][0];
        $second = $report['sections']['munch_sales']['orders'][1];

        $this->assertFalse($report['is_single_day']);
        $this->assertSame('11th September 2026 to 12th September 2026', $report['sales_date_label']);
        $this->assertSame('11 Sep 2026 10:42 AM', $first['time']);
        $this->assertSame('12 Sep 2026 9:15 AM', $second['time']);
    }

    public function test_section_columns_are_specific_and_never_use_a_generic_marketplace_heading(): void
    {
        $this->assertSame(
            ['Time', 'Munch Order #', 'Type', 'Qty', 'Amount'],
            AdminSaleReportExport::sectionColumnLabels('munch_sales')
        );
        $this->assertSame(
            ['Time', 'Munch Order #', 'Glovo Order #', 'Type', 'Qty', 'Amount'],
            AdminSaleReportExport::sectionColumnLabels('glovo')
        );
        $this->assertSame(
            ['Time', 'Munch Order #', 'Uber Order #', 'Type', 'Qty', 'Amount'],
            AdminSaleReportExport::sectionColumnLabels('uber')
        );
        $this->assertSame(
            ['Time', 'Munch Order #', 'Bolt Food Order #', 'Type', 'Qty', 'Amount'],
            AdminSaleReportExport::sectionColumnLabels('bolt_food')
        );
        $this->assertSame('', AdminSaleReportExport::marketplaceOrderColumn('munch_sales'));
        $this->assertSame('Glovo Order #', AdminSaleReportExport::marketplaceOrderColumn('glovo'));
        $this->assertSame('Uber Order #', AdminSaleReportExport::marketplaceOrderColumn('uber'));
        $this->assertSame('Bolt Food Order #', AdminSaleReportExport::marketplaceOrderColumn('bolt_food'));
        $this->assertSame(
            ['Time', 'Munch Order #', 'Type', 'Payment Method', 'Status', 'Qty', 'Amount'],
            AdminSaleReportExport::sectionColumnLabels(AdminSaleReportExport::CANCELLED_SECTION)
        );
        $this->assertSame('', AdminSaleReportExport::marketplaceOrderColumn(AdminSaleReportExport::CANCELLED_SECTION));
        $this->assertNotContains('Marketplace Order #', AdminSaleReportExport::sectionColumnLabels(AdminSaleReportExport::CANCELLED_SECTION));
    }

    public function test_branch_and_sales_date_appear_in_every_export_format(): void
    {
        $report = $this->sampleReport();
        $csv = AdminSaleReportExport::csvString($report);
        $rows = AdminSaleReportExport::flattenExport($report);
        $pdf = file_get_contents(resource_path('views/admin-views/report/partials/_sale-report-export.blade.php'));

        $this->assertSame('Munch Bamburi', $report['branch_name']);
        $this->assertSame('11th September 2026', $report['sales_date_label']);
        $this->assertTrue($report['is_single_day']);
        $this->assertStringContainsString('MUNCH', $csv);
        $this->assertStringContainsString('Branch: Munch Bamburi', $csv);
        $this->assertStringContainsString('Sales Date: 11th September 2026', $csv);
        $this->assertSame(['MUNCH'], $rows[0]['cells']);
        $this->assertSame(['Branch: Munch Bamburi'], $rows[1]['cells']);
        $this->assertSame(['Sales Date: 11th September 2026'], $rows[2]['cells']);
        $this->assertStringContainsString('Branch:', $pdf);
        $this->assertStringContainsString('Sales Date:', $pdf);
        $this->assertStringContainsString('MUNCH SALES', $csv);
        $this->assertStringContainsString('A10126', $csv);
        $this->assertStringContainsString('GLV-12345', $csv);
        $this->assertStringNotContainsString('MARKETPLACE SALES', $csv);
        $this->assertStringNotContainsString('Marketplace Order #', $csv);
        $this->assertStringNotContainsString('TOTAL SALES', $csv);
    }

    public function test_csv_and_excel_keep_separate_sections_and_order_number_columns(): void
    {
        $report = $this->sampleReport();
        $rows = AdminSaleReportExport::flattenExport($report);
        $csv = AdminSaleReportExport::csvString($report);
        $headings = $this->cellsByType($rows, 'section');
        $columns = $this->rowsByType($rows, 'columns');
        $totals = $this->cellsByType($rows, 'total');

        $this->assertSame(['MUNCH SALES', 'GLOVO', 'UBER', 'BOLT FOOD', 'CANCELLED'], $headings);
        $this->assertSame(['Time', 'Munch Order #', 'Type', 'Qty', 'Amount'], $columns['munch_sales']);
        $this->assertSame(['Time', 'Munch Order #', 'Glovo Order #', 'Type', 'Qty', 'Amount'], $columns['glovo']);
        $this->assertSame(['Time', 'Munch Order #', 'Uber Order #', 'Type', 'Qty', 'Amount'], $columns['uber']);
        $this->assertSame(['Time', 'Munch Order #', 'Bolt Food Order #', 'Type', 'Qty', 'Amount'], $columns['bolt_food']);
        $this->assertSame(
            ['Time', 'Munch Order #', 'Type', 'Payment Method', 'Status', 'Qty', 'Amount'],
            $columns['cancelled']
        );
        $this->assertNotContains('Marketplace Order #', $columns['munch_sales']);
        $this->assertNotContains('Timestamp', $columns['munch_sales']);
        $this->assertContains('Munch Sales Total', $totals);
        $this->assertContains('Glovo Total', $totals);
        $this->assertContains('Uber Total', $totals);
        $this->assertContains('Bolt Food Total', $totals);
        $this->assertContains('Cancelled Total', $totals);
        $this->assertContains('Cancelled Orders: 0', $totals);
        $this->assertContains('Total Orders: 3', $totals);
        $this->assertContains('Total Orders: 1', $totals);
        $this->assertNotContains('TOTAL SALES', $totals);
        $this->assertSame(
            ['Total Orders: 3', '', '', 'Munch Sales Total', AdminSaleReportExport::formatAmount(3550)],
            $this->rowsByType($rows, 'total')['munch_sales']
        );
        $this->assertSame(
            ['Total Orders: 1', '', '', '', 'Glovo Total', AdminSaleReportExport::formatAmount(1100)],
            $this->rowsByType($rows, 'total')['glovo']
        );
        $this->assertStringContainsString('Time', $csv);
        $this->assertStringContainsString('Munch Order #', $csv);
        $this->assertStringContainsString('Glovo Order #', $csv);
        $this->assertStringContainsString('Uber Order #', $csv);
        $this->assertStringContainsString('Bolt Food Order #', $csv);
        $this->assertStringContainsString('10:42 AM', $csv);
        $this->assertStringNotContainsString('11 Sep 2026 10:42 AM', $csv);
        $this->assertStringNotContainsString('Timestamp', $csv);
        $this->assertStringContainsString('Munch Sales Total', $csv);
        $this->assertStringContainsString('Total Orders: 3', $csv);
        $this->assertStringContainsString('Total Orders: 1', $csv);
        $this->assertStringNotContainsString('TOTAL SALES', $csv);
        $this->assertSame(AdminSaleReportExport::FORMAT_XLSX, AdminSaleReportExport::normalizeFormat('excel'));
        $this->assertSame(AdminSaleReportExport::FORMAT_CSV, AdminSaleReportExport::normalizeFormat('csv'));
        $this->assertSame(AdminSaleReportExport::FORMAT_PDF, AdminSaleReportExport::normalizeFormat('pdf'));

        $munchOrderRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['type'] === 'order' && $row['section'] === 'munch_sales'
        ));
        $this->assertCount(3, $munchOrderRows);
        $this->assertSame(['10:42 AM', 'A10123', 'Dine In', '0', AdminSaleReportExport::formatAmount(1250)], $munchOrderRows[0]['cells']);
        $this->assertCount(5, $munchOrderRows[0]['cells']);

        $glovoOrderRows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => $row['type'] === 'order' && $row['section'] === 'glovo'
        ));
        $this->assertSame(['2:31 PM', 'A10126', 'GLV-12345', 'Glovo', '0', AdminSaleReportExport::formatAmount(1100)], $glovoOrderRows[0]['cells']);
    }

    public function test_multi_day_filename_and_single_day_filename_use_branch_and_date(): void
    {
        $single = $this->sampleReport();
        $range = AdminSaleReportExport::build($this->sampleOrders(), [
            'branch_name' => 'Munch Bamburi',
            'from' => '2026-09-01',
            'to' => '2026-09-11',
        ]);

        $this->assertSame(
            'Munch Bamburi Sales 11th September 2026.pdf',
            AdminSaleReportExport::filename($single, 'pdf')
        );
        $this->assertSame(
            'Munch Bamburi Sales 11th September 2026.csv',
            AdminSaleReportExport::filename($single, 'csv')
        );
        $this->assertSame(
            'Munch Bamburi Sales 11th September 2026.xlsx',
            AdminSaleReportExport::filename($single, 'xlsx')
        );
        $this->assertSame(
            'Munch Bamburi Sales 1st September 2026 to 11th September 2026.xlsx',
            AdminSaleReportExport::filename($range, 'excel')
        );
        $this->assertSame(
            'Munch Bamburi Sales 11th September 2026.pdf',
            AdminSaleReportExport::filename($single, 'print')
        );
        $this->assertSame(
            'Munch All Branches Sales 11th September 2026.csv',
            AdminSaleReportExport::filename(
                AdminSaleReportExport::build([], [
                    'branch_name' => 'All Branches',
                    'from' => '2026-09-11',
                    'to' => '2026-09-11',
                ]),
                'csv'
            )
        );
        $this->assertSame(
            'Munch Bamburi West Sales 11th September 2026.pdf',
            AdminSaleReportExport::filename(
                AdminSaleReportExport::build([], [
                    'branch_name' => 'Munch Bamburi/West',
                    'from' => '2026-09-11',
                    'to' => '2026-09-11',
                ]),
                'pdf'
            )
        );
    }

    public function test_payment_totals_are_passed_through_unchanged(): void
    {
        $payments = [
            'cash' => 100,
            'card' => 200,
            'mpesa' => 50,
            'paystack' => 5000,
            'glovo' => 80,
            'uber' => 40,
            'bolt_food' => 30,
        ];
        $report = AdminSaleReportExport::build($this->sampleOrders(), [
            'branch_name' => 'Munch Bamburi',
            'from' => '2026-09-11',
            'to' => '2026-09-11',
            'payment_totals' => $payments,
        ]);

        $this->assertSame(100.0, $report['payment_totals']['cash']);
        $this->assertSame(200.0, $report['payment_totals']['card']);
        $this->assertSame(50.0, $report['payment_totals']['mpesa']);
        $this->assertSame(5000.0, $report['payment_totals']['paystack']);
        $this->assertSame(80.0, $report['payment_totals']['glovo']);
        $this->assertSame(40.0, $report['payment_totals']['uber']);
        $this->assertSame(30.0, $report['payment_totals']['bolt_food']);
        $this->assertNotEquals($report['totals']['munch_sales'], $report['payment_totals']['paystack']);
        $this->assertStringContainsString('Paystack', AdminSaleReportExport::csvString($report));
    }

    public function test_existing_branch_and_date_filters_still_feed_the_export(): void
    {
        $today = Carbon::parse('2026-09-11 10:00:00');
        $yesterday = Carbon::parse('2026-09-10 10:00:00');

        $this->insert(1, 'dine_in', 'dine_in', 100, 1, $today, 'A10123');
        $this->insert(2, 'pos', 'takeaway', 200, 1, $today, 'A10124');
        $this->insert(3, 'pos', 'delivery', 300, 1, $today, 'A10125');
        $this->insert(4, 'pos', 'glovo', 50, 1, $today, 'A10126', 'GLV-1');
        $this->insert(5, 'delivery', null, 999, 1, $today, 'W1');
        $this->insert(6, 'take_away', null, 888, 1, $today, 'W2');
        $this->insert(7, 'pos', 'takeaway', 15, 2, $today, 'A20124');
        $this->insert(8, 'pos', 'takeaway', 25, 1, $yesterday, 'A10100');
        $this->insert(9, 'pos', 'takeaway', 850, 1, $today, 'A10329', '', 'canceled', $today);

        $from = $today->copy()->startOfDay();
        $to = $today->copy()->endOfDay();
        $query = Order::query()->whereBetween('created_at', [$from, $to])->where('branch_id', 1);
        PosOrderTypes::constrainSaleReportChannel($query, 'pos');
        AdminDashboardSalesKpis::constrainNotVoided($query);
        $filtered = $query->orderBy('created_at')->get();

        $report = AdminSaleReportExport::build($filtered, [
            'branch_name' => 'Munch Bamburi',
            'from' => $from,
            'to' => $to,
            'payment_totals' => ['cash' => 650],
        ]);

        $this->assertEqualsCanonicalizing(['1', '2', '3', '4'], array_keys($report['assigned_order_ids']));
        $this->assertSame(600.0, $report['totals']['munch_sales']);
        $this->assertSame(50.0, $report['totals']['glovo']);
        $this->assertSame(0.0, $report['totals']['uber']);
        $this->assertSame(0.0, $report['totals']['bolt_food']);
        $this->assertSame(3, $report['order_counts']['munch_sales']);
        $this->assertSame(1, $report['order_counts']['glovo']);
        $this->assertSame(0, $report['order_counts']['uber']);
        $this->assertSame(0, $report['order_counts']['bolt_food']);
        $this->assertSame(650.0, $report['payment_totals']['cash']);
        $this->assertNotContains('5', array_keys($report['assigned_order_ids']));
        $this->assertNotContains('6', array_keys($report['assigned_order_ids']));
        $this->assertNotContains('7', array_keys($report['assigned_order_ids']));
        $this->assertNotContains('8', array_keys($report['assigned_order_ids']));
        $this->assertNotContains('9', array_keys($report['assigned_order_ids']));

        $cancelled = Order::query()->where('id', 9)->get();
        $withCancelled = AdminSaleReportExport::build($filtered, [
            'branch_name' => 'Munch Bamburi',
            'from' => $from,
            'to' => $to,
            'payment_totals' => ['cash' => 650],
            'cancelled_orders' => $cancelled,
        ]);
        $this->assertSame(600.0, $withCancelled['totals']['munch_sales']);
        $this->assertSame(3, $withCancelled['order_counts']['munch_sales']);
        $this->assertSame(850.0, $withCancelled['cancelled']['total']);
        $this->assertSame(1, $withCancelled['cancelled']['order_count']);
        $this->assertContains('9', $this->idKeys($withCancelled['cancelled_order_ids']));
        $this->assertNotContains('9', $this->idKeys($withCancelled['assigned_order_ids']));
        $this->assertStringContainsString('A10329', AdminSaleReportExport::csvString($withCancelled));
        $this->assertStringContainsString('CANCELLED', AdminSaleReportExport::csvString($withCancelled));
    }

    public function test_shared_payload_generates_openable_pdf_csv_and_xlsx(): void
    {
        $this->assertTrue(class_exists(Pdf::class));
        $this->assertFalse(class_exists('Barryvdh\\DomPDF\\Facade'));

        $report = $this->sampleReport();
        $this->assertSame(
            'Munch Bamburi Sales 11th September 2026.pdf',
            AdminSaleReportExport::filename($report, 'pdf')
        );
        $this->assertSame(
            'Munch Bamburi Sales 11th September 2026.csv',
            AdminSaleReportExport::filename($report, 'csv')
        );
        $this->assertSame(
            'Munch Bamburi Sales 11th September 2026.xlsx',
            AdminSaleReportExport::filename($report, 'xlsx')
        );

        $html = view('admin-views.report.partials._sale-report-export', compact('report'))->render();
        foreach ([
            'MUNCH',
            'Branch:',
            'Munch Bamburi',
            'Sales Date:',
            '11th September 2026',
            'MUNCH SALES',
            'Dine In',
            'Take Away',
            'Delivery',
            'Munch Sales Total',
            'Total Orders: 3',
            'Total Orders: 1',
            'GLOVO',
            'UBER',
            'BOLT FOOD',
            'Glovo Total',
            'Uber Total',
            'Bolt Food Total',
            'CANCELLED',
            'Not included in sales',
            'Cancelled Orders: 0',
            'Cancelled Total',
            'PAYMENT METHODS',
            'Time',
            'Munch Order #',
            'Glovo Order #',
            'Uber Order #',
            'Bolt Food Order #',
            'A10123',
            'A10126',
            'GLV-12345',
            'UBER-789',
            'BOLT-456',
            '10:42 AM',
            '#E7032D',
            '#FFC244',
            '#06C167',
            '#34D186',
            '#4B5563',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringNotContainsString('Marketplace Order #', $html);
        $this->assertStringNotContainsString('MARKETPLACE SALES', $html);
        $this->assertStringNotContainsString('TOTAL SALES', $html);
        $this->assertStringNotContainsString('Timestamp', $html);
        $this->assertStringNotContainsString('11 Sep 2026 10:42 AM', $html);

        $binary = Pdf::loadView('admin-views.report.partials._sale-report-export', compact('report'))->output();
        $this->assertNotSame('', $binary);
        $this->assertSame('%PDF', substr($binary, 0, 4));
        $this->assertGreaterThan(1000, strlen($binary));

        $csv = AdminSaleReportExport::csvString($report);
        $this->assertStringContainsString('Branch: Munch Bamburi', $csv);
        $this->assertStringContainsString('A10124', $csv);
        $this->assertStringContainsString('Total Orders: 3', $csv);
        $this->assertStringContainsString('Total Orders: 1', $csv);
        $this->assertStringContainsString('GLV-12345', $csv);
        $this->assertStringContainsString('UBER-789', $csv);
        $this->assertStringContainsString('BOLT-456', $csv);
        $this->assertStringNotContainsString('Marketplace Order #', $csv);

        $xlsxPath = sys_get_temp_dir().'/munch-sale-report-export-test.xlsx';
        AdminSaleReportExport::writeXlsx($xlsxPath, $report);
        $this->assertFileExists($xlsxPath);
        $this->assertGreaterThan(100, (int) filesize($xlsxPath));
        $this->assertSame('PK', substr((string) file_get_contents($xlsxPath), 0, 2));

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($xlsxPath) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $styles = (string) $zip->getFromName('xl/styles.xml');
        $zip->close();
        unlink($xlsxPath);

        foreach ([
            'MUNCH SALES',
            'GLOVO',
            'UBER',
            'BOLT FOOD',
            'CANCELLED',
            'Time',
            'Munch Order #',
            'Glovo Order #',
            'Uber Order #',
            'Bolt Food Order #',
            'Munch Sales Total',
            'Glovo Total',
            'Uber Total',
            'Bolt Food Total',
            'Total Orders: 3',
            'Total Orders: 1',
            'A10123',
            'GLV-12345',
            '10:42 AM',
        ] as $needle) {
            $this->assertStringContainsString($needle, $sheet, $needle);
        }
        $this->assertStringNotContainsString('Marketplace Order #', $sheet);
        $this->assertStringNotContainsString('TOTAL SALES', $sheet);
        $this->assertStringNotContainsString('Timestamp', $sheet);
        $this->assertStringContainsString('E7032D', $styles);
        $this->assertStringContainsString('FFC244', $styles);
        $this->assertStringContainsString('06C167', $styles);
        $this->assertStringContainsString('34D186', $styles);
        $this->assertStringContainsString('4B5563', $styles);
    }

    public function test_cancelled_orders_are_listed_separately_and_never_added_to_sales(): void
    {
        $day = Carbon::parse('2026-09-12 10:00:00', 'Africa/Nairobi');
        $validMunch = $this->order([
            'id' => 1,
            'order_type' => 'pos',
            'sales_channel' => 'takeaway',
            'order_amount' => 49620,
            'readable_order_id' => 'A10001',
            'payment_method' => 'cash',
            'order_status' => 'delivered',
            'created_at' => $day->copy()->setTime(10, 0),
        ]);
        $cancelledMunch = $this->order([
            'id' => 114739,
            'order_type' => 'pos',
            'sales_channel' => 'takeaway',
            'order_amount' => 850,
            'readable_order_id' => 'A10329',
            'payment_method' => 'mpesa',
            'order_status' => 'canceled',
            'cancelled_at' => $day->copy()->setTime(12, 53),
            'created_at' => $day->copy()->setTime(12, 53),
        ]);
        $cancelledGlovo = $this->order([
            'id' => 20,
            'order_type' => 'pos',
            'sales_channel' => 'glovo',
            'order_amount' => 400,
            'readable_order_id' => 'A10330',
            'platform_order_number' => 'GLV-999',
            'payment_method' => 'glovo',
            'order_status' => 'cancelled',
            'created_at' => $day->copy()->setTime(13, 10),
        ]);
        $cancelledUber = $this->order([
            'id' => 21,
            'order_type' => 'pos',
            'sales_channel' => 'uber',
            'order_amount' => 300,
            'readable_order_id' => 'A10331',
            'platform_order_number' => 'UBER-999',
            'payment_method' => 'uber',
            'order_status' => 'failed',
            'created_at' => $day->copy()->setTime(13, 20),
        ]);
        $cancelledBolt = $this->order([
            'id' => 22,
            'order_type' => 'pos',
            'sales_channel' => 'bolt_food',
            'order_amount' => 200,
            'readable_order_id' => 'A10332',
            'platform_order_number' => 'BOLT-999',
            'payment_method' => 'bolt_food',
            'order_status' => 'returned',
            'created_at' => $day->copy()->setTime(13, 30),
        ]);
        $refunded = $this->order([
            'id' => 23,
            'order_type' => 'pos',
            'sales_channel' => 'takeaway',
            'order_amount' => 150,
            'readable_order_id' => 'A10333',
            'payment_method' => 'card',
            'order_status' => 'refunded',
            'created_at' => $day->copy()->setTime(13, 40),
        ]);

        $payments = [
            'cash' => 49620,
            'card' => 0,
            'mpesa' => 0,
            'paystack' => 0,
            'glovo' => 0,
            'uber' => 0,
            'bolt_food' => 0,
        ];
        $report = AdminSaleReportExport::build([$validMunch], [
            'branch_name' => 'Munch Bamburi',
            'from' => '2026-09-12',
            'to' => '2026-09-12',
            'payment_totals' => $payments,
            'cancelled_orders' => [$cancelledMunch, $cancelledGlovo, $cancelledUber, $cancelledBolt, $refunded],
        ]);

        $this->assertSame(['1'], $this->idsIn($report, 'munch_sales'));
        $this->assertSame(['114739', '20', '21', '22', '23'], $this->idsIn($report, 'cancelled'));
        $this->assertSame(49620.0, $report['totals']['munch_sales']);
        $this->assertSame(1, $report['order_counts']['munch_sales']);
        $this->assertSame(0.0, $report['totals']['glovo']);
        $this->assertSame(0.0, $report['totals']['uber']);
        $this->assertSame(0.0, $report['totals']['bolt_food']);
        $this->assertSame(0, $report['order_counts']['glovo']);
        $this->assertSame(0, $report['order_counts']['uber']);
        $this->assertSame(0, $report['order_counts']['bolt_food']);
        $this->assertSame(1900.0, $report['cancelled']['total']);
        $this->assertSame(5, $report['cancelled']['order_count']);
        $this->assertSame(0.0, $report['payment_totals']['mpesa']);
        $this->assertSame(49620.0, $report['payment_totals']['cash']);
        $this->assertArrayNotHasKey('total_sales', $report['totals']);
        $this->assertEmpty(array_intersect(
            array_keys($report['assigned_order_ids']),
            array_keys($report['cancelled_order_ids'])
        ));
        $this->assertNotContains('114739', array_keys($report['assigned_order_ids']));
        $this->assertNotContains('20', array_keys($report['assigned_order_ids']));
        $this->assertNotContains('21', array_keys($report['assigned_order_ids']));
        $this->assertNotContains('22', array_keys($report['assigned_order_ids']));

        $cancelledRows = array_values(array_filter(
            $report['sections']['cancelled']['orders'],
            static fn (array $order): bool => $order['order_number'] === 'A10329'
        ));
        $this->assertCount(1, $cancelledRows);
        $this->assertSame('12:53 PM', $cancelledRows[0]['time']);
        $this->assertStringNotContainsString('Sep', $cancelledRows[0]['time']);
        $this->assertSame('Take Away', $cancelledRows[0]['order_type']);
        $this->assertSame(PosOrderTypes::paymentDisplayLabel('mpesa'), $cancelledRows[0]['payment_method']);
        $this->assertSame('Cancelled', $cancelledRows[0]['status']);
        $this->assertSame(850.0, $cancelledRows[0]['amount']);

        $byNumber = [];
        foreach ($report['sections']['cancelled']['orders'] as $order) {
            $byNumber[$order['order_number']] = $order;
        }
        $this->assertSame('Failed', $byNumber['A10331']['status']);
        $this->assertSame('Returned', $byNumber['A10332']['status']);
        $this->assertSame('Refunded', $byNumber['A10333']['status']);
        $this->assertSame('Glovo', $byNumber['A10330']['order_type']);
        $this->assertSame('GLV-999', $byNumber['A10330']['platform_order_number']);
        $this->assertSame('UBER-999', $byNumber['A10331']['platform_order_number']);
        $this->assertSame('BOLT-999', $byNumber['A10332']['platform_order_number']);

        $cancelledCells = AdminSaleReportExport::orderCells($byNumber['A10330'], AdminSaleReportExport::CANCELLED_SECTION);
        $this->assertSame('Glovo / GLV-999', $cancelledCells[2]);
        $this->assertNotContains('Marketplace Order #', $cancelledCells);

        $mixed = AdminSaleReportExport::build([$validMunch, $cancelledMunch], [
            'branch_name' => 'Munch Bamburi',
            'from' => '2026-09-12',
            'to' => '2026-09-12',
            'payment_totals' => $payments,
        ]);
        $this->assertSame(['1'], $this->idsIn($mixed, 'munch_sales'));
        $this->assertSame(['114739'], $this->idsIn($mixed, 'cancelled'));
        $this->assertSame(49620.0, $mixed['totals']['munch_sales']);
        $this->assertSame(850.0, $mixed['cancelled']['total']);

        $csv = AdminSaleReportExport::csvString($report);
        $html = view('admin-views.report.partials._sale-report-export', compact('report'))->render();
        $screen = view('admin-views.report.partials._sale-report-cancelled', compact('report'))->render();
        $xlsxPath = sys_get_temp_dir().'/munch-sale-report-cancelled.xlsx';
        AdminSaleReportExport::writeXlsx($xlsxPath, $report);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($xlsxPath) === true);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $styles = (string) $zip->getFromName('xl/styles.xml');
        $zip->close();
        unlink($xlsxPath);

        foreach ([$csv, $html, $sheet] as $output) {
            $this->assertStringContainsString('A10329', $output);
            $this->assertStringContainsString('CANCELLED', $output);
            $this->assertStringContainsString('Cancelled Total', $output);
            $this->assertStringContainsString('GLV-999', $output);
            $this->assertStringContainsString('UBER-999', $output);
            $this->assertStringContainsString('BOLT-999', $output);
            $this->assertStringNotContainsString('Marketplace Order #', $output);
        }
        $this->assertStringContainsString('A10329', $screen);
        $this->assertStringContainsString('Cancelled', $screen);
        $this->assertStringContainsString('Cancelled Total', $screen);
        $this->assertStringContainsString('GLV-999', $screen);
        $this->assertStringContainsString('UBER-999', $screen);
        $this->assertStringContainsString('BOLT-999', $screen);
        $this->assertStringContainsString('Not included in sales', $screen);
        $this->assertStringNotContainsString('Marketplace Order #', $screen);
        $this->assertStringContainsString('12:53 PM', $csv);
        $this->assertStringNotContainsString('12 Sep 2026 12:53 PM', $csv);
        $this->assertStringContainsString('Not included in sales', $csv);
        $this->assertStringContainsString('Cancelled Orders: 5', $csv);
        $this->assertStringContainsString('#4B5563', $html);
        $this->assertStringContainsString('#E7032D', $html);
        $this->assertStringContainsString('4B5563', $styles);
        $pdf = Pdf::loadView('admin-views.report.partials._sale-report-export', compact('report'))->output();
        $this->assertSame('%PDF', substr($pdf, 0, 4));
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function test_bamburi_12_september_keeps_valid_munch_sales_and_shows_cancelled_a10329(): void
    {
        $day = Carbon::parse('2026-09-12 12:48:48', 'Africa/Nairobi');
        $remaining = 49620.0;
        $valid = [];
        for ($i = 1; $i <= 75; $i++) {
            $amount = ($i === 75) ? $remaining : 660.0;
            $remaining -= $amount;
            $valid[] = $this->order([
                'id' => $i,
                'order_type' => 'pos',
                'sales_channel' => 'takeaway',
                'order_amount' => $amount,
                'readable_order_id' => 'A'.(10000 + $i),
                'payment_method' => 'cash',
                'order_status' => 'delivered',
                'created_at' => $day,
            ]);
        }
        $cancelled = $this->order([
            'id' => 114739,
            'order_type' => 'pos',
            'sales_channel' => 'takeaway',
            'order_amount' => 850,
            'readable_order_id' => 'A10329',
            'payment_method' => 'mpesa',
            'order_status' => 'canceled',
            'cancelled_at' => Carbon::parse('2026-09-12 12:53:50', 'Africa/Nairobi'),
            'created_at' => Carbon::parse('2026-09-12 12:53:50', 'Africa/Nairobi'),
        ]);

        $report = AdminSaleReportExport::build($valid, [
            'branch_name' => 'Munch Bamburi',
            'from' => '2026-09-12',
            'to' => '2026-09-12',
            'payment_totals' => [
                'cash' => 49620,
                'card' => 0,
                'mpesa' => 0,
                'paystack' => 0,
                'glovo' => 0,
                'uber' => 0,
                'bolt_food' => 0,
            ],
            'cancelled_orders' => [$cancelled],
        ]);

        $this->assertSame(49620.0, $report['totals']['munch_sales']);
        $this->assertSame(75, $report['order_counts']['munch_sales']);
        $this->assertSame(850.0, $report['cancelled']['total']);
        $this->assertSame(1, $report['cancelled']['order_count']);
        $this->assertSame(['114739'], $this->idsIn($report, 'cancelled'));
        $this->assertNotContains('114739', $this->idsIn($report, 'munch_sales'));
        $this->assertSame(0.0, $report['payment_totals']['mpesa']);
        $this->assertSame(49620.0, $report['payment_totals']['cash']);
        $this->assertArrayNotHasKey('total_sales', $report['totals']);

        $csv = AdminSaleReportExport::csvString($report);
        $html = view('admin-views.report.partials._sale-report-export', compact('report'))->render();
        $this->assertStringContainsString('A10329', $csv);
        $this->assertStringContainsString('A10329', $html);
        $this->assertStringContainsString('Cancelled Orders: 1', $csv);
        $this->assertStringContainsString(AdminSaleReportExport::formatAmount(49620), $csv);
        $this->assertStringContainsString(AdminSaleReportExport::formatAmount(850), $csv);
        $this->assertStringNotContainsString('Marketplace Order #', $csv);
    }

    public function test_cancelled_section_respects_branch_and_date_filters(): void
    {
        $today = Carbon::parse('2026-09-12 12:53:50', 'Africa/Nairobi');
        $yesterday = Carbon::parse('2026-09-11 12:53:50', 'Africa/Nairobi');

        $this->insert(114739, 'pos', 'takeaway', 850, 10, $today, 'A10329', '', 'canceled', $today);
        $this->insert(200, 'pos', 'takeaway', 400, 2, $today, 'A20001', '', 'canceled', $today);
        $this->insert(201, 'pos', 'takeaway', 300, 10, $yesterday, 'A10300', '', 'canceled', $yesterday);
        $this->insert(1, 'pos', 'takeaway', 660, 10, $today, 'A10001');

        $from = $today->copy()->startOfDay();
        $to = $today->copy()->endOfDay();
        $valid = Order::query()->whereBetween('created_at', [$from, $to])->where('branch_id', 10);
        AdminDashboardSalesKpis::constrainNotVoided($valid);
        $cancelled = Order::query()->whereBetween('created_at', [$from, $to])->where('branch_id', 10);
        AdminDashboardSalesKpis::constrainVoided($cancelled);

        $report = AdminSaleReportExport::build($valid->get(), [
            'branch_name' => 'Munch Bamburi',
            'from' => $from,
            'to' => $to,
            'payment_totals' => ['cash' => 660],
            'cancelled_orders' => $cancelled->get(),
        ]);

        $this->assertSame(['1'], $this->idsIn($report, 'munch_sales'));
        $this->assertSame(['114739'], $this->idsIn($report, 'cancelled'));
        $this->assertSame(660.0, $report['totals']['munch_sales']);
        $this->assertSame(850.0, $report['cancelled']['total']);
        $this->assertNotContains('200', $this->idsIn($report, 'cancelled'));
        $this->assertNotContains('201', $this->idsIn($report, 'cancelled'));
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleReport(): array
    {
        return AdminSaleReportExport::build($this->sampleOrders(), [
            'branch_name' => 'Munch Bamburi',
            'from' => '2026-09-11',
            'to' => '2026-09-11',
            'payment_totals' => [
                'cash' => 2100,
                'card' => 0,
                'mpesa' => 0,
                'paystack' => 0,
                'glovo' => 1100,
                'uber' => 950,
                'bolt_food' => 1300,
            ],
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sampleOrders(): array
    {
        $day = Carbon::parse('2026-09-11 10:00:00', 'Africa/Nairobi');

        return [
            $this->order([
                'id' => 1,
                'order_type' => 'dine_in',
                'sales_channel' => 'dine_in',
                'order_amount' => 1250,
                'readable_order_id' => 'A10123',
                'created_at' => $day->copy()->setTime(10, 42),
            ]),
            $this->order([
                'id' => 2,
                'order_type' => 'pos',
                'sales_channel' => 'takeaway',
                'order_amount' => 850,
                'readable_order_id' => 'A10124',
                'created_at' => $day->copy()->setTime(12, 18),
            ]),
            $this->order([
                'id' => 3,
                'order_type' => 'pos',
                'sales_channel' => 'delivery',
                'order_amount' => 1450,
                'readable_order_id' => 'A10125',
                'created_at' => $day->copy()->setTime(13, 5),
            ]),
            $this->order([
                'id' => 4,
                'order_type' => 'pos',
                'sales_channel' => 'glovo',
                'order_amount' => 1100,
                'readable_order_id' => 'A10126',
                'platform_order_number' => 'GLV-12345',
                'created_at' => $day->copy()->setTime(14, 31),
            ]),
            $this->order([
                'id' => 5,
                'order_type' => 'pos',
                'sales_channel' => 'uber',
                'order_amount' => 950,
                'readable_order_id' => 'A10127',
                'platform_order_number' => 'UBER-789',
                'created_at' => $day->copy()->setTime(16, 12),
            ]),
            $this->order([
                'id' => 6,
                'order_type' => 'pos',
                'sales_channel' => 'bolt_food',
                'order_amount' => 1300,
                'readable_order_id' => 'A10128',
                'platform_order_number' => 'BOLT-456',
                'created_at' => $day->copy()->setTime(18, 45),
            ]),
            $this->order([
                'id' => 7,
                'order_type' => 'delivery',
                'sales_channel' => null,
                'order_amount' => 999,
                'readable_order_id' => 'W10001',
            ]),
            $this->order([
                'id' => 8,
                'order_type' => 'take_away',
                'sales_channel' => null,
                'order_amount' => 888,
                'readable_order_id' => 'W10002',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function order(array $overrides): array
    {
        return array_merge([
            'id' => 1,
            'order_type' => 'pos',
            'sales_channel' => 'takeaway',
            'readable_order_id' => 'A10000',
            'platform_order_number' => '',
            'order_amount' => 0,
            'created_at' => Carbon::parse('2026-09-11 10:00:00', 'Africa/Nairobi'),
        ], $overrides);
    }

    /**
     * @param  array<int|string, mixed>  $map
     * @return list<string>
     */
    private function idKeys(array $map): array
    {
        return array_map(static fn (int|string $id): string => (string) $id, array_keys($map));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function idsIn(array $report, string $section): array
    {
        $ids = [];
        foreach ($report['sections'][$section]['categories'] as $category) {
            foreach ($category['orders'] as $order) {
                $ids[] = (string) $order['order_id'];
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private function idsInCategory(array $report, string $section, string $category): array
    {
        return array_map(
            static fn (array $order): string => (string) $order['order_id'],
            $report['sections'][$section]['categories'][$category]['orders']
        );
    }

    /**
     * @param  list<array{type: string, section: string|null, cells: list<string>}>  $rows
     * @return list<string>
     */
    private function cellsByType(array $rows, string $type): array
    {
        $values = [];
        foreach ($rows as $row) {
            if ($row['type'] !== $type) {
                continue;
            }
            foreach ($row['cells'] as $cell) {
                if ($cell !== '') {
                    $values[] = $cell;
                }
            }
        }

        return $values;
    }

    /**
     * @param  list<array{type: string, section: string|null, cells: list<string>}>  $rows
     * @return array<string, list<string>>
     */
    private function rowsByType(array $rows, string $type): array
    {
        $mapped = [];
        foreach ($rows as $row) {
            if ($row['type'] === $type && $row['section'] !== null) {
                $mapped[$row['section']] = $row['cells'];
            }
        }

        return $mapped;
    }

    private function insert(
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
            'payment_method' => 'cash',
            'order_status' => $status,
            'order_amount' => $amount,
            'cancelled_at' => $cancelledAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
