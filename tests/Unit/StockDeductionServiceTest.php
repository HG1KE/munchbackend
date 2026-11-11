<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Services\Stock\StockDeductionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockDeductionServiceTest extends TestCase
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

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('order_status')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('product_stock_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('stock_item_id');
            $table->decimal('quantity', 12, 3)->default(1);
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
            $table->unique(['order_id', 'product_id', 'stock_item_id'], 'stock_sale_deductions_unique');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('stock_sale_deductions');
        Schema::dropIfExists('product_stock_allocations');
        Schema::dropIfExists('order_details');
        Schema::dropIfExists('orders');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    private function seedOrder(array $order, array $details = [], array $allocations = []): Order
    {
        $orderId = DB::table('orders')->insertGetId(array_merge([
            'branch_id' => 5,
            'order_status' => 'delivered',
            'placed_at' => '2026-11-11 10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ], $order));

        foreach ($allocations as $allocation) {
            DB::table('product_stock_allocations')->insert(array_merge([
                'created_at' => now(),
                'updated_at' => now(),
            ], $allocation));
        }

        foreach ($details as $detail) {
            DB::table('order_details')->insert(array_merge([
                'order_id' => $orderId,
                'created_at' => now(),
                'updated_at' => now(),
            ], $detail));
        }

        return Order::query()->findOrFail($orderId);
    }

    public function test_sold_order_deducts_allocation_times_line_qty(): void
    {
        $order = $this->seedOrder([], [
            ['product_id' => 10, 'quantity' => 3],
        ], [
            ['product_id' => 10, 'stock_item_id' => 1, 'quantity' => 2],
            ['product_id' => 10, 'stock_item_id' => 2, 'quantity' => 2],
        ]);

        (new StockDeductionService())->syncOrder($order->fresh(['details']));

        $rows = DB::table('stock_sale_deductions')->where('order_id', $order->id)->orderBy('stock_item_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(6.0, (float) $rows[0]->quantity);
        $this->assertSame(1, (int) $rows[0]->stock_item_id);
        $this->assertSame(6.0, (float) $rows[1]->quantity);
        $this->assertSame('2026-11-11', $rows[0]->sale_date);
    }

    public function test_sync_is_idempotent_and_edit_recalculates(): void
    {
        $order = $this->seedOrder([], [
            ['product_id' => 10, 'quantity' => 1],
        ], [
            ['product_id' => 10, 'stock_item_id' => 1, 'quantity' => 2],
        ]);

        $service = new StockDeductionService();
        $service->syncOrder($order->fresh(['details']));
        $service->syncOrder($order->fresh(['details']));

        $this->assertSame(1, (int) DB::table('stock_sale_deductions')->where('order_id', $order->id)->count());
        $this->assertSame(2.0, (float) DB::table('stock_sale_deductions')->where('order_id', $order->id)->value('quantity'));

        DB::table('order_details')->where('order_id', $order->id)->update(['quantity' => 4]);
        $service->syncOrder($order->fresh(['details']));

        $this->assertSame(1, (int) DB::table('stock_sale_deductions')->where('order_id', $order->id)->count());
        $this->assertSame(8.0, (float) DB::table('stock_sale_deductions')->where('order_id', $order->id)->value('quantity'));
    }

    public function test_cancel_clears_deductions(): void
    {
        $order = $this->seedOrder([], [
            ['product_id' => 10, 'quantity' => 2],
        ], [
            ['product_id' => 10, 'stock_item_id' => 1, 'quantity' => 1],
        ]);

        $service = new StockDeductionService();
        $service->syncOrder($order->fresh(['details']));
        $this->assertSame(1, (int) DB::table('stock_sale_deductions')->where('order_id', $order->id)->count());

        DB::table('orders')->where('id', $order->id)->update(['order_status' => 'canceled']);
        $service->syncOrder($order->fresh(['details']));

        $this->assertSame(0, (int) DB::table('stock_sale_deductions')->where('order_id', $order->id)->count());
    }

    public function test_pending_does_not_deduct(): void
    {
        $order = $this->seedOrder(['order_status' => 'pending'], [
            ['product_id' => 10, 'quantity' => 2],
        ], [
            ['product_id' => 10, 'stock_item_id' => 1, 'quantity' => 1],
        ]);

        (new StockDeductionService())->syncOrder($order->fresh(['details']));
        $this->assertSame(0, (int) DB::table('stock_sale_deductions')->count());
    }

    public function test_wiring_presence(): void
    {
        $observer = file_get_contents(app_path('Observers/OrderObserver.php'));
        $this->assertStringContainsString('StockDeductionService', $observer);

        $pos = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $this->assertStringContainsString('StockDeductionService', $pos);

        $edit = file_get_contents(app_path('Services/PosOrderEditService.php'));
        $this->assertStringContainsString('StockDeductionService', $edit);

        $routes = file_get_contents(base_path('routes/stocks.php'));
        $this->assertStringContainsString("'as' => 'stocks.'", $routes);
        $this->assertStringContainsString("->name('dashboard')", $routes);
        $this->assertStringContainsString('mapStocksRoutes', file_get_contents(app_path('Providers/RouteServiceProvider.php')));
    }
}
