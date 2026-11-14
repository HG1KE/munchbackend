<?php

namespace Tests\Unit;

use App\Model\AddOn;
use App\Services\Stock\AddonStockAllocationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AddonStockAllocationTest extends TestCase
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

        Schema::create('add_ons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 8, 2)->default(0);
            $table->decimal('tax', 8, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });
        Schema::create('addon_stock_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('addon_id');
            $table->unsignedBigInteger('stock_item_id');
            $table->decimal('quantity', 12, 3);
            $table->timestamps();
            $table->unique(['addon_id', 'stock_item_id']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('addon_stock_allocations');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('add_ons');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_payload_and_sync_replace_allocations(): void
    {
        $addonId = DB::table('add_ons')->insertGetId([
            'name' => 'Extra Cheese',
            'price' => 50,
            'tax' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cheeseId = DB::table('stock_items')->insertGetId([
            'name' => 'Cheese Slice',
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $baconId = DB::table('stock_items')->insertGetId([
            'name' => 'Bacon Strip',
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

        $service = new AddonStockAllocationService();
        $addon = AddOn::withoutGlobalScopes()->findOrFail($addonId);

        $empty = $service->payloadForAddon($addon);
        $this->assertSame($addonId, $empty['addon']['id']);
        $this->assertCount(2, $empty['stock_items']);
        $this->assertSame([], $empty['allocations']);

        $service->syncFromRequest($addonId, Request::create('/', 'POST', [
            'stock_allocation_item_id' => [$cheeseId, $baconId, $cheeseId],
            'stock_allocation_qty' => ['1', '2', '1.5'],
        ]));

        $rows = DB::table('addon_stock_allocations')->where('addon_id', $addonId)->orderBy('stock_item_id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame((int) $baconId, (int) $rows[0]->stock_item_id);
        $this->assertSame(2.0, (float) $rows[0]->quantity);
        $this->assertSame((int) $cheeseId, (int) $rows[1]->stock_item_id);
        $this->assertSame(1.5, (float) $rows[1]->quantity);

        $payload = $service->payloadForAddon($addon->fresh());
        $this->assertCount(2, $payload['allocations']);

        $service->syncFromRequest($addonId, Request::create('/', 'POST', [
            'stock_allocation_item_id' => [''],
            'stock_allocation_qty' => [''],
        ]));
        $this->assertSame(0, (int) DB::table('addon_stock_allocations')->where('addon_id', $addonId)->count());
    }

    public function test_admin_addon_views_include_stock_allocation_partial(): void
    {
        $edit = file_get_contents(resource_path('views/admin-views/addon/edit.blade.php'));
        $index = file_get_contents(resource_path('views/admin-views/addon/index.blade.php'));
        $partial = file_get_contents(resource_path('views/admin-views/addon/partials/_stock-allocations.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Admin/AddonController.php'));

        $this->assertStringContainsString('admin-views.addon.partials._stock-allocations', $edit);
        $this->assertStringContainsString('admin-views.addon.partials._stock-allocations', $index);
        $this->assertStringContainsString('stock_allocation_item_id[]', $partial);
        $this->assertStringContainsString('AddonStockAllocationService', $controller);
        $this->assertStringContainsString('syncFromRequest', $controller);
    }
}
