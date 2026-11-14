<?php

namespace Tests\Unit;

use App\Services\Stock\BranchStockItemVisibility;
use App\Services\Stock\StockVarianceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchStockItemVisibilityTest extends TestCase
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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamps();
        });
        Schema::create('product_by_branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id');
            $table->tinyInteger('is_available')->default(1);
            $table->timestamps();
        });
        Schema::create('product_stock_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('stock_item_id');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->timestamps();
            $table->unique(['product_id', 'stock_item_id']);
        });
        Schema::create('addon_stock_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('addon_id');
            $table->unsignedBigInteger('stock_item_id');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->timestamps();
            $table->unique(['addon_id', 'stock_item_id']);
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
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('stock_daily_counts');
        Schema::dropIfExists('addon_stock_allocations');
        Schema::dropIfExists('product_stock_allocations');
        Schema::dropIfExists('product_by_branches');
        Schema::dropIfExists('products');
        Schema::dropIfExists('stock_items');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    private function stockItem(string $name): int
    {
        return (int) DB::table('stock_items')->insertGetId([
            'name' => $name,
            'status' => 1,
            'show_in_stock_pdf' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(string $name, int $status = 1): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => $name,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function branchAvailability(int $productId, int $branchId, int $available): void
    {
        DB::table('product_by_branches')->insert([
            'product_id' => $productId,
            'branch_id' => $branchId,
            'is_available' => $available,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function allocateProduct(int $productId, int $stockItemId): void
    {
        DB::table('product_stock_allocations')->insert([
            'product_id' => $productId,
            'stock_item_id' => $stockItemId,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_omits_stock_item_when_only_allocated_to_branch_disabled_products(): void
    {
        $branchA = 10;
        $branchB = 20;

        $foil = $this->stockItem('Foil Sheets'); // unallocated — always keep
        $buns = $this->stockItem('Burger Buns');
        $wrap = $this->stockItem('Wrap Tortilla');
        $shared = $this->stockItem('Shared Oil');

        $burger = $this->product('Classic Burger');
        $wrapProduct = $this->product('Chicken Wrap');
        $sharedProduct = $this->product('Shared Special');

        // Burger sold only at branch B
        $this->branchAvailability($burger, $branchA, 0);
        $this->branchAvailability($burger, $branchB, 1);
        $this->allocateProduct($burger, $buns);

        // Wrap sold only at branch A
        $this->branchAvailability($wrapProduct, $branchA, 1);
        $this->branchAvailability($wrapProduct, $branchB, 0);
        $this->allocateProduct($wrapProduct, $wrap);

        // Shared sold at both
        $this->branchAvailability($sharedProduct, $branchA, 1);
        $this->branchAvailability($sharedProduct, $branchB, 1);
        $this->allocateProduct($sharedProduct, $shared);

        $visibility = new BranchStockItemVisibility;
        $all = [$foil, $buns, $wrap, $shared];

        $atA = $visibility->filterForBranch($branchA, $all);
        $this->assertSame([$foil, $wrap, $shared], $atA);

        $atB = $visibility->filterForBranch($branchB, $all);
        $this->assertSame([$foil, $buns, $shared], $atB);
    }

    public function test_keeps_item_when_any_enabled_product_or_addon_allocates_it(): void
    {
        $branchId = 7;
        $item = $this->stockItem('Cheese Slices');
        $disabled = $this->product('Disabled Burger');
        $enabled = $this->product('Enabled Burger');

        $this->branchAvailability($disabled, $branchId, 0);
        $this->branchAvailability($enabled, $branchId, 1);
        $this->allocateProduct($disabled, $item);
        $this->allocateProduct($enabled, $item);

        $visibility = new BranchStockItemVisibility;
        $this->assertSame([$item], $visibility->filterForBranch($branchId, [$item]));

        // Addon allocation alone keeps an item even if product side is disabled-only.
        $addonOnly = $this->stockItem('Addon Sauce Base');
        $disabledOnly = $this->product('Off Menu');
        $this->branchAvailability($disabledOnly, $branchId, 0);
        $this->allocateProduct($disabledOnly, $addonOnly);
        DB::table('addon_stock_allocations')->insert([
            'addon_id' => 99,
            'stock_item_id' => $addonOnly,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame([$addonOnly], $visibility->filterForBranch($branchId, [$addonOnly]));
    }

    public function test_omits_when_product_globally_inactive_even_if_branch_row_available(): void
    {
        $branchId = 3;
        $item = $this->stockItem('Ghost Patty');
        $product = $this->product('Ghost Burger', 0);
        $this->branchAvailability($product, $branchId, 1);
        $this->allocateProduct($product, $item);

        $visibility = new BranchStockItemVisibility;
        $this->assertSame([], $visibility->filterForBranch($branchId, [$item]));
    }

    public function test_variance_rows_for_branch_omit_disabled_only_allocations(): void
    {
        $branchId = 4;
        $keep = $this->stockItem('Keep Me');
        $omit = $this->stockItem('Omit Me');

        $enabled = $this->product('On Menu');
        $disabled = $this->product('Off Menu');
        $this->branchAvailability($enabled, $branchId, 1);
        $this->branchAvailability($disabled, $branchId, 0);
        $this->allocateProduct($enabled, $keep);
        $this->allocateProduct($disabled, $omit);

        $rows = (new StockVarianceService)->rowsForBranchDate($branchId, '2026-11-14', 'missing');
        $ids = array_column($rows, 'stock_item_id');
        $this->assertContains($keep, $ids);
        $this->assertNotContains($omit, $ids);
    }

    public function test_persist_and_opening_services_reference_visibility(): void
    {
        $persist = file_get_contents(app_path('Services/Stock/StockCountPersistService.php'));
        $this->assertStringContainsString('BranchStockItemVisibility', $persist);

        $opening = file_get_contents(app_path('Services/Stock/StockOpeningContinuityService.php'));
        $this->assertStringContainsString('BranchStockItemVisibility', $opening);

        $variance = file_get_contents(app_path('Services/Stock/StockVarianceService.php'));
        $this->assertStringContainsString('BranchStockItemVisibility', $variance);
    }
}
