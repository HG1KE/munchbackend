<?php

namespace Tests\Unit;

use App\Model\Product;
use App\Services\Stock\VariationStockAllocationService;
use App\Support\ProductVariationPricing;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VariationStockAllocationTest extends TestCase
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

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('status')->default(true);
            $table->text('variations')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('variation_stock_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('option_key');
            $table->string('group_name')->nullable();
            $table->string('option_label')->nullable();
            $table->unsignedBigInteger('stock_item_id');
            $table->decimal('quantity', 12, 3);
            $table->timestamps();
            $table->unique(['product_id', 'option_key', 'stock_item_id']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('variation_stock_allocations');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('products');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    private function seedProductWithSizeOptions(): int
    {
        return DB::table('products')->insertGetId([
            'name' => 'Spicy Twin',
            'status' => 1,
            'variations' => json_encode([
                [
                    'name' => 'Size',
                    'type' => 'single',
                    'values' => [
                        ['label' => 'Regular', 'optionPrice' => 0],
                        ['label' => 'Large', 'optionPrice' => 50],
                    ],
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_payload_and_sync_replace_allocations_per_option(): void
    {
        $productId = $this->seedProductWithSizeOptions();
        $foilId = DB::table('stock_items')->insertGetId([
            'name' => 'Foil',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $boxId = DB::table('stock_items')->insertGetId([
            'name' => 'Large Box',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('stock_items')->insert([
            'name' => 'Inactive',
            'status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new VariationStockAllocationService();
        $product = Product::withoutGlobalScopes()->findOrFail($productId);
        $largeKey = ProductVariationPricing::optionKey('Size', 'Large');
        $regularKey = ProductVariationPricing::optionKey('Size', 'Regular');

        $empty = $service->payloadForProduct($product);
        $this->assertSame($productId, $empty['product']['id']);
        $this->assertCount(2, $empty['stock_items']);
        $this->assertCount(2, $empty['options']);
        $this->assertSame([], $empty['options'][0]['allocations']);

        $service->syncFromRequest($productId, Request::create('/', 'POST', [
            'variation_option_key' => [$largeKey, $largeKey, $regularKey],
            'stock_allocation_item_id' => [$foilId, $boxId, $foilId],
            'stock_allocation_qty' => ['1', '1', '0.5'],
        ]), $product);

        $rows = DB::table('variation_stock_allocations')
            ->where('product_id', $productId)
            ->orderBy('option_key')
            ->orderBy('stock_item_id')
            ->get();
        $this->assertCount(3, $rows);
        $this->assertSame($largeKey, (string) $rows[0]->option_key);
        $this->assertSame('Size', (string) $rows[0]->group_name);
        $this->assertSame('Large', (string) $rows[0]->option_label);

        $payload = $service->payloadForProduct($product->fresh());
        $byId = collect($payload['options'])->keyBy('id');
        $this->assertCount(2, $byId[$largeKey]['allocations']);
        $this->assertCount(1, $byId[$regularKey]['allocations']);
        $this->assertSame(0.5, (float) $byId[$regularKey]['allocations'][0]['quantity']);

        $service->syncFromRequest($productId, Request::create('/', 'POST', [
            'variation_option_key' => [''],
            'stock_allocation_item_id' => [''],
            'stock_allocation_qty' => [''],
        ]), $product);
        $this->assertSame(0, (int) DB::table('variation_stock_allocations')->where('product_id', $productId)->count());
    }

    public function test_list_products_with_variations_and_allocation_counts(): void
    {
        $productId = $this->seedProductWithSizeOptions();
        DB::table('products')->insert([
            'name' => 'Plain Bun',
            'status' => 1,
            'variations' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $stockId = DB::table('stock_items')->insertGetId([
            'name' => 'Foil',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $largeKey = ProductVariationPricing::optionKey('Size', 'Large');
        DB::table('variation_stock_allocations')->insert([
            'product_id' => $productId,
            'option_key' => $largeKey,
            'group_name' => 'Size',
            'option_label' => 'Large',
            'stock_item_id' => $stockId,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $list = (new VariationStockAllocationService())->listProductsWithVariations();
        $this->assertCount(1, $list);
        $this->assertSame($productId, $list[0]['id']);
        $this->assertSame(2, $list[0]['option_count']);
        $this->assertSame(1, $list[0]['allocated_option_count']);
    }

    public function test_selected_option_keys_from_cart_and_resolved_shapes(): void
    {
        $service = new VariationStockAllocationService();
        $cart = (object) [
            'variation' => json_encode([
                [
                    'name' => 'Size',
                    'values' => ['label' => ['Large']],
                ],
            ]),
            'variant' => null,
        ];
        $this->assertSame(
            [ProductVariationPricing::optionKey('Size', 'Large')],
            $service->selectedOptionKeysFromDetail($cart)
        );

        $resolved = (object) [
            'variation' => null,
            'variant' => json_encode([
                [
                    'name' => 'Size',
                    'values' => [
                        ['label' => 'Regular', 'optionPrice' => 0],
                    ],
                ],
            ]),
        ];
        $this->assertSame(
            [ProductVariationPricing::optionKey('Size', 'Regular')],
            $service->selectedOptionKeysFromDetail($resolved)
        );
    }

    public function test_admin_wiring_presence(): void
    {
        $this->assertFileExists(app_path('Http/Controllers/Admin/VariationStockAllocationController.php'));
        $this->assertFileExists(app_path('Model/VariationStockAllocation.php'));
        $this->assertFileExists(resource_path('views/admin-views/product/variation-stock.blade.php'));
        $this->assertFileExists(public_path('assets/admin/js/munch-variation-stock.js'));
        $this->assertFileExists(public_path('assets/admin/css/munch-variation-stock.css'));
        $this->assertFileExists(database_path('migrations/2026_11_14_200000_create_variation_stock_allocations_table.php'));

        $routes = file_get_contents(base_path('routes/admin.php'));
        $this->assertStringContainsString("name('variation-stock.index')", $routes);
        $this->assertStringContainsString("name('variation-stock-allocations.show')", $routes);
        $this->assertStringContainsString("name('variation-stock-allocations.update')", $routes);

        $sidebar = file_get_contents(resource_path('views/layouts/admin/partials/_sidebar.blade.php'));
        $this->assertStringContainsString('admin.product.variation-stock.index', $sidebar);

        $list = file_get_contents(resource_path('views/admin-views/product/list.blade.php'));
        $this->assertStringContainsString('admin.product.variation-stock.index', $list);

        $js = file_get_contents(public_path('assets/admin/js/munch-variation-stock.js'));
        $this->assertStringContainsString('variation_option_key[]', $js);
        $this->assertStringContainsString('stock_allocation_item_id[]', $js);

        $deduction = file_get_contents(app_path('Services/Stock/StockDeductionService.php'));
        $this->assertStringContainsString('VariationStockAllocation', $deduction);
        $this->assertStringContainsString('variationSelections', $deduction);
    }
}
