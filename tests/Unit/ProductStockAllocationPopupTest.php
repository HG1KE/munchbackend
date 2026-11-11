<?php

namespace Tests\Unit;

use App\Model\Product;
use App\Services\Stock\ProductStockAllocationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductStockAllocationPopupTest extends TestCase
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
            $table->timestamps();
        });
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('product_stock_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('stock_item_id');
            $table->decimal('quantity', 12, 3);
            $table->timestamps();
            $table->unique(['product_id', 'stock_item_id']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('product_stock_allocations');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('products');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_payload_and_sync_replace_allocations(): void
    {
        $productId = DB::table('products')->insertGetId([
            'name' => 'Double Burger',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bunsId = DB::table('stock_items')->insertGetId([
            'name' => 'Buns',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pattiesId = DB::table('stock_items')->insertGetId([
            'name' => 'Beef Patties',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('stock_items')->insert([
            'name' => 'Inactive Fries',
            'status' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('product_stock_allocations')->insert([
            'product_id' => $productId,
            'stock_item_id' => $bunsId,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::query()->findOrFail($productId);
        $service = new ProductStockAllocationService();
        $payload = $service->payloadForProduct($product);

        $this->assertSame($productId, $payload['product']['id']);
        $this->assertSame('Double Burger', $payload['product']['name']);
        $this->assertCount(2, $payload['stock_items']);
        $this->assertSame([$bunsId], array_column($payload['allocations'], 'stock_item_id'));

        $request = Request::create('/admin/product/stock-allocations/'.$productId, 'POST', [
            'stock_allocation_item_id' => [$bunsId, $pattiesId, ''],
            'stock_allocation_qty' => [2, 2, 9],
        ]);
        $service->syncFromRequest($productId, $request);

        $rows = DB::table('product_stock_allocations')
            ->where('product_id', $productId)
            ->orderBy('stock_item_id')
            ->get();
        $this->assertCount(2, $rows);
        $this->assertEquals(2.0, (float) $rows->firstWhere('stock_item_id', $bunsId)->quantity);
        $this->assertEquals(2.0, (float) $rows->firstWhere('stock_item_id', $pattiesId)->quantity);

        $after = $service->payloadForProduct($product->fresh());
        $this->assertCount(2, $after['allocations']);
    }

    public function test_list_popup_assets_and_routes_exist(): void
    {
        $this->assertFileExists(public_path('assets/admin/js/munch-product-stock.js'));
        $this->assertFileExists(public_path('assets/admin/css/munch-product-stock.css'));
        $this->assertFileExists(app_path('Http/Controllers/Admin/ProductStockAllocationController.php'));

        $routes = file_get_contents(base_path('routes/admin.php'));
        $this->assertStringContainsString("name('stock-allocations.show')", $routes);
        $this->assertStringContainsString("name('stock-allocations.update')", $routes);

        $js = file_get_contents(public_path('assets/admin/js/munch-product-stock.js'));
        $this->assertStringContainsString('stock_allocation_item_id[]', $js);
        $this->assertStringContainsString('stock_allocation_qty[]', $js);
        $this->assertStringContainsString('[data-stock]', $js);
        $this->assertStringContainsString('select2', $js);
        $this->assertStringContainsString('dropdownParent', $js);
        $this->assertStringContainsString('step="0.001"', $js);
        $this->assertStringContainsString('min="0.001"', $js);
    }

    public function test_sync_accepts_decimal_qty(): void
    {
        $productId = DB::table('products')->insertGetId([
            'name' => 'Half Bun Special',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bunsId = DB::table('stock_items')->insertGetId([
            'name' => 'Buns',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = new ProductStockAllocationService();
        $request = Request::create('/admin/product/stock-allocations/'.$productId, 'POST', [
            'stock_allocation_item_id' => [$bunsId],
            'stock_allocation_qty' => [0.5],
        ]);
        $service->syncFromRequest($productId, $request);

        $row = DB::table('product_stock_allocations')->where('product_id', $productId)->first();
        $this->assertNotNull($row);
        $this->assertEquals(0.5, (float) $row->quantity);
    }
}
