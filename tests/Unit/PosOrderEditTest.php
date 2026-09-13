<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Model\OrderDetail;
use App\Models\OrderChangeAmount;
use App\Services\PosOrderEditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosOrderEditTest extends TestCase
{
    private PosOrderEditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
        $this->service = new PosOrderEditService();
    }

    public function test_edit_is_enabled_when_neither_ticket_is_printed(): void
    {
        $order = $this->makeOrder([
            'order_status' => 'confirmed',
            'sales_channel' => 'dine_in',
            'order_type' => 'dine_in',
        ]);
        $this->assertTrue(PosOrderEditService::isEditable($order));
        $this->assertNull(PosOrderEditService::editableError($order));
    }

    public function test_edit_is_disabled_when_kitchen_ticket_is_printed(): void
    {
        $order = $this->makeOrder([
            'order_status' => 'confirmed',
            'sales_channel' => 'takeaway',
            'kitchen_printed_at' => now(),
        ]);
        $this->assertFalse(PosOrderEditService::isEditable($order));
        $this->assertSame(PosOrderEditService::PRINTED_MESSAGE, PosOrderEditService::editableError($order));
    }

    public function test_edit_is_disabled_when_customer_receipt_is_printed(): void
    {
        $order = $this->makeOrder([
            'order_status' => 'confirmed',
            'sales_channel' => 'takeaway',
            'receipt_printed_at' => now(),
        ]);
        $this->assertFalse(PosOrderEditService::isEditable($order));
        $this->assertSame(PosOrderEditService::PRINTED_MESSAGE, PosOrderEditService::editableError($order));
    }

    public function test_edit_is_disabled_when_both_tickets_are_printed(): void
    {
        $order = $this->makeOrder([
            'order_status' => 'confirmed',
            'sales_channel' => 'delivery',
            'kitchen_printed_at' => now(),
            'receipt_printed_at' => now(),
        ]);
        $this->assertFalse(PosOrderEditService::isEditable($order));
        $this->assertSame(PosOrderEditService::PRINTED_MESSAGE, PosOrderEditService::editableError($order));
    }

    public function test_cancelled_and_marketplace_orders_cannot_be_edited(): void
    {
        $cancelled = $this->makeOrder(['order_status' => 'canceled', 'sales_channel' => 'takeaway']);
        $this->assertFalse(PosOrderEditService::isEditable($cancelled));
        $this->assertSame(PosOrderEditService::CANCELLED_MESSAGE, PosOrderEditService::editableError($cancelled));

        $glovo = $this->makeOrder([
            'order_status' => 'delivered',
            'sales_channel' => 'glovo',
            'order_type' => 'pos',
        ]);
        $this->assertFalse(PosOrderEditService::isEditable($glovo));
        $this->assertSame(PosOrderEditService::MARKETPLACE_MESSAGE, PosOrderEditService::editableError($glovo));
    }

    public function test_cancelled_order_cannot_print_a_customer_receipt(): void
    {
        $cancelled = $this->makeOrder(['order_status' => 'canceled', 'sales_channel' => 'takeaway']);
        $this->assertSame(
            'Cancelled orders cannot print a customer receipt.',
            PosOrderEditService::printBlockedMessage($cancelled, 'receipt')
        );
        $this->assertSame(
            'Cancelled orders cannot print kitchen tickets',
            PosOrderEditService::printBlockedMessage($cancelled, 'kitchen')
        );

        $open = $this->makeOrder(['order_status' => 'confirmed', 'sales_channel' => 'dine_in']);
        $this->assertNull(PosOrderEditService::printBlockedMessage($open, 'receipt'));
        $this->assertNull(PosOrderEditService::printBlockedMessage($open, 'kitchen'));
    }

    public function test_edit_updates_the_existing_order_and_payment_row(): void
    {
        $order = $this->persistOrder([
            'order_amount' => 780,
            'payment_method' => 'cash',
            'client_uuid' => 'edit-uuid-1',
            'readable_order_id' => 'A10807',
        ]);
        OrderDetail::query()->insert([
            'order_id' => $order->id,
            'product_id' => 62,
            'quantity' => 2,
            'price' => 390,
            'discount_on_product' => 0,
            'tax_amount' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        OrderChangeAmount::query()->create([
            'order_id' => $order->id,
            'order_amount' => 780,
            'paid_amount' => 780,
        ]);

        $result = $this->service->apply($order, [
            'details' => [[
                'product_id' => 63,
                'quantity' => 1,
                'price' => 590,
                'discount_on_product' => 0,
                'tax_amount' => 0,
                'discount_type' => 'discount_on_product',
                'variation' => '[]',
                'add_on_ids' => '[]',
                'add_on_qtys' => '[]',
                'add_on_prices' => '[]',
                'add_on_taxes' => '[]',
                'add_on_tax_amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            'extra_discount' => 0,
            'total_tax_amount' => 0,
            'delivery_charge' => 0,
            'order_amount' => 1180,
        ], 'cash');

        $this->assertTrue($result['success']);
        $this->assertSame(1, Order::query()->count());
        $fresh = Order::query()->findOrFail($order->id);
        $this->assertSame('A10807', $fresh->readable_order_id);
        $this->assertSame('edit-uuid-1', $fresh->client_uuid);
        $this->assertSame(1180.0, (float) $fresh->order_amount);
        $this->assertSame('cash', $fresh->payment_method);
        $this->assertSame('paid', $fresh->payment_status);
        $this->assertSame(1, OrderDetail::query()->where('order_id', $order->id)->count());
        $this->assertSame(63, (int) OrderDetail::query()->where('order_id', $order->id)->value('product_id'));
        $this->assertSame(1, OrderChangeAmount::query()->where('order_id', $order->id)->count());
        $change = OrderChangeAmount::query()->where('order_id', $order->id)->first();
        $this->assertSame(1180.0, (float) $change->order_amount);
        $this->assertSame(1180.0, (float) $change->paid_amount);
    }

    public function test_printed_or_cancelled_orders_are_rejected_by_apply(): void
    {
        $printed = $this->persistOrder([
            'kitchen_printed_at' => now(),
            'order_amount' => 100,
        ]);
        $printedResult = $this->service->apply($printed, $this->parts(200), 'cash');
        $this->assertFalse($printedResult['success']);
        $this->assertSame(100.0, (float) $printed->fresh()->order_amount);

        $cancelled = $this->persistOrder([
            'order_status' => 'canceled',
            'cancelled_at' => now(),
            'order_amount' => 300,
        ]);
        $cancelledResult = $this->service->apply($cancelled, $this->parts(400), 'cash');
        $this->assertFalse($cancelledResult['success']);
        $this->assertSame(PosOrderEditService::CANCELLED_MESSAGE, $cancelledResult['message']);
        $this->assertSame(300.0, (float) $cancelled->fresh()->order_amount);
    }

    public function test_frontend_and_backend_keep_normal_post_print_and_cancel_paths(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $page = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));

        $this->assertStringContainsString('id="pos-success-edit"', $page);
        $this->assertStringContainsString('Edit Order', $page);
        $this->assertStringContainsString('function startEditPostedOrder', $js);
        $this->assertStringContainsString('function applyEditButtonState', $js);
        $this->assertStringContainsString('OrderRules.canEditPostedOrder', $js);
        $this->assertStringContainsString('CFG.urls.updateOrder', $js);
        $this->assertStringContainsString('if (editingOrder) return', $js);
        $this->assertStringContainsString('replaceQueuedPayload', $js);
        $this->assertStringContainsString("payload.action === 'update'", $js);
        $this->assertStringContainsString('function placeOrder', $js);
        $this->assertStringContainsString('function printOneTicket', $js);
        $this->assertStringContainsString('CFG.urls.cancelOrder', $js);
        $this->assertStringContainsString('function updateOrder', $controller);
        $this->assertStringContainsString('hydrateJsonPosCart', $controller);
        $this->assertStringContainsString('buildPosCartSnapshot', $controller);
        $this->assertStringContainsString('$this->posEdit->apply', $controller);
        $this->assertStringContainsString("route('branch.pos.update-order')", $page);
    }

    public function test_node_edit_and_receipt_rules(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for POS edit/receipt scenarios');
        }

        $script = base_path('tests/Js/pos-order-edit.test.js');
        $output = [];
        $code = 0;
        exec(escapeshellcmd($node).' '.escapeshellarg($script).' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $joined = implode("\n", $output);
        $this->assertStringContainsString('edit enabled when neither printed', $joined);
        $this->assertStringContainsString('edit disabled when kitchen printed', $joined);
        $this->assertStringContainsString('cancelled receipt blocked', $joined);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeOrder(array $attrs): Order
    {
        $order = new Order();
        $order->order_type = $attrs['order_type'] ?? 'pos';
        $order->sales_channel = $attrs['sales_channel'] ?? 'takeaway';
        $order->order_status = $attrs['order_status'] ?? 'confirmed';
        $order->payment_status = $attrs['payment_status'] ?? 'paid';
        $order->kitchen_printed_at = $attrs['kitchen_printed_at'] ?? null;
        $order->receipt_printed_at = $attrs['receipt_printed_at'] ?? null;
        $order->cancelled_at = $attrs['cancelled_at'] ?? null;

        return $order;
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function persistOrder(array $attrs): Order
    {
        $id = DB::table('orders')->insertGetId([
            'user_id' => 0,
            'order_amount' => $attrs['order_amount'] ?? 100,
            'payment_status' => $attrs['payment_status'] ?? 'paid',
            'order_status' => $attrs['order_status'] ?? 'confirmed',
            'payment_method' => $attrs['payment_method'] ?? 'cash',
            'order_type' => $attrs['order_type'] ?? 'pos',
            'sales_channel' => $attrs['sales_channel'] ?? 'takeaway',
            'branch_id' => 7,
            'readable_order_id' => $attrs['readable_order_id'] ?? 'A10001',
            'client_uuid' => $attrs['client_uuid'] ?? 'uuid-1',
            'kitchen_printed_at' => $attrs['kitchen_printed_at'] ?? null,
            'receipt_printed_at' => $attrs['receipt_printed_at'] ?? null,
            'cancelled_at' => $attrs['cancelled_at'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::query()->findOrFail($id);
    }

    /**
     * @return array{details: list<array<string, mixed>>, extra_discount: float, total_tax_amount: float, delivery_charge: float, order_amount: float}
     */
    private function parts(float $amount): array
    {
        return [
            'details' => [[
                'product_id' => 1,
                'quantity' => 1,
                'price' => $amount,
                'discount_on_product' => 0,
                'tax_amount' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            'extra_discount' => 0,
            'total_tax_amount' => 0,
            'delivery_charge' => 0,
            'order_amount' => $amount,
        ];
    }

    private function ensureSchema(): void
    {
        foreach (['order_change_amounts', 'order_details', 'orders'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->float('order_amount')->default(0);
            $table->string('payment_status')->nullable();
            $table->string('order_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('order_type')->nullable();
            $table->string('sales_channel')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('readable_order_id')->nullable();
            $table->string('client_uuid')->nullable();
            $table->timestamp('kitchen_printed_at')->nullable();
            $table->timestamp('receipt_printed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->float('extra_discount')->default(0);
            $table->float('total_tax_amount')->default(0);
            $table->float('delivery_charge')->default(0);
            $table->float('coupon_discount_amount')->default(0);
            $table->text('order_note')->nullable();
            $table->timestamps();
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->float('price')->default(0);
            $table->float('discount_on_product')->default(0);
            $table->float('tax_amount')->default(0);
            $table->string('discount_type')->nullable();
            $table->text('variation')->nullable();
            $table->text('add_on_ids')->nullable();
            $table->text('add_on_qtys')->nullable();
            $table->text('add_on_prices')->nullable();
            $table->text('add_on_taxes')->nullable();
            $table->float('add_on_tax_amount')->default(0);
            $table->timestamps();
        });

        Schema::create('order_change_amounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->float('order_amount')->default(0);
            $table->float('paid_amount')->default(0);
            $table->timestamps();
        });
    }
}
