<?php

namespace Tests\Unit;

use App\CentralLogics\Helpers;
use App\Model\Order;
use App\Services\OnlineOrderPosAcceptService;
use App\Support\PosClientVersion;
use App\Support\PosOnlineOrderReceiveSettings;
use App\Support\PosOrderTypes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OnlineOrderPosAcceptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'order_details',
            'order_areas',
            'delivery_charge_by_areas',
            'orders',
            'branches',
            'users',
            'guest_users',
            'business_settings',
            'currencies',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->text('value')->nullable();
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('currency_code');
            $table->string('currency_symbol')->default('Ksh');
            $table->string('country')->nullable();
            $table->decimal('exchange_rate', 8, 2)->default(1);
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        Schema::create('guest_users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_charge_by_areas', function (Blueprint $table) {
            $table->id();
            $table->string('area_name')->nullable();
            $table->timestamps();
        });

        Schema::create('order_areas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('area_id')->nullable();
            $table->timestamps();
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->text('product_details')->nullable();
            $table->text('variation')->nullable();
            $table->text('add_on_ids')->nullable();
            $table->text('add_on_qtys')->nullable();
            $table->decimal('price', 24, 2)->default(0);
            $table->decimal('discount_on_product', 24, 2)->default(0);
            $table->decimal('tax_amount', 24, 2)->default(0);
            $table->integer('quantity')->default(1);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('readable_order_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->default(1);
            $table->string('order_status')->default('pending');
            $table->string('order_type')->default('delivery');
            $table->string('sales_channel')->nullable();
            $table->string('payment_status')->default('unpaid');
            $table->string('payment_method')->nullable();
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->decimal('delivery_charge', 24, 2)->default(0);
            $table->text('delivery_address')->nullable();
            $table->text('order_note')->nullable();
            $table->date('delivery_date')->nullable();
            $table->boolean('is_guest')->default(true);
            $table->boolean('checked')->default(false);
            $table->timestamp('kitchen_printed_at')->nullable();
            $table->timestamp('receipt_printed_at')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamps();
        });

        DB::table('business_settings')->insert([
            ['key' => 'currency', 'value' => 'KES'],
            ['key' => 'decimal_point_settings', 'value' => '0'],
            ['key' => 'currency_symbol_position', 'value' => 'left'],
        ]);
        DB::table('currencies')->insert([
            'currency_code' => 'KES',
            'currency_symbol' => 'Ksh',
        ]);
        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Mtwapa', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Nyali', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('delivery_charge_by_areas')->insert([
            'id' => 9,
            'area_name' => 'Shanzu',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Helpers::forgetBusinessSettingsRuntimeCache();
    }

    public function test_pending_alert_is_branch_scoped_and_includes_full_preview(): void
    {
        $this->seedOnlineOrder(101, 1, [
            'payment_status' => 'paid',
            'payment_method' => 'mpesa',
            'order_amount' => 1850,
            'delivery_charge' => 150,
        ]);
        $this->seedOnlineOrder(202, 2);

        $payload = $this->service()->pendingAlertPayload(1);

        $this->assertSame(1, $payload['new_order']);
        $this->assertNotNull($payload['order']);
        $this->assertSame(101, $payload['order']['id']);
        $this->assertSame('Ada Munch', $payload['order']['customer_name']);
        $this->assertSame('Shanzu', $payload['order']['area']);
        $this->assertSame('Near Total petrol station', $payload['order']['address']);
        $this->assertStringContainsString('Shanzu', $payload['order']['address_line']);
        $this->assertTrue($payload['order']['is_delivery']);
        $this->assertFalse($payload['order']['is_pickup']);
        $this->assertSame('DELIVERY', $payload['order']['fulfillment_label']);
        $this->assertNotEmpty($payload['order']['items']);
        $this->assertSame('1x Half Chicken', $payload['order']['item_lines'][0]);
        $this->assertSame('Paid', $payload['order']['payment_status_label']);

        $other = $this->service()->pendingAlertPayload(2);
        $this->assertSame(1, $other['new_order']);
        $this->assertSame(202, $other['order']['id']);
    }

    public function test_accept_converts_online_order_into_pos_sale_and_is_idempotent(): void
    {
        $this->seedOnlineOrder(303, 1, [
            'payment_status' => 'paid',
            'payment_method' => 'mpesa',
            'order_amount' => 1200,
            'order_status' => 'pending',
        ]);

        $first = $this->service()->accept(303, 1);
        $this->assertTrue($first['success']);
        $this->assertFalse($first['duplicate']);
        $this->assertFalse($first['idempotent']);

        $order = Order::query()->find(303);
        $this->assertNotNull($order);
        $this->assertTrue(PosOrderTypes::isPosFamily($order->order_type, $order->sales_channel));
        $this->assertSame('pos', $order->order_type);
        $this->assertSame('delivery', $order->sales_channel);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('mpesa', $order->payment_method);
        $this->assertSame('confirmed', $order->order_status);
        $this->assertSame(1, (int) $order->checked);

        $second = $this->service()->accept(303, 1);
        $this->assertTrue($second['success']);
        $this->assertTrue($second['duplicate']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame(303, (int) $second['order']->id);

        $alert = $this->service()->pendingAlertPayload(1);
        $this->assertSame(0, $alert['new_order']);
        $this->assertNull($alert['order']);
    }

    public function test_accept_rejects_other_branch_and_terminal_orders(): void
    {
        $this->seedOnlineOrder(404, 1);
        $this->seedOnlineOrder(405, 1, ['order_status' => 'canceled']);

        $wrongBranch = $this->service()->accept(404, 2);
        $this->assertFalse($wrongBranch['success']);
        $this->assertSame('not_found', $wrongBranch['code']);

        $terminal = $this->service()->accept(405, 1);
        $this->assertFalse($terminal['success']);
        $this->assertSame('terminal', $terminal['code']);
    }

    public function test_takeaway_online_order_maps_to_pos_takeaway_channel(): void
    {
        $this->seedOnlineOrder(505, 1, [
            'order_type' => 'take_away',
            'payment_status' => 'unpaid',
            'payment_method' => 'cash_on_delivery',
        ]);

        $preview = $this->service()->previewPayload(Order::query()->with(['customer', 'guest', 'branch', 'order_area.area', 'details'])->find(505));
        $this->assertTrue($preview['is_pickup']);
        $this->assertFalse($preview['is_delivery']);
        $this->assertSame('PICKUP', $preview['fulfillment_label']);

        $result = $this->service()->accept(505, 1);
        $this->assertTrue($result['success']);

        $order = Order::query()->find(505);
        $this->assertSame('pos', $order->order_type);
        $this->assertSame('takeaway', $order->sales_channel);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('cash_on_delivery', $order->payment_method);
    }

    public function test_pos_shell_wires_accept_endpoints_and_bumps_asset_version(): void
    {
        $routes = file_get_contents(base_path('routes/branch.php'));
        $this->assertStringContainsString("pending-online-orders", $routes);
        $this->assertStringContainsString("accept-online-order", $routes);

        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $this->assertStringContainsString('function pendingOnlineOrders', $controller);
        $this->assertStringContainsString('function acceptOnlineOrder', $controller);
        $this->assertStringContainsString('OnlineOrderPosAcceptService', $controller);
        $this->assertStringContainsString('PosOnlineOrderReceiveSettings::isEnabled', $controller);
        $this->assertStringContainsString('pos_online_receive_disabled', $controller);

        $page = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));
        $this->assertStringContainsString('pos-online-order-modal', $page);
        $this->assertStringContainsString('pos-online-order-accept', $page);
        $this->assertStringContainsString('pos-online-order-fulfillment', $page);
        $this->assertStringContainsString('pos-online-order-area', $page);
        $this->assertStringContainsString('pendingOnlineOrders', $page);
        $this->assertStringContainsString('acceptOnlineOrder', $page);
        $this->assertStringContainsString('PosOnlineOrderReceiveSettings::isEnabled', $page);
        $this->assertStringNotContainsString("get_business_settings('admin_order_notification')", $page);

        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $this->assertStringContainsString('function acceptOnlineOrder', $js);
        $this->assertStringContainsString('startOnlineOrderPolling', $js);
        $this->assertStringContainsString('onlineOrderFulfillmentLabel', $js);
        $this->assertStringContainsString('onlineOrderAreaRow', $js);
        $this->assertStringContainsString('Date.now() + 45000', $js);
        $this->assertStringContainsString('openSuccessModal(snapshotPrintJob(body)', $js);

        $css = file_get_contents(public_path('assets/admin/css/munch-pos.css'));
        $this->assertStringContainsString('munch-pos-online-ring__fulfillment', $css);

        $this->assertSame('6.17', PosClientVersion::ASSET);
    }

    public function test_pos_online_order_receive_setting_defaults_off_and_reads_business_settings(): void
    {
        Helpers::forgetBusinessSettingsRuntimeCache();
        $this->assertFalse(PosOnlineOrderReceiveSettings::isEnabled());
        $this->assertSame('pos_online_order_receive_status', PosOnlineOrderReceiveSettings::KEY);

        DB::table('business_settings')->updateOrInsert(
            ['key' => PosOnlineOrderReceiveSettings::KEY],
            ['value' => '1']
        );
        Helpers::forgetBusinessSettingsRuntimeCache();
        $this->assertTrue(PosOnlineOrderReceiveSettings::isEnabled());

        DB::table('business_settings')->where('key', PosOnlineOrderReceiveSettings::KEY)->update(['value' => '0']);
        Helpers::forgetBusinessSettingsRuntimeCache();
        $this->assertFalse(PosOnlineOrderReceiveSettings::isEnabled());
    }

    public function test_order_settings_ui_exposes_pos_online_receive_toggle(): void
    {
        $page = file_get_contents(resource_path('views/admin-views/business-settings/order-index.blade.php'));
        $this->assertStringContainsString('name="pos_online_order_receive_status"', $page);
        $this->assertStringContainsString('Receive online orders on branch POS', $page);

        $controller = file_get_contents(app_path('Http/Controllers/Admin/BusinessSettingsController.php'));
        $orderUpdate = $this->methodBody($controller, 'orderUpdate');
        $this->assertStringContainsString("has('pos_online_order_receive_status') ? 1 : 0", $orderUpdate);
        $this->assertStringContainsString("'key' => 'pos_online_order_receive_status'", $orderUpdate);
    }

    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function '.$method);
        $this->assertNotFalse($start, $method.' missing');
        $brace = strpos($source, '{', $start);
        $this->assertNotFalse($brace, $method.' body missing');

        $depth = 0;
        $len = strlen($source);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }

        $this->fail($method.' body unclosed');
    }

    private function service(): OnlineOrderPosAcceptService
    {
        return app(OnlineOrderPosAcceptService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedOnlineOrder(int $id, int $branchId, array $overrides = []): void
    {
        $now = now();
        DB::table('orders')->insert(array_merge([
            'id' => $id,
            'readable_order_id' => 'A'.$id,
            'user_id' => null,
            'branch_id' => $branchId,
            'order_status' => 'pending',
            'order_type' => 'delivery',
            'sales_channel' => null,
            'payment_status' => 'unpaid',
            'payment_method' => 'cash_on_delivery',
            'order_amount' => 1000,
            'delivery_charge' => 100,
            'delivery_address' => json_encode([
                'contact_person_name' => 'Ada Munch',
                'contact_person_number' => '0712345678',
                'address' => 'Near Total petrol station',
            ]),
            'order_note' => 'No onions',
            'delivery_date' => $now->toDateString(),
            'is_guest' => 1,
            'checked' => 0,
            'placed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        DB::table('order_areas')->insert([
            'order_id' => $id,
            'area_id' => 9,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('order_details')->insert([
            'order_id' => $id,
            'product_id' => 11,
            'product_details' => json_encode(['name' => 'Half Chicken', 'add_ons' => []]),
            'variation' => json_encode([]),
            'add_on_ids' => json_encode([]),
            'add_on_qtys' => json_encode([]),
            'price' => 900,
            'discount_on_product' => 0,
            'tax_amount' => 0,
            'quantity' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
