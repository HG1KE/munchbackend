<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Support\AdminSaleReportSummary;
use App\Support\PosClientVersion;
use App\Support\PosOrderTypes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosCashierDiscountDisabledTest extends TestCase
{
    private string $previousConnection = 'mysql';

    /**
     * @return list<string>
     */
    private function posTypes(): array
    {
        return ['dine_in', 'take_away', 'delivery', 'glovo', 'uber', 'bolt_food'];
    }

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
            $table->id();
            $table->unsignedBigInteger('branch_id')->default(1);
            $table->string('order_type')->default('pos');
            $table->string('sales_channel')->nullable();
            $table->decimal('order_amount', 12, 2)->default(0);
            $table->decimal('extra_discount', 12, 2)->default(0);
            $table->string('payment_status')->default('paid');
            $table->string('order_status')->default('delivered');
            $table->string('payment_method')->nullable();
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

    public function test_frontend_has_no_cashier_discount_control_for_any_pos_order_type(): void
    {
        $page = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));
        $legacy = file_get_contents(resource_path('views/branch-views/pos/_cart.blade.php'));
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));

        $this->assertStringNotContainsString('pos-discount-wrap', $page);
        $this->assertStringNotContainsString('id="pos-discount"', $page);
        $this->assertStringNotContainsString('pos-discount-type', $page);
        $this->assertStringNotContainsString('add-discount', $page);
        $this->assertStringNotContainsString('add-discount', $legacy);
        $this->assertStringNotContainsString('branch.pos.discount', $legacy);
        $this->assertStringContainsString('function allowsDiscount', $js);
        $this->assertStringContainsString('return false', $this->functionBody($js, 'function allowsDiscount'));
        $this->assertStringContainsString('extra_discount: 0', $js);
        $this->assertStringContainsString("extra_discount_type: 'amount'", $js);
        $this->assertStringContainsString('function stripCashierDiscount', $js);
        $this->assertStringContainsString('cart.discount = 0', $js);

        foreach ($this->posTypes() as $type) {
            $this->assertFalse(PosOrderTypes::allowsManualDiscount($type), $type.' still allows a cashier discount');
        }
    }

    public function test_submitted_extra_discount_is_persisted_as_zero_for_every_pos_channel(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $this->assertStringContainsString('$order->extra_discount = PosOrderTypes::cashierExtraDiscount($extraDiscount);', $controller);
        $this->assertStringContainsString("\$cart['extra_discount'] = PosOrderTypes::cashierExtraDiscount(\$request->input('extra_discount', 0));", $controller);
        $this->assertStringContainsString("\$extraDiscount = PosOrderTypes::cashierExtraDiscount(\$cart['extra_discount'] ?? 0);", $controller);
        $this->assertStringContainsString("\$cart['extra_discount'] = PosOrderTypes::cashierExtraDiscount(\$request->discount);", $controller);
        $this->assertStringNotContainsString('$order->extra_discount = $extraDiscount;', $controller);
        $this->assertStringNotContainsString('$order->extra_discount = $request', $controller);

        foreach ($this->posTypes() as $type) {
            $id = DB::table('orders')->insertGetId([
                'branch_id' => 7,
                'order_type' => PosOrderTypes::databaseType($type),
                'sales_channel' => PosOrderTypes::salesChannel($type),
                'order_amount' => 1500,
                'extra_discount' => PosOrderTypes::cashierExtraDiscount(500),
                'payment_status' => 'paid',
                'order_status' => PosOrderTypes::defaultStatus($type),
                'payment_method' => PosOrderTypes::resolvedPaymentMethod($type, 'cash'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $order = Order::query()->findOrFail($id);
            $this->assertSame(0.0, (float) $order->extra_discount, $type.' POST extra_discount=500 must persist 0');
            $this->assertSame(1500.0, (float) $order->order_amount, $type.' order amount must stay intact');
        }
    }

    public function test_historical_extra_discount_and_sale_report_kpis_remain_unchanged(): void
    {
        $id = DB::table('orders')->insertGetId([
            'branch_id' => 7,
            'order_type' => 'pos',
            'sales_channel' => 'takeaway',
            'order_amount' => 900,
            'extra_discount' => 100,
            'payment_status' => 'paid',
            'order_status' => 'delivered',
            'payment_method' => 'cash',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(100.0, (float) Order::query()->findOrFail($id)->extra_discount);
        $this->assertSame(900.0, (float) Order::query()->findOrFail($id)->order_amount);

        $summary = AdminSaleReportSummary::fromParts([
            'gross' => 1000,
            'item_discount' => 50,
            'extra_discount' => 100,
            'coupon_discount' => 20,
            'referral_discount' => 10,
            'tax' => 40,
            'delivery_fees' => 0,
            'total_sales' => 820,
        ]);

        $this->assertSame(180.0, $summary['total_discounts']);
        $this->assertSame(100.0, $summary['cashier_discounts']);
        $this->assertSame(1000.0, $summary['gross_sales']);
        $this->assertSame(820.0, $summary['net_sales']);
        $this->assertSame(820.0, $summary['total_sales']);
        $this->assertSame(40.0, $summary['tax']);

        $report = file_get_contents(resource_path('views/admin-views/report/sale-report.blade.php'));
        $this->assertStringContainsString('sum-cashier-discounts', $report);
        $this->assertStringContainsString('translate(\'Total Discounts\')', $report);

        $controller = file_get_contents(app_path('Http/Controllers/Admin/ReportController.php'));
        $this->assertStringContainsString('COALESCE(SUM(extra_discount), 0) as extra_discount', $controller);
    }

    public function test_duplicate_recovery_offline_and_stale_client_paths_are_unchanged(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $page = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));

        $this->assertStringContainsString('if (state.orderSubmitting) return', $js);
        $this->assertStringContainsString('function enqueue', $js);
        $this->assertStringContainsString('payload.extra_discount = 0', $this->functionBody($js, 'function enqueue'));
        $this->assertStringContainsString('payload.extra_discount = 0', $this->functionBody($js, 'function syncOne'));
        $this->assertStringContainsString('function recoverUncertainSubmit', $js);
        $this->assertStringContainsString('Checking order status...', $js);
        $this->assertStringContainsString('function applyStaleClient', $js);
        $this->assertStringContainsString('isPosClientStale()', $js);
        $this->assertStringContainsString("String(serverVersion) !== String(CFG.assetVersion)", $js);
        $this->assertStringContainsString("'pos_asset_version' => PosClientVersion::ASSET", $controller);
        $this->assertSame('6.9', PosClientVersion::ASSET);
        $this->assertStringContainsString("munch-pos-app.js') }}?v={{ \\App\\Support\\PosClientVersion::ASSET }}", $page);
        $this->assertStringContainsString('id="pos-munch-type-modal"', $page);
        $this->assertStringContainsString('function confirmMunchWalkInType', $js);
        $this->assertStringContainsString('function confirmDeliveryAndPlace', $js);
    }

    public function test_node_cashier_discount_scenarios(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for POS cashier-discount scenarios');
        }

        $script = base_path('tests/Js/pos-cashier-discount.test.js');
        $output = [];
        $code = 0;
        exec(escapeshellcmd($node).' '.escapeshellarg($script).' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $joined = implode("\n", $output);
        $this->assertStringContainsString('discount control is absent for Dine In', $joined);
        $this->assertStringContainsString('discount control is absent for Takeaway', $joined);
        $this->assertStringContainsString('discount control is absent for Delivery', $joined);
        $this->assertStringContainsString('discount control is absent for Glovo', $joined);
        $this->assertStringContainsString('discount control is absent for Uber', $joined);
        $this->assertStringContainsString('discount control is absent for Bolt Food', $joined);
        $this->assertStringContainsString('hydrating an old cart cannot restore a non-zero active discount', $joined);
    }

    private function functionBody(string $source, string $needle): string
    {
        $start = strpos($source, $needle);
        $this->assertNotFalse($start, $needle.' not found');

        return substr($source, $start, 2500);
    }
}
