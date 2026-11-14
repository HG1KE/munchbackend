<?php

namespace Tests\Unit;

use App\Model\StockDailyCount;
use App\Model\StockItem;
use App\Model\StockManager;
use App\Services\Stock\StockCountPersistService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockCountPersistServiceTest extends TestCase
{
    private string $previousConnection;

    private StockCountPersistService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = config('database.default');
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('stock_managers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('all_branches')->default(false);
            $table->boolean('status')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('stock_items', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('status')->default(true);
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
            $table->unsignedBigInteger('stock_manager_id')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'stock_item_id', 'count_date'], 'stock_daily_counts_unique');
        });

        $this->service = new StockCountPersistService;
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('stock_daily_counts');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('stock_managers');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    private function manager(): StockManager
    {
        return StockManager::query()->create([
            'name' => 'Mgr',
            'email' => 'mgr-persist@example.com',
            'password' => 'secret',
            'all_branches' => true,
            'status' => true,
        ]);
    }

    public function test_partial_save_allows_incomplete_single_row(): void
    {
        $itemA = StockItem::query()->create(['name' => 'Buns', 'status' => true]);
        $itemB = StockItem::query()->create(['name' => 'Patties', 'status' => true]);
        $manager = $this->manager();

        $result = $this->service->persistPartial($manager, 4, '2026-11-13', [
            'opening' => [(string) $itemA->id => '12.5'],
            'received' => [],
            'spoiled' => [],
            'closing' => [],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame([(int) $itemA->id], $result['saved']);

        $row = StockDailyCount::query()
            ->where('branch_id', 4)
            ->where('stock_item_id', $itemA->id)
            ->whereDate('count_date', '2026-11-13')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(12.5, (float) $row->opening_qty);
        $this->assertNull($row->received_qty);
        $this->assertNull($row->spoiled_qty);
        $this->assertNull($row->closing_qty);
        $this->assertSame((int) $manager->id, (int) $row->stock_manager_id);

        $this->assertSame(0, StockDailyCount::query()->where('stock_item_id', $itemB->id)->count());
    }

    public function test_partial_save_merges_fields_without_wiping_siblings(): void
    {
        $item = StockItem::query()->create(['name' => 'Cheese', 'status' => true]);
        $manager = $this->manager();

        $this->service->persistPartial($manager, 1, '2026-11-13', [
            'opening' => [$item->id => '10'],
            'received' => [$item->id => '2'],
            'spoiled' => [],
            'closing' => [],
        ]);

        $result = $this->service->persistPartial($manager, 1, '2026-11-13', [
            'spoiled' => [$item->id => '1'],
            'closing' => [$item->id => '7'],
        ]);

        $this->assertTrue($result['ok']);
        $row = StockDailyCount::query()->where('stock_item_id', $item->id)->first();
        $this->assertSame(10.0, (float) $row->opening_qty);
        $this->assertSame(2.0, (float) $row->received_qty);
        $this->assertSame(1.0, (float) $row->spoiled_qty);
        $this->assertSame(7.0, (float) $row->closing_qty);
    }

    public function test_partial_save_empty_string_clears_field_to_null(): void
    {
        $item = StockItem::query()->create(['name' => 'Lettuce', 'status' => true]);
        $manager = $this->manager();

        StockDailyCount::query()->create([
            'branch_id' => 2,
            'stock_item_id' => $item->id,
            'count_date' => '2026-11-13',
            'opening_qty' => 5,
            'received_qty' => 1,
            'spoiled_qty' => 0,
            'closing_qty' => 3,
            'stock_manager_id' => $manager->id,
        ]);

        $result = $this->service->persistPartial($manager, 2, '2026-11-13', [
            'closing' => [$item->id => ''],
        ]);

        $this->assertTrue($result['ok']);
        $row = StockDailyCount::query()->where('stock_item_id', $item->id)->first();
        $this->assertSame(5.0, (float) $row->opening_qty);
        $this->assertNull($row->closing_qty);
    }

    public function test_complete_save_still_requires_all_boxes(): void
    {
        $item = StockItem::query()->create(['name' => 'Tomato', 'status' => true]);
        $manager = $this->manager();

        $error = $this->service->persistComplete($manager, 3, '2026-11-13', [
            'opening' => [$item->id => '1'],
            'received' => [$item->id => ''],
            'spoiled' => [$item->id => '0'],
            'closing' => [$item->id => '1'],
        ]);

        $this->assertSame('Fill in all boxes before saving', $error);
        $this->assertSame(0, StockDailyCount::query()->count());
    }

    public function test_complete_and_partial_reject_negative_qty(): void
    {
        $item = StockItem::query()->create(['name' => 'Onion', 'status' => true]);
        $manager = $this->manager();

        $error = $this->service->persistComplete($manager, 3, '2026-11-13', [
            'opening' => [$item->id => '-1'],
            'received' => [$item->id => '0'],
            'spoiled' => [$item->id => '0'],
            'closing' => [$item->id => '0'],
        ]);
        $this->assertNotNull($error);
        $this->assertStringContainsString('cannot be negative', (string) $error);
        $this->assertSame(0, StockDailyCount::query()->count());

        $partial = $this->service->persistPartial($manager, 3, '2026-11-13', [
            'received' => [$item->id => '-0.5'],
        ]);
        $this->assertFalse($partial['ok']);
        $this->assertStringContainsString('cannot be negative', (string) $partial['error']);
    }

    public function test_dashboard_js_wires_partial_autosave_flag(): void
    {
        $js = file_get_contents(base_path('public/assets/admin/js/munch-stocks-dashboard.js'));
        $this->assertStringContainsString("body.set('partial', '1')", $js);
        $this->assertStringContainsString('Saving…', $js);
        $this->assertStringContainsString('createAutosave', $js);
        $this->assertStringNotContainsString('munch-stocks-draft:', $js);

        $blade = file_get_contents(resource_path('views/stocks-views/dashboard/index.blade.php'));
        $this->assertStringContainsString('munch-stocks-save-status', $blade);
        $this->assertStringContainsString('btn-outline-secondary', $blade);

        $controller = file_get_contents(app_path('Http/Controllers/Stocks/DashboardController.php'));
        $this->assertStringContainsString('persistPartial', $controller);
        $this->assertStringContainsString("boolean('partial')", $controller);
    }
}
