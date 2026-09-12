<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Services\AdminDashboardSalesKpiService;
use App\Support\AdminDashboardSalesKpis;
use App\Support\PosOrderTypes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminDashboardSalesKpiClassificationTest extends TestCase
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
            $table->string('payment_status')->default('paid');
            $table->string('order_status')->default('confirmed');
            $table->decimal('order_amount', 12, 2)->default(0);
            $table->timestamp('cancelled_at')->nullable();
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

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function munchPaymentMatrix(): array
    {
        return [
            ['dine_in', 'dine_in', 'cash', 'POS Dine In + Cash'],
            ['dine_in', 'dine_in', 'card', 'POS Dine In + Card'],
            ['dine_in', 'dine_in', 'mpesa', 'POS Dine In + M-PESA'],
            ['dine_in', 'dine_in', 'paystack', 'POS Dine In + Paystack'],
            ['pos', 'takeaway', 'cash', 'POS Takeaway + Cash'],
            ['pos', 'takeaway', 'card', 'POS Takeaway + Card'],
            ['pos', 'takeaway', 'mpesa', 'POS Takeaway + M-PESA'],
            ['pos', 'takeaway', 'paystack', 'POS Takeaway + Paystack'],
            ['pos', 'delivery', 'cash', 'POS Delivery + Cash'],
            ['pos', 'delivery', 'card', 'POS Delivery + Card'],
            ['pos', 'delivery', 'mpesa', 'POS Delivery + M-PESA'],
            ['pos', 'delivery', 'paystack', 'POS Delivery + Paystack'],
        ];
    }

    /**
     * @dataProvider munchPaymentMatrix
     */
    public function test_pos_munch_channels_count_as_munch_sales_for_every_valid_payment(
        string $orderType,
        string $channel,
        string $method,
        string $label
    ): void {
        $this->assertSame('munch', AdminDashboardSalesKpis::category($method, $channel, $orderType), $label);
        $this->assertSame(
            in_array($channel, ['dine_in', 'takeaway', 'delivery'], true) ? $channel : 'takeaway',
            PosOrderTypes::saleReportCategory($orderType, $channel)
        );

        $totals = AdminDashboardSalesKpis::fromGroupedRows([[
            'payment_method' => $method,
            'sales_channel' => $channel,
            'order_type' => $orderType,
            'total' => 100,
        ]]);

        $this->assertSame(100.0, $totals['munch_sales'], $label);
        $this->assertSame(0.0, $totals['glovo'], $label);
        $this->assertSame(0.0, $totals['uber'], $label);
        $this->assertSame(0.0, $totals['bolt_food'], $label);
    }

    public function test_confirmed_paid_pos_dine_in_and_delivery_qualify(): void
    {
        $dineIn = $this->makeOrder([
            'order_type' => 'dine_in',
            'sales_channel' => 'dine_in',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);
        $delivery = $this->makeOrder([
            'order_type' => 'pos',
            'sales_channel' => 'delivery',
            'payment_method' => 'mpesa',
            'payment_status' => 'paid',
            'order_status' => 'confirmed',
        ]);

        $this->assertTrue(AdminDashboardSalesKpis::qualifies($dineIn));
        $this->assertTrue(AdminDashboardSalesKpis::qualifies($delivery));
        $this->assertSame('munch', AdminDashboardSalesKpis::category('cash', 'dine_in', 'dine_in'));
        $this->assertSame('munch', AdminDashboardSalesKpis::category('mpesa', 'delivery', 'pos'));
    }

    public function test_marketplace_orders_never_enter_munch_sales(): void
    {
        foreach (['glovo', 'uber', 'bolt_food'] as $market) {
            $this->assertSame($market, AdminDashboardSalesKpis::category($market, $market, 'pos'));
            $totals = AdminDashboardSalesKpis::fromGroupedRows([
                ['payment_method' => $market, 'sales_channel' => $market, 'order_type' => 'pos', 'total' => 250],
                ['payment_method' => 'cash', 'sales_channel' => 'takeaway', 'order_type' => 'pos', 'total' => 10],
            ]);
            $this->assertSame(10.0, $totals['munch_sales'], $market);
            $this->assertSame(250.0, $totals[$market], $market);
        }
    }

    public function test_website_orders_are_excluded_from_munch_pos_kpi(): void
    {
        $this->assertSame('other', AdminDashboardSalesKpis::category('cash', null, 'delivery'));
        $this->assertSame('other', AdminDashboardSalesKpis::category('cash', '', 'take_away'));

        $websiteDelivery = $this->makeOrder([
            'order_type' => 'delivery',
            'sales_channel' => '',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'order_status' => 'delivered',
        ]);
        $websiteTakeaway = $this->makeOrder([
            'order_type' => 'take_away',
            'sales_channel' => null,
            'payment_method' => 'mpesa',
            'payment_status' => 'paid',
            'order_status' => 'delivered',
        ]);

        $this->assertFalse(AdminDashboardSalesKpis::qualifies($websiteDelivery));
        $this->assertFalse(AdminDashboardSalesKpis::qualifies($websiteTakeaway));

        $totals = AdminDashboardSalesKpis::fromGroupedRows([
            ['payment_method' => 'cash', 'sales_channel' => '', 'order_type' => 'delivery', 'total' => 999],
            ['payment_method' => 'mpesa', 'sales_channel' => null, 'order_type' => 'take_away', 'total' => 888],
            ['payment_method' => 'cash', 'sales_channel' => 'takeaway', 'order_type' => 'pos', 'total' => 50],
        ]);
        $this->assertSame(50.0, $totals['munch_sales']);
    }

    public function test_cancelled_and_unpaid_orders_are_excluded(): void
    {
        $cancelled = $this->makeOrder([
            'order_type' => 'dine_in',
            'sales_channel' => 'dine_in',
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'order_status' => 'canceled',
            'cancelled_at' => '2026-09-11 16:00:00',
        ]);
        $unpaid = $this->makeOrder([
            'order_type' => 'dine_in',
            'sales_channel' => 'dine_in',
            'payment_method' => 'cash',
            'payment_status' => 'unpaid',
            'order_status' => 'confirmed',
        ]);
        $failed = $this->makeOrder([
            'order_type' => 'pos',
            'sales_channel' => 'delivery',
            'payment_method' => 'mpesa',
            'payment_status' => 'paid',
            'order_status' => 'failed',
        ]);

        $this->assertFalse(AdminDashboardSalesKpis::qualifies($cancelled));
        $this->assertFalse(AdminDashboardSalesKpis::qualifies($unpaid));
        $this->assertFalse(AdminDashboardSalesKpis::qualifies($failed));
    }

    public function test_each_order_belongs_to_exactly_one_kpi_category(): void
    {
        $rows = [
            ['payment_method' => 'cash', 'sales_channel' => 'dine_in', 'order_type' => 'dine_in', 'total' => 100],
            ['payment_method' => 'paystack', 'sales_channel' => 'delivery', 'order_type' => 'pos', 'total' => 750],
            ['payment_method' => 'glovo', 'sales_channel' => 'glovo', 'order_type' => 'pos', 'total' => 200],
            ['payment_method' => 'uber', 'sales_channel' => 'uber', 'order_type' => 'pos', 'total' => 300],
            ['payment_method' => 'bolt_food', 'sales_channel' => 'bolt_food', 'order_type' => 'pos', 'total' => 50],
            ['payment_method' => 'cash', 'sales_channel' => '', 'order_type' => 'delivery', 'total' => 999],
        ];

        $seen = [];
        foreach ($rows as $row) {
            $category = AdminDashboardSalesKpis::category(
                $row['payment_method'],
                $row['sales_channel'],
                $row['order_type']
            );
            $this->assertArrayNotHasKey($row['payment_method'].'|'.$row['sales_channel'], $seen);
            $seen[$row['payment_method'].'|'.$row['sales_channel']] = $category;
        }

        $totals = AdminDashboardSalesKpis::fromGroupedRows($rows);
        $this->assertSame(850.0, $totals['munch_sales']);
        $this->assertSame(200.0, $totals['glovo']);
        $this->assertSame(300.0, $totals['uber']);
        $this->assertSame(50.0, $totals['bolt_food']);
        $this->assertSame(
            1400.0,
            $totals['munch_sales'] + $totals['glovo'] + $totals['uber'] + $totals['bolt_food']
        );
    }

    public function test_september_11_production_shape_includes_confirmed_and_paystack(): void
    {
        $totals = AdminDashboardSalesKpis::fromGroupedRows([
            ['payment_method' => 'cash', 'sales_channel' => 'takeaway', 'order_type' => 'pos', 'total' => 33590],
            ['payment_method' => 'mpesa', 'sales_channel' => 'takeaway', 'order_type' => 'pos', 'total' => 18500],
            ['payment_method' => 'card', 'sales_channel' => 'takeaway', 'order_type' => 'pos', 'total' => 1040],
            ['payment_method' => 'cash', 'sales_channel' => 'dine_in', 'order_type' => 'dine_in', 'total' => 7580],
            ['payment_method' => 'mpesa', 'sales_channel' => 'dine_in', 'order_type' => 'dine_in', 'total' => 13630],
            ['payment_method' => 'cash', 'sales_channel' => 'delivery', 'order_type' => 'pos', 'total' => 16150],
            ['payment_method' => 'mpesa', 'sales_channel' => 'delivery', 'order_type' => 'pos', 'total' => 12630],
            ['payment_method' => 'paystack', 'sales_channel' => 'delivery', 'order_type' => 'pos', 'total' => 750],
            ['payment_method' => 'glovo', 'sales_channel' => 'glovo', 'order_type' => 'pos', 'total' => 54630],
            ['payment_method' => 'uber', 'sales_channel' => 'uber', 'order_type' => 'pos', 'total' => 27585],
            ['payment_method' => 'bolt_food', 'sales_channel' => 'bolt_food', 'order_type' => 'pos', 'total' => 890],
        ]);

        $this->assertSame(57320.0, $totals['cash']);
        $this->assertSame(1040.0, $totals['card']);
        $this->assertSame(44760.0, $totals['mpesa']);
        $this->assertSame(750.0, $totals['paystack']);
        $this->assertSame(103120.0, $totals['cash'] + $totals['card'] + $totals['mpesa']);
        $this->assertSame(103870.0, $totals['munch_sales']);
        $this->assertSame(49990.0 + 53130.0 + 750.0, $totals['munch_sales']);
        $this->assertSame(54630.0, $totals['glovo']);
        $this->assertSame(27585.0, $totals['uber']);
        $this->assertSame(890.0, $totals['bolt_food']);
    }

    public function test_service_query_counts_confirmed_paid_pos_and_excludes_voids_and_website(): void
    {
        $day = Carbon::parse('2026-09-11 12:00:00');

        $this->insertOrder(1, 1, 'dine_in', 'dine_in', 'cash', 'paid', 'confirmed', 19180, $day);
        $this->insertOrder(2, 10, 'pos', 'takeaway', 'cash', 'paid', 'delivered', 40970, $day);
        $this->insertOrder(3, 13, 'pos', 'delivery', 'mpesa', 'paid', 'confirmed', 20790, $day);
        $this->insertOrder(4, 14, 'pos', 'delivery', 'paystack', 'paid', 'confirmed', 750, $day);
        $this->insertOrder(5, 14, 'dine_in', 'dine_in', 'mpesa', 'paid', 'confirmed', 22180, $day);
        $this->insertOrder(6, 1, 'pos', 'glovo', 'glovo', 'paid', 'delivered', 1000, $day);
        $this->insertOrder(7, 1, 'delivery', '', 'cash', 'paid', 'delivered', 9999, $day);
        $this->insertOrder(8, 1, 'take_away', '', 'mpesa', 'paid', 'delivered', 8888, $day);
        $this->insertOrder(9, 1, 'dine_in', 'dine_in', 'cash', 'paid', 'canceled', 500, $day, $day);
        $this->insertOrder(10, 1, 'pos', 'delivery', 'cash', 'unpaid', 'confirmed', 400, $day);
        $this->insertOrder(11, 1, 'pos', 'takeaway', 'cash', 'paid', 'delivered', 50, Carbon::parse('2026-09-10 12:00:00'));

        $service = new AdminDashboardSalesKpiService();
        $period = AdminDashboardSalesKpis::period('custom', '2026-09-11', '2026-09-11', $day);
        $rows = $service->aggregatedQuery(null, $period)->get()->map(fn ($row) => [
            'payment_method' => $row->payment_method,
            'sales_channel' => $row->sales_channel,
            'order_type' => $row->order_type,
            'total' => (float) $row->total,
        ])->all();

        $totals = AdminDashboardSalesKpis::fromGroupedRows($rows);
        $this->assertSame(103870.0, $totals['munch_sales']);
        $this->assertSame(750.0, $totals['paystack']);
        $this->assertSame(1000.0, $totals['glovo']);
        $this->assertSame(0.0, $totals['uber']);

        $ids = AdminDashboardSalesKpis::constrainQualifying(Order::query())
            ->whereBetween('created_at', [$period['from'], $period['to']])
            ->pluck('id')
            ->all();
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
        $this->assertContains(3, $ids);
        $this->assertContains(4, $ids);
        $this->assertContains(5, $ids);
        $this->assertContains(6, $ids);
        $this->assertNotContains(7, $ids);
        $this->assertNotContains(8, $ids);
        $this->assertNotContains(9, $ids);
        $this->assertNotContains(10, $ids);
        $this->assertNotContains(11, $ids);

        $nyali = AdminDashboardSalesKpis::fromGroupedRows(
            $service->aggregatedQuery(1, $period)->get()->map(fn ($row) => [
                'payment_method' => $row->payment_method,
                'sales_channel' => $row->sales_channel,
                'order_type' => $row->order_type,
                'total' => (float) $row->total,
            ])->all()
        );
        $this->assertSame(19180.0, $nyali['munch_sales']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOrder(array $attributes): Order
    {
        $order = new Order();
        $order->forceFill($attributes);
        $order->id = (int) ($attributes['id'] ?? 1);
        $order->exists = true;

        return $order;
    }

    private function insertOrder(
        int $id,
        int $branchId,
        string $orderType,
        string $channel,
        string $method,
        string $paymentStatus,
        string $status,
        float $amount,
        Carbon $createdAt,
        ?Carbon $cancelledAt = null
    ): void {
        DB::table('orders')->insert([
            'id' => $id,
            'branch_id' => $branchId,
            'order_type' => $orderType,
            'sales_channel' => $channel,
            'payment_method' => $method,
            'payment_status' => $paymentStatus,
            'order_status' => $status,
            'order_amount' => $amount,
            'cancelled_at' => $cancelledAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
