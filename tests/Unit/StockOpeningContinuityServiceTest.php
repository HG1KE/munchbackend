<?php

namespace Tests\Unit;

use App\Model\StockDailyCount;
use App\Model\StockItem;
use App\Services\Stock\StockOpeningContinuityService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockOpeningContinuityServiceTest extends TestCase
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
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('stock_daily_counts');
        Schema::dropIfExists('stock_items');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_flags_opening_not_equal_yesterday_closing(): void
    {
        $item = StockItem::query()->create(['name' => 'Buns', 'status' => true]);
        StockDailyCount::query()->create([
            'branch_id' => 1,
            'stock_item_id' => $item->id,
            'count_date' => '2026-11-12',
            'opening_qty' => 10,
            'received_qty' => 0,
            'spoiled_qty' => 0,
            'closing_qty' => 8,
        ]);
        StockDailyCount::query()->create([
            'branch_id' => 1,
            'stock_item_id' => $item->id,
            'count_date' => '2026-11-13',
            'opening_qty' => 7,
            'received_qty' => 0,
            'spoiled_qty' => 0,
            'closing_qty' => null,
        ]);

        $gaps = (new StockOpeningContinuityService())->mismatches(1, '2026-11-13');
        $this->assertCount(1, $gaps);
        $this->assertSame(8.0, $gaps[0]['yesterday_closing']);
        $this->assertSame(7.0, $gaps[0]['today_opening']);
        $this->assertSame(-1.0, $gaps[0]['delta']);
    }

    public function test_tolerance_ignores_tiny_float_noise(): void
    {
        $item = StockItem::query()->create(['name' => 'Patties', 'status' => true]);
        StockDailyCount::query()->create([
            'branch_id' => 2,
            'stock_item_id' => $item->id,
            'count_date' => '2026-11-12',
            'closing_qty' => 5,
        ]);
        StockDailyCount::query()->create([
            'branch_id' => 2,
            'stock_item_id' => $item->id,
            'count_date' => '2026-11-13',
            'opening_qty' => 5.0004,
        ]);

        $gaps = (new StockOpeningContinuityService())->mismatches(2, '2026-11-13');
        $this->assertSame([], $gaps);
    }
}
