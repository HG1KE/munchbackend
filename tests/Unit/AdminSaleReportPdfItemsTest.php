<?php

namespace Tests\Unit;

use App\Support\AdminSaleReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminSaleReportPdfItemsTest extends TestCase
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

        Schema::dropIfExists('add_ons');
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
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->integer('quantity')->default(1);
            $table->text('product_details')->nullable();
            $table->text('variation')->nullable();
            $table->text('add_on_ids')->nullable();
            $table->text('add_on_qtys')->nullable();
            $table->timestamps();
        });
        Schema::create('add_ons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('add_ons');
        Schema::dropIfExists('order_details');
        Schema::dropIfExists('orders');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_pdf_order_cell_includes_item_names_quantities_variations_and_addons(): void
    {
        $report = $this->reportWithSampleItems();
        $order = $report['sections']['munch_sales']['orders'][0];
        $html = view('admin-views.report.partials._sale-report-export', compact('report'))->render();
        $text = AdminSaleReportExport::orderCellText($order);
        $cellHtml = AdminSaleReportExport::orderCellHtml($order);

        $this->assertStringContainsString('A10852', $text);
        $this->assertStringContainsString('2 × SuperBowl', $text);
        $this->assertStringContainsString('1 × 4 Piece Wings', $text);
        $this->assertStringContainsString('Plain Chips', $text);
        $this->assertStringContainsString('BBQ', $text);
        $this->assertStringContainsString('+ Extra Cheese, + Chicken', $text);
        $this->assertStringContainsString('+ Extra Sauce', $text);

        $this->assertStringContainsString('order-cell__number', $cellHtml);
        $this->assertStringContainsString('A10852', $cellHtml);
        $this->assertStringContainsString('2 × SuperBowl', $html);
        $this->assertStringContainsString('SuperBowl', $html);
        $this->assertStringContainsString('4 Piece Wings', $html);
        $this->assertStringContainsString('Plain Chips', $html);
        $this->assertStringContainsString('BBQ', $html);
        $this->assertStringContainsString('+ Extra Cheese', $html);
        $this->assertStringContainsString('+ Chicken', $html);
        $this->assertStringContainsString('+ Extra Sauce', $html);
        $this->assertStringContainsString('font-size: 7.5pt', $html);
        $this->assertStringContainsString('order-cell', $html);
        $this->assertStringContainsString('Munch Order #', $html);
        $this->assertStringNotContainsString('>Item</th>', $html);
        $this->assertStringNotContainsString('Variation</th>', $html);
        $this->assertStringNotContainsString('Addon</th>', $html);
        $this->assertSame(
            ['Time', 'Munch Order #', 'Type', 'Qty', 'Amount'],
            AdminSaleReportExport::sectionColumnLabels('munch_sales')
        );

        $this->assertSame(1250.0, $report['totals']['munch_sales']);
        $this->assertSame(1, $report['order_counts']['munch_sales']);
        $this->assertSame(3, $order['quantity']);
        $this->assertCount(1, $report['sections']['munch_sales']['orders']);
    }

    public function test_orders_without_variations_or_addons_stay_compact(): void
    {
        $report = AdminSaleReportExport::build([
            $this->order([
                'id' => 2,
                'readable_order_id' => 'A10853',
                'order_amount' => 400,
                'quantity' => 1,
                'items' => [
                    ['name' => 'Fries', 'quantity' => 1, 'variations' => [], 'addons' => []],
                ],
            ]),
        ], $this->context());

        $order = $report['sections']['munch_sales']['orders'][0];
        $text = AdminSaleReportExport::orderCellText($order);
        $cellHtml = AdminSaleReportExport::orderCellHtml($order);
        $html = view('admin-views.report.partials._sale-report-export', compact('report'))->render();

        $this->assertSame("A10853\n1 × Fries", $text);
        $this->assertStringNotContainsString('order-cell__meta', $cellHtml);
        $this->assertStringNotContainsString('+ ', $text);
        $this->assertStringContainsString('1 × Fries', $html);
        $this->assertSame(400.0, $report['totals']['munch_sales']);
    }

    public function test_multiple_items_stay_inside_one_order_cell(): void
    {
        $report = $this->reportWithSampleItems();
        $order = $report['sections']['munch_sales']['orders'][0];
        $html = view('admin-views.report.partials._sale-report-export', compact('report'))->render();

        $this->assertCount(2, $order['items']);
        $this->assertSame(1, substr_count($html, 'A10852'));
        $this->assertStringContainsString('2 × SuperBowl', $html);
        $this->assertStringContainsString('1 × 4 Piece Wings', $html);
        $this->assertStringContainsString('order-cell__item', $html);
        $this->assertStringNotContainsString('<th>Item</th>', $html);

        $csv = AdminSaleReportExport::csvString($report);
        $this->assertStringContainsString('A10852', $csv);
        $this->assertStringNotContainsString('SuperBowl', $csv);
        $this->assertStringNotContainsString('Item,', $csv);
        $this->assertSame(
            [$order['time'], 'A10852', 'Dine In', '3', AdminSaleReportExport::formatAmount(1250)],
            AdminSaleReportExport::orderCells($order, 'munch_sales')
        );
    }

    public function test_existing_totals_and_on_screen_listing_are_unchanged(): void
    {
        $orders = [
            $this->order([
                'id' => 1,
                'readable_order_id' => 'A10852',
                'order_amount' => 1250,
                'quantity' => 3,
                'items' => [
                    ['name' => 'SuperBowl', 'quantity' => 2, 'variations' => ['Plain Chips'], 'addons' => ['Extra Cheese']],
                    ['name' => '4 Piece Wings', 'quantity' => 1, 'variations' => ['BBQ'], 'addons' => ['Extra Sauce']],
                ],
            ]),
            $this->order([
                'id' => 4,
                'order_type' => 'pos',
                'sales_channel' => 'glovo',
                'readable_order_id' => 'A10854',
                'platform_order_number' => 'GLV-9',
                'order_amount' => 1100,
                'quantity' => 2,
                'created_at' => Carbon::parse('2026-09-11 14:31:00', 'Africa/Nairobi'),
                'items' => [
                    ['name' => 'Burger', 'quantity' => 2, 'variations' => [], 'addons' => []],
                ],
            ]),
        ];
        $report = AdminSaleReportExport::build($orders, [
            'branch_name' => 'Munch Bamburi',
            'from' => '2026-09-11',
            'to' => '2026-09-11',
            'payment_totals' => [
                'cash' => 1250,
                'glovo' => 1100,
            ],
        ]);
        $listing = AdminSaleReportExport::listingRows($orders, ['1' => 3, '4' => 2]);
        $screen = file_get_contents(resource_path('views/admin-views/report/partials/_table.blade.php'));

        $this->assertSame(1250.0, $report['totals']['munch_sales']);
        $this->assertSame(1100.0, $report['totals']['glovo']);
        $this->assertSame(0.0, $report['totals']['uber']);
        $this->assertSame(0.0, $report['totals']['bolt_food']);
        $this->assertSame(1, $report['order_counts']['munch_sales']);
        $this->assertSame(1, $report['order_counts']['glovo']);
        $this->assertSame(1250.0, $report['payment_totals']['cash']);
        $this->assertSame(1100.0, $report['payment_totals']['glovo']);
        $this->assertArrayNotHasKey('items', $listing[0]);
        $this->assertSame('A10852', $listing[0]['order_display_id']);
        $this->assertStringContainsString("\$row['order_display_id']", $screen);
        $this->assertStringNotContainsString('order-cell__items', $screen);
        $this->assertStringNotContainsString('orderCellHtml', $screen);
        $this->assertStringContainsString('Munch Order #', view('admin-views.report.partials._sale-report-export', compact('report'))->render());
    }

    public function test_pdf_loads_item_breakdown_from_order_details(): void
    {
        $day = Carbon::parse('2026-09-11 10:42:00', 'Africa/Nairobi');
        DB::table('orders')->insert([
            'id' => 10,
            'branch_id' => 1,
            'order_type' => 'dine_in',
            'sales_channel' => 'dine_in',
            'readable_order_id' => 'A10852',
            'platform_order_number' => '',
            'payment_method' => 'cash',
            'order_status' => 'delivered',
            'order_amount' => 1250,
            'created_at' => $day,
            'updated_at' => $day,
        ]);
        DB::table('add_ons')->insert([
            ['id' => 1, 'name' => 'Extra Cheese'],
            ['id' => 2, 'name' => 'Chicken'],
            ['id' => 3, 'name' => 'Extra Sauce'],
        ]);
        DB::table('order_details')->insert([
            [
                'order_id' => 10,
                'quantity' => 2,
                'product_details' => json_encode(['name' => 'SuperBowl']),
                'variation' => json_encode([['name' => 'Flavour', 'values' => ['label' => ['Plain Chips']]]]),
                'add_on_ids' => json_encode([1, 2]),
                'add_on_qtys' => json_encode([1, 1]),
                'created_at' => $day,
                'updated_at' => $day,
            ],
            [
                'order_id' => 10,
                'quantity' => 1,
                'product_details' => json_encode(['name' => '4 Piece Wings']),
                'variation' => json_encode([['name' => 'Flavour', 'values' => [['label' => 'BBQ']]]]),
                'add_on_ids' => json_encode([3]),
                'add_on_qtys' => json_encode([1]),
                'created_at' => $day,
                'updated_at' => $day,
            ],
            [
                'order_id' => 10,
                'quantity' => 1,
                'product_details' => json_encode(['name' => 'Fries']),
                'variation' => json_encode([]),
                'add_on_ids' => json_encode([]),
                'add_on_qtys' => json_encode([]),
                'created_at' => $day,
                'updated_at' => $day,
            ],
        ]);

        $order = DB::table('orders')->where('id', 10)->first();
        $report = AdminSaleReportExport::build([$order], [
            'branch_name' => 'Munch Bamburi',
            'from' => '2026-09-11',
            'to' => '2026-09-11',
            'payment_totals' => ['cash' => 1250],
            'quantities' => ['10' => 4],
        ]);
        $row = $report['sections']['munch_sales']['orders'][0];
        $text = AdminSaleReportExport::orderCellText($row);
        $html = view('admin-views.report.partials._sale-report-export', compact('report'))->render();
        $pdf = Pdf::loadView('admin-views.report.partials._sale-report-export', compact('report'))->output();

        $this->assertSame(1250.0, $report['totals']['munch_sales']);
        $this->assertSame(4, $row['quantity']);
        $this->assertCount(3, $row['items']);
        $this->assertStringContainsString('2 × SuperBowl', $text);
        $this->assertStringContainsString('   Plain Chips', $text);
        $this->assertStringContainsString('   + Extra Cheese, + Chicken', $text);
        $this->assertStringContainsString('1 × 4 Piece Wings', $text);
        $this->assertStringContainsString('   BBQ', $text);
        $this->assertStringContainsString('   + Extra Sauce', $text);
        $this->assertStringContainsString("1 × Fries", $text);
        $this->assertSame(1, substr_count($html, 'A10852'));
        $this->assertSame('%PDF', substr($pdf, 0, 4));
        $this->assertGreaterThan(1000, strlen($pdf));
        $this->assertSame(
            [$row['time'], 'A10852', 'Dine In', '4', AdminSaleReportExport::formatAmount(1250)],
            AdminSaleReportExport::orderCells($row, 'munch_sales')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reportWithSampleItems(): array
    {
        return AdminSaleReportExport::build([
            $this->order([
                'id' => 1,
                'order_type' => 'dine_in',
                'sales_channel' => 'dine_in',
                'readable_order_id' => 'A10852',
                'order_amount' => 1250,
                'quantity' => 3,
                'created_at' => Carbon::parse('2026-09-11 10:42:00', 'Africa/Nairobi'),
                'items' => [
                    [
                        'name' => 'SuperBowl',
                        'quantity' => 2,
                        'variations' => ['Plain Chips'],
                        'addons' => ['Extra Cheese', 'Chicken'],
                    ],
                    [
                        'name' => '4 Piece Wings',
                        'quantity' => 1,
                        'variations' => ['BBQ'],
                        'addons' => ['Extra Sauce'],
                    ],
                ],
            ]),
        ], $this->context(1250));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(float $cash = 400): array
    {
        return [
            'branch_name' => 'Munch Bamburi',
            'from' => '2026-09-11',
            'to' => '2026-09-11',
            'payment_totals' => ['cash' => $cash],
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
            'order_type' => 'dine_in',
            'sales_channel' => 'dine_in',
            'readable_order_id' => 'A10000',
            'platform_order_number' => '',
            'order_amount' => 0,
            'created_at' => Carbon::parse('2026-09-11 10:42:00', 'Africa/Nairobi'),
        ], $overrides);
    }
}
