<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Support\PosOrderTypes;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminSaleReportPosFilterTest extends TestCase
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

        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('branch_id')->default(1);
            $table->string('order_type')->default('pos');
            $table->string('sales_channel')->nullable();
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

    public function test_pos_filter_includes_every_pos_channel_and_excludes_website_orders(): void
    {
        $today = Carbon::parse('2026-09-12 10:00:00');
        $yesterday = Carbon::parse('2026-09-11 10:00:00');

        $this->insert(1, 'dine_in', 'dine_in', 100, 1, $today);
        $this->insert(2, 'pos', 'takeaway', 200, 1, $today);
        $this->insert(3, 'pos', 'delivery', 300, 1, $today);
        $this->insert(4, 'pos', 'uber', 40, 1, $today);
        $this->insert(5, 'pos', 'glovo', 50, 1, $today);
        $this->insert(6, 'pos', 'bolt_food', 60, 1, $today);
        $this->insert(7, 'delivery', null, 999, 1, $today);
        $this->insert(8, 'take_away', null, 888, 1, $today);
        $this->insert(9, 'pos', 'takeaway', 15, 2, $today);
        $this->insert(10, 'pos', 'takeaway', 25, 1, $yesterday);

        $ids = $this->filteredIds('pos', 1, $today->copy()->startOfDay(), $today->copy()->endOfDay());

        $this->assertSame([1, 2, 3, 4, 5, 6], $ids);
        $this->assertContains(1, $ids, 'POS Dine In');
        $this->assertContains(2, $ids, 'POS Take Away');
        $this->assertContains(3, $ids, 'POS Delivery');
        $this->assertContains(4, $ids, 'POS Uber');
        $this->assertContains(5, $ids, 'POS Glovo');
        $this->assertContains(6, $ids, 'POS Bolt Food');
        $this->assertNotContains(7, $ids, 'website delivery must stay out of POS');
        $this->assertNotContains(8, $ids, 'website take away must stay out of POS');
        $this->assertNotContains(9, $ids, 'other branch must stay out when branch is filtered');
        $this->assertNotContains(10, $ids, 'outside the date range must stay out');
        $this->assertCount(6, $ids);
        $this->assertSame(6, count(array_unique($ids)), 'POS rows must not be double-counted');
        $this->assertSame(750.0, $this->amountTotal($ids));
    }

    public function test_specific_channel_filters_still_use_sales_channel(): void
    {
        $today = Carbon::parse('2026-09-12 10:00:00');
        $this->insert(1, 'pos', 'delivery', 300, 1, $today);
        $this->insert(2, 'delivery', null, 999, 1, $today);
        $this->insert(3, 'pos', 'uber', 40, 1, $today);
        $this->insert(4, 'pos', 'takeaway', 200, 1, $today);
        $this->insert(5, 'dine_in', 'dine_in', 100, 1, $today);

        $from = $today->copy()->startOfDay();
        $to = $today->copy()->endOfDay();

        $this->assertSame([1], $this->filteredIds('delivery', 1, $from, $to));
        $this->assertSame([3], $this->filteredIds('uber', 1, $from, $to));
        $this->assertSame([4], $this->filteredIds('takeaway', 1, $from, $to));
        $this->assertSame([5], $this->filteredIds('dine_in', 1, $from, $to));
        $this->assertSame([1, 2, 3, 4, 5], $this->filteredIds('all', 1, $from, $to));
    }

    public function test_sale_report_controller_uses_the_pos_family_constraint(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/ReportController.php'));
        $this->assertStringContainsString('PosOrderTypes::constrainSaleReportChannel($query, $channel)', $controller);
        $this->assertStringContainsString('$this->saleReportOrderQuery($request, $fromDate, $toDate)', $controller);
        $this->assertStringContainsString("session()->put('export_sale_data', \$data)", $controller);
        $this->assertStringContainsString("session()->put('export_sale_summary', \$summaryDisplay)", $controller);
        $this->assertTrue(method_exists(PosOrderTypes::class, 'constrainSaleReportChannel'));
        $this->assertTrue(method_exists(Order::class, 'scopePos'));
    }

    /**
     * @return list<int>
     */
    private function filteredIds(string $channel, int|string $branchId, Carbon $from, Carbon $to): array
    {
        $query = Order::query()->whereBetween('created_at', [$from, $to]);
        if ($branchId !== 'all') {
            $query->where('branch_id', $branchId);
        }
        PosOrderTypes::constrainSaleReportChannel($query, $channel);

        return $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $ids
     */
    private function amountTotal(array $ids): float
    {
        return (float) Order::query()->whereIn('id', $ids)->sum('order_amount');
    }

    private function insert(int $id, string $type, ?string $channel, float $amount, int $branchId, Carbon $createdAt): void
    {
        DB::table('orders')->insert([
            'id' => $id,
            'branch_id' => $branchId,
            'order_type' => $type,
            'sales_channel' => $channel,
            'payment_method' => 'cash',
            'order_amount' => $amount,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
