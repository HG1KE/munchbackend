<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Support\AdminSaleReportExport;
use App\Support\PosOrderTypes;
use App\Support\TimezoneDisplay;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Rap2hpoutre\FastExcel\FastExcel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

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
    }

    public function test_no_order_appears_in_more_than_one_section_and_totals_add_up(): void
    {
        $report = $this->sampleReport();
        $assigned = $report['assigned_order_ids'];

        $this->assertSame(6, count($assigned));
        $this->assertSame(6, count(array_unique(array_keys($assigned))));
        $this->assertSame(3550.0, $report['totals']['munch_sales']);
        $this->assertSame(1100.0, $report['totals']['glovo']);
        $this->assertSame(950.0, $report['totals']['uber']);
        $this->assertSame(1300.0, $report['totals']['bolt_food']);
        $this->assertSame(6900.0, $report['totals']['total_sales']);
        $this->assertSame(
            $report['totals']['munch_sales']
            + $report['totals']['glovo']
            + $report['totals']['uber']
            + $report['totals']['bolt_food'],
            $report['totals']['total_sales']
        );
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
        $this->assertSame(100.0, $report['totals']['munch_sales']);
        $this->assertSame(100.0, $report['totals']['total_sales']);
    }

    public function test_order_timestamps_and_marketplace_numbers_are_present(): void
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

        $this->assertSame('11 Sep 2026 10:42 AM', $dineIn['timestamp']);
        $this->assertSame('A10123', $dineIn['order_number']);
        $this->assertSame('Munch Sales', $dineIn['sales_category']);
        $this->assertSame('Dine In', $dineIn['order_type']);
        $this->assertSame('', $dineIn['platform_order_number']);
        $this->assertSame('11 Sep 2026 02:31 PM', $glovo['timestamp']);
        $this->assertSame('A10126', $glovo['order_number']);
        $this->assertSame('GLV-12345', $glovo['platform_order_number']);
        $this->assertSame('Glovo', $glovo['sales_category']);
        $this->assertSame('Glovo', $glovo['order_type']);
    }

    public function test_branch_and_sales_date_appear_in_every_export_format(): void
    {
        $report = $this->sampleReport();
        $csv = AdminSaleReportExport::csvString($report);
        $rows = AdminSaleReportExport::flattenRows($report);
        $pdf = file_get_contents(resource_path('views/admin-views/report/partials/_sale-report-export.blade.php'));

        $this->assertSame('Munch Bamburi', $report['branch_name']);
        $this->assertSame('11th September 2026', $report['sales_date_label']);
        $this->assertTrue($report['is_single_day']);
        $this->assertStringContainsString('MUNCH', $csv);
        $this->assertStringContainsString('Branch: Munch Bamburi', $csv);
        $this->assertStringContainsString('Sales Date: 11th September 2026', $csv);
        $this->assertSame('MUNCH', $rows[0]['Timestamp']);
        $this->assertSame('Branch: Munch Bamburi', $rows[1]['Timestamp']);
        $this->assertSame('Sales Date: 11th September 2026', $rows[2]['Timestamp']);
        $this->assertStringContainsString('Branch:', $pdf);
        $this->assertStringContainsString('Sales Date:', $pdf);
        $this->assertStringContainsString('MUNCH SALES', $csv);
        $this->assertStringContainsString('MARKETPLACE SALES', $csv);
        $this->assertStringContainsString('A10126', $csv);
        $this->assertStringContainsString('GLV-12345', $csv);
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
        $this->assertNotEquals($report['totals']['total_sales'], $report['payment_totals']['paystack']);
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

        $from = $today->copy()->startOfDay();
        $to = $today->copy()->endOfDay();
        $query = Order::query()->whereBetween('created_at', [$from, $to])->where('branch_id', 1);
        PosOrderTypes::constrainSaleReportChannel($query, 'pos');
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
        $this->assertSame(650.0, $report['totals']['total_sales']);
        $this->assertSame(650.0, $report['payment_totals']['cash']);
        $this->assertNotContains('5', array_keys($report['assigned_order_ids']));
        $this->assertNotContains('6', array_keys($report['assigned_order_ids']));
        $this->assertNotContains('7', array_keys($report['assigned_order_ids']));
        $this->assertNotContains('8', array_keys($report['assigned_order_ids']));
    }

    public function test_csv_and_excel_rows_share_the_same_breakdown(): void
    {
        $report = $this->sampleReport();
        $rows = AdminSaleReportExport::flattenRows($report);
        $csv = AdminSaleReportExport::csvString($report);
        $timestamps = array_column($rows, 'Timestamp');

        $this->assertContains('MUNCH SALES', $timestamps);
        $this->assertContains('Munch Sales Total', $timestamps);
        $this->assertContains('Glovo Total', $timestamps);
        $this->assertContains('Uber Total', $timestamps);
        $this->assertContains('Bolt Food Total', $timestamps);
        $this->assertContains('TOTAL SALES', $timestamps);
        $this->assertContains('PAYMENT METHODS', $timestamps);
        $this->assertSame(array_keys($rows[0]), [
            'Timestamp',
            'Munch Order #',
            'Marketplace Order #',
            'Sales Category',
            'Order Type',
            'Amount',
        ]);
        $this->assertStringContainsString('Munch Sales Total', $csv);
        $this->assertStringContainsString('TOTAL SALES', $csv);
        $this->assertSame(AdminSaleReportExport::FORMAT_XLSX, AdminSaleReportExport::normalizeFormat('excel'));
        $this->assertSame(AdminSaleReportExport::FORMAT_CSV, AdminSaleReportExport::normalizeFormat('csv'));
        $this->assertSame(AdminSaleReportExport::FORMAT_PDF, AdminSaleReportExport::normalizeFormat('pdf'));
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
            'MARKETPLACE SALES',
            'Glovo Total',
            'Uber Total',
            'Bolt Food Total',
            'TOTAL SALES',
            'PAYMENT METHODS',
            'A10123',
            'A10126',
            'GLV-12345',
            'UBER-789',
            'BOLT-456',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }

        $binary = Pdf::loadView('admin-views.report.partials._sale-report-export', compact('report'))->output();
        $this->assertNotSame('', $binary);
        $this->assertSame('%PDF', substr($binary, 0, 4));

        $csv = AdminSaleReportExport::csvString($report);
        $this->assertStringContainsString('Branch: Munch Bamburi', $csv);
        $this->assertStringContainsString('A10124', $csv);
        $this->assertStringContainsString('GLV-12345', $csv);

        $xlsxPath = sys_get_temp_dir().'/munch-sale-report-export-test.xlsx';
        (new FastExcel(AdminSaleReportExport::flattenRows($report)))->export($xlsxPath);
        $this->assertFileExists($xlsxPath);
        $this->assertGreaterThan(100, (int) filesize($xlsxPath));
        unlink($xlsxPath);
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

    private function insert(
        int $id,
        string $type,
        ?string $channel,
        float $amount,
        int $branchId,
        Carbon $createdAt,
        string $readableId,
        string $platform = ''
    ): void {
        DB::table('orders')->insert([
            'id' => $id,
            'branch_id' => $branchId,
            'order_type' => $type,
            'sales_channel' => $channel,
            'readable_order_id' => $readableId,
            'platform_order_number' => $platform,
            'payment_method' => 'cash',
            'order_amount' => $amount,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
