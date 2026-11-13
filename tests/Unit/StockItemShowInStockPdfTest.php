<?php

namespace Tests\Unit;

use App\Model\StockItem;
use App\Services\Stock\StockVarianceService;
use App\Support\StocksAuditExport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockItemShowInStockPdfTest extends TestCase
{
    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = config('database.default');
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('status')->default(true);
            $table->boolean('show_in_stock_pdf')->default(true);
            $table->timestamps();
        });
        Schema::create('stock_daily_counts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('stock_item_id');
            $table->date('count_date');
            $table->decimal('opening_qty', 12, 3)->nullable();
            $table->decimal('received_qty', 12, 3)->nullable();
            $table->decimal('spoiled_qty', 12, 3)->nullable();
            $table->decimal('closing_qty', 12, 3)->nullable();
            $table->timestamps();
        });
        Schema::create('stock_sale_deductions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('stock_item_id');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->date('sale_date');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('stock_sale_deductions');
        Schema::dropIfExists('stock_daily_counts');
        Schema::dropIfExists('stock_items');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_migration_model_and_admin_ui_wire_show_in_stock_pdf(): void
    {
        $migration = database_path('migrations/2026_11_13_160000_add_show_in_stock_pdf_to_stock_items.php');
        $this->assertFileExists($migration);
        $migrationSrc = file_get_contents($migration);
        $this->assertStringContainsString("show_in_stock_pdf", $migrationSrc);
        $this->assertStringContainsString('->default(true)', $migrationSrc);

        $model = new StockItem();
        $this->assertContains('show_in_stock_pdf', $model->getFillable());
        $this->assertArrayHasKey('show_in_stock_pdf', $model->getCasts());

        $controller = file_get_contents(app_path('Http/Controllers/Admin/StockItemController.php'));
        $this->assertStringContainsString("'show_in_stock_pdf' => \$request->has('show_in_stock_pdf')", $controller);
        $this->assertStringContainsString('$stockItem->show_in_stock_pdf = $request->has(\'show_in_stock_pdf\');', $controller);

        $index = file_get_contents(resource_path('views/admin-views/stock-item/index.blade.php'));
        $edit = file_get_contents(resource_path('views/admin-views/stock-item/edit.blade.php'));
        $this->assertStringContainsString('name="show_in_stock_pdf"', $index);
        $this->assertStringContainsString("translate('Show in Stock PDF')", $index);
        $this->assertStringContainsString('name="show_in_stock_pdf"', $edit);
        $this->assertStringContainsString("translate('Show in Stock PDF')", $edit);

        $variance = file_get_contents(app_path('Services/Stock/StockVarianceService.php'));
        $this->assertStringContainsString('show_in_stock_pdf', $variance);

        $export = file_get_contents(app_path('Support/StocksAuditExport.php'));
        $this->assertStringContainsString('shouldIncludeInPdf', $export);
    }

    public function test_variance_rows_include_all_items_but_pdf_filters_flagged_off(): void
    {
        $visibleId = DB::table('stock_items')->insertGetId([
            'name' => 'Beef Patties',
            'status' => 1,
            'show_in_stock_pdf' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $hiddenId = DB::table('stock_items')->insertGetId([
            'name' => 'Internal Wrapper',
            'status' => 1,
            'show_in_stock_pdf' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$visibleId, $hiddenId] as $itemId) {
            DB::table('stock_daily_counts')->insert([
                'branch_id' => 1,
                'stock_item_id' => $itemId,
                'count_date' => '2026-11-11',
                'opening_qty' => 10,
                'received_qty' => 0,
                'spoiled_qty' => 0,
                'closing_qty' => 8,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $rows = (new StockVarianceService())->rowsForBranchDate(1, '2026-11-11', 'missing');
        $this->assertCount(2, $rows);
        $byId = collect($rows)->keyBy('stock_item_id');
        $this->assertTrue($byId[$visibleId]['show_in_stock_pdf']);
        $this->assertFalse($byId[$hiddenId]['show_in_stock_pdf']);

        $report = StocksAuditExport::build('Bamburi', '2026-11-11', $rows);
        $this->assertTrue(StocksAuditExport::isComplete($report));
        $this->assertCount(1, $report['rows']);
        $this->assertSame('Beef Patties', $report['rows'][0]['name']);
        $this->assertSame($visibleId, $report['rows'][0]['stock_item_id']);
    }
}
