<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Support\AdminDashboardSalesKpis;
use App\Support\AdminSaleReportSummary;
use App\Support\PosOrderTypes;
use App\Support\PosSaleTime;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminSaleReportCashierDiscountsTest extends TestCase
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

        Schema::dropIfExists('order_details');
        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('branch_id')->default(1);
            $table->string('order_type')->default('pos');
            $table->string('sales_channel')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('order_status')->default('delivered');
            $table->timestamp('cancelled_at')->nullable();
            $table->decimal('order_amount', 12, 2)->default(0);
            $table->decimal('extra_discount', 12, 2)->default(0);
            $table->decimal('coupon_discount_amount', 12, 2)->default(0);
            $table->decimal('referral_discount', 12, 2)->default(0);
            $table->decimal('total_tax_amount', 12, 2)->default(0);
            $table->decimal('delivery_charge', 12, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('discount_on_product', 12, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->decimal('add_on_tax_amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('order_details');
        Schema::dropIfExists('orders');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_extra_discount_of_100_is_cashier_discounts_100(): void
    {
        $summary = AdminSaleReportSummary::fromParts([
            'gross' => 1000,
            'item_discount' => 0,
            'extra_discount' => 100,
            'total_sales' => 900,
        ]);

        $this->assertSame(100.0, $summary['cashier_discounts']);
        $this->assertSame(100.0, $summary['total_discounts']);
        $this->assertSame(900.0, $summary['net_sales']);
        $this->assertSame(900.0, $summary['total_sales']);
    }

    public function test_catalogue_discount_alone_is_zero_cashier_discounts(): void
    {
        $summary = AdminSaleReportSummary::fromParts([
            'gross' => 1000,
            'item_discount' => 200,
            'extra_discount' => 0,
            'total_sales' => 800,
        ]);

        $this->assertSame(0.0, $summary['cashier_discounts']);
        $this->assertSame(200.0, $summary['total_discounts']);
        $this->assertSame(800.0, $summary['net_sales']);
        $this->assertSame(800.0, $summary['total_sales']);
    }

    public function test_catalogue_200_and_extra_100_split_total_and_cashier_discounts(): void
    {
        $summary = AdminSaleReportSummary::fromParts([
            'gross' => 2380,
            'item_discount' => 200,
            'extra_discount' => 100,
            'coupon_discount' => 0,
            'referral_discount' => 0,
            'total_sales' => 2180,
        ]);

        $this->assertSame(300.0, $summary['total_discounts']);
        $this->assertSame(100.0, $summary['cashier_discounts']);
        $this->assertSame(2080.0, $summary['net_sales']);
        $this->assertSame(2180.0, $summary['total_sales']);
        $this->assertSame(2380.0, $summary['gross_sales']);
    }

    public function test_coupon_and_referral_do_not_count_as_cashier_discounts(): void
    {
        $summary = AdminSaleReportSummary::fromParts([
            'gross' => 1000,
            'item_discount' => 0,
            'extra_discount' => 0,
            'coupon_discount' => 50,
            'referral_discount' => 10,
            'total_sales' => 940,
        ]);

        $this->assertSame(0.0, $summary['cashier_discounts']);
        $this->assertSame(60.0, $summary['total_discounts']);
        $this->assertSame(940.0, $summary['net_sales']);
        $this->assertSame(940.0, $summary['total_sales']);
    }

    public function test_cancelled_and_online_extra_discount_are_excluded_from_cashier_discounts(): void
    {
        $day = Carbon::parse('2026-09-13 12:00:00', 'Africa/Nairobi');
        $this->insertOrder(1, 'pos', 'takeaway', 2080, 100, 0, 0, 'delivered', null, $day);
        $this->insertDetail(1, 2380, 200, 1);
        $this->insertOrder(2, 'pos', 'takeaway', 750, 50, 0, 0, 'canceled', $day, $day);
        $this->insertDetail(2, 800, 0, 1);
        $this->insertOrder(3, 'delivery', null, 500, 80, 0, 0, 'delivered', null, $day);
        $this->insertDetail(3, 580, 0, 1);
        $this->insertOrder(4, 'pos', 'takeaway', 400, 25, 0, 0, 'confirmed', $day, $day);
        $this->insertDetail(4, 425, 0, 1);

        $ids = $this->validSaleReportIds($day);
        $summary = $this->summaryForIds($ids);

        $this->assertSame([1], $ids);
        $this->assertSame(100.0, $summary['cashier_discounts']);
        $this->assertSame(300.0, $summary['total_discounts']);
        $this->assertSame(2380.0, $summary['gross_sales']);
        $this->assertSame(2080.0, $summary['net_sales']);
        $this->assertSame(2180.0, $summary['total_sales']);
    }

    public function test_payment_totals_and_existing_kpis_are_unchanged_when_cashier_discounts_are_present(): void
    {
        $summary = AdminSaleReportSummary::fromParts([
            'gross' => 1000,
            'item_discount' => 80,
            'extra_discount' => 20,
            'coupon_discount' => 50,
            'referral_discount' => 10,
            'tax' => 40,
            'delivery_fees' => 150,
            'total_sales' => 920,
        ]);
        $groups = AdminSaleReportSummary::fromPaymentTotals([
            'cash' => 400,
            'card' => 100,
            'mpesa' => 50,
            'paystack' => 0,
            'glovo' => 200,
            'uber' => 100,
            'bolt_food' => 50,
        ]);

        $this->assertSame(160.0, $summary['total_discounts']);
        $this->assertSame(20.0, $summary['cashier_discounts']);
        $this->assertSame(840.0, $summary['net_sales']);
        $this->assertSame(920.0, $summary['total_sales']);
        $this->assertSame(40.0, $summary['tax']);
        $this->assertSame(150.0, $summary['delivery_fees']);
        $this->assertSame(550.0, $groups['munch_sales']);
        $this->assertSame(350.0, $groups['marketplace_sales']);
    }

    public function test_controller_reuses_extra_discount_sum_and_exports_do_not_gain_summary_kpis(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/ReportController.php'));
        $export = file_get_contents(app_path('Support/AdminSaleReportExport.php'));
        $pdf = file_get_contents(resource_path('views/admin-views/report/partials/_sale-report-export.blade.php'));

        $this->assertStringContainsString('COALESCE(SUM(extra_discount), 0) as extra_discount', $controller);
        $this->assertStringContainsString("'extra_discount' => \$orderSums->extra_discount ?? 0", $controller);
        $this->assertStringContainsString('cashier_discounts', file_get_contents(app_path('Support/AdminSaleReportSummary.php')));
        $this->assertStringNotContainsString('cashier_discounts', $export);
        $this->assertStringNotContainsString('Cashier Discounts', $pdf);
        $this->assertStringNotContainsString('total_discounts', $export);
    }

    /**
     * @return list<int>
     */
    private function validSaleReportIds(Carbon $day): array
    {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();
        $query = PosSaleTime::constrainBusinessPeriod(Order::query()->pos(), $from, $to);
        $query->where('branch_id', 1);
        PosOrderTypes::constrainSaleReportChannel($query, 'all');
        AdminDashboardSalesKpis::constrainNotVoided($query);

        return $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<int>  $orderIds
     * @return array{gross_sales: float, total_discounts: float, cashier_discounts: float, net_sales: float, tax: float, delivery_fees: float, total_sales: float}
     */
    private function summaryForIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return AdminSaleReportSummary::fromParts(['total_sales' => 0]);
        }

        $orderSums = DB::table('orders')
            ->whereIn('id', $orderIds)
            ->selectRaw('COALESCE(SUM(extra_discount), 0) as extra_discount')
            ->selectRaw('COALESCE(SUM(coupon_discount_amount), 0) as coupon_discount')
            ->selectRaw('COALESCE(SUM(referral_discount), 0) as referral_discount')
            ->selectRaw('COALESCE(SUM(total_tax_amount), 0) as tax')
            ->selectRaw('COALESCE(SUM(delivery_charge), 0) as delivery_fees')
            ->first();

        $detailSums = DB::table('order_details')
            ->whereIn('order_id', $orderIds)
            ->selectRaw('COALESCE(SUM(price * quantity), 0) as gross')
            ->selectRaw('COALESCE(SUM(discount_on_product * quantity), 0) as item_discount')
            ->selectRaw('COALESCE(SUM(add_on_tax_amount), 0) as addon_tax')
            ->first();

        $totalSold = 0.0;
        foreach (DB::table('order_details')->whereIn('order_id', $orderIds)->get() as $detail) {
            $totalSold += ((float) $detail->price - (float) $detail->discount_on_product) * (int) $detail->quantity;
        }

        return AdminSaleReportSummary::fromParts([
            'gross' => $detailSums->gross ?? 0,
            'item_discount' => $detailSums->item_discount ?? 0,
            'extra_discount' => $orderSums->extra_discount ?? 0,
            'coupon_discount' => $orderSums->coupon_discount ?? 0,
            'referral_discount' => $orderSums->referral_discount ?? 0,
            'tax' => ((float) ($orderSums->tax ?? 0)) + (float) ($detailSums->addon_tax ?? 0),
            'delivery_fees' => $orderSums->delivery_fees ?? 0,
            'total_sales' => $totalSold,
        ]);
    }

    private function insertOrder(
        int $id,
        string $type,
        ?string $channel,
        float $amount,
        float $extraDiscount,
        float $coupon,
        float $referral,
        string $status,
        ?Carbon $cancelledAt,
        Carbon $createdAt
    ): void {
        DB::table('orders')->insert([
            'id' => $id,
            'branch_id' => 1,
            'order_type' => $type,
            'sales_channel' => $channel,
            'payment_method' => 'cash',
            'order_status' => $status,
            'cancelled_at' => $cancelledAt,
            'order_amount' => $amount,
            'extra_discount' => $extraDiscount,
            'coupon_discount_amount' => $coupon,
            'referral_discount' => $referral,
            'total_tax_amount' => 0,
            'delivery_charge' => 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function insertDetail(int $orderId, float $price, float $itemDiscount, int $quantity): void
    {
        DB::table('order_details')->insert([
            'order_id' => $orderId,
            'price' => $price,
            'discount_on_product' => $itemDiscount,
            'quantity' => $quantity,
            'add_on_tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
