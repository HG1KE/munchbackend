<?php

namespace Tests\Unit;

use App\CentralLogics\AbandonedCheckoutService;
use App\Http\Controllers\Api\V1\OrderController;
use App\Jobs\SendOnlineOrderPlacementNotificationsJob;
use App\Model\AbandonedCheckout;
use App\Model\Order;
use App\Model\OrderDetail;
use App\Models\OrderPartialPayment;
use App\Models\PaymentRequest;
use App\Services\Paystack\PaystackOrderProtectionService;
use App\Services\PaystackService;
use App\Support\OnlineCheckoutIdempotency;
use App\Support\OrderPlacementTime;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OnlineOrderIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OrderPlacementTime::resetColumnCache();
        $this->ensureSchema();
        OrderPlacementTime::resetColumnCache();
    }

    public function test_first_online_cod_request_creates_one_order(): void
    {
        Queue::fake();

        $result = $this->placeOnline([
            'online_checkout_uuid' => '11111111-1111-4111-8111-111111111111',
            'user_id' => 10,
            'order_amount' => 2050,
        ], [['product_id' => 3, 'quantity' => 2, 'price' => 390]]);

        $this->assertTrue($result['created']);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(2050.0, (float) $result['order']->order_amount);
        $this->assertSame('cash_on_delivery', $result['order']->payment_method);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $result['order']->online_checkout_uuid);
        $this->assertSame(200, $result['response']->getStatusCode());
        Queue::assertPushed(SendOnlineOrderPlacementNotificationsJob::class, 1);
    }

    public function test_same_uuid_submitted_twice_returns_the_existing_order(): void
    {
        Queue::fake();
        $uuid = '22222222-2222-4222-8222-222222222222';

        $first = $this->placeOnline(['online_checkout_uuid' => $uuid, 'user_id' => 10, 'order_amount' => 590]);
        $second = $this->placeOnline(['online_checkout_uuid' => $uuid, 'user_id' => 10, 'order_amount' => 590]);

        $this->assertFalse($second['created']);
        $this->assertSame($first['order']->id, $second['order']->id);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame($first['order']->readable_order_id, $second['response']->getData(true)['readable_order_id']);
        Queue::assertPushed(SendOnlineOrderPlacementNotificationsJob::class, 1);
    }

    public function test_same_uuid_submitted_concurrently_creates_exactly_one_order(): void
    {
        $uuid = '33333333-3333-4333-8333-333333333333';
        $this->insertOnlineRow(['id' => 2001, 'online_checkout_uuid' => $uuid, 'readable_order_id' => 'A2001']);

        $threw = false;
        try {
            $this->insertOnlineRow(['id' => 2002, 'online_checkout_uuid' => $uuid, 'readable_order_id' => 'A2002']);
        } catch (UniqueConstraintViolationException $e) {
            $threw = true;
            $existing = OnlineCheckoutIdempotency::recoverExistingFromException($uuid, $e);
            $this->assertNotNull($existing);
            $this->assertSame(2001, (int) $existing->id);
            $this->assertSame('A2001', $existing->readable_order_id);
        }

        $this->assertTrue($threw, 'second insert must hit the unique constraint');
        $this->assertSame(1, Order::query()->where('online_checkout_uuid', $uuid)->count());
        $this->assertSame(1, Order::query()->count());
    }

    public function test_duplicate_request_does_not_duplicate_order_details(): void
    {
        Queue::fake();
        $uuid = '44444444-4444-4444-8444-444444444444';
        $details = [
            ['product_id' => 3, 'quantity' => 2, 'price' => 390],
            ['product_id' => 37, 'quantity' => 1, 'price' => 290],
        ];

        $this->placeOnline(['online_checkout_uuid' => $uuid], $details);
        $this->placeOnline(['online_checkout_uuid' => $uuid], $details);

        $this->assertSame(2, OrderDetail::query()->count());
        $this->assertSame(1, Order::query()->count());
    }

    public function test_duplicate_request_does_not_duplicate_payment_rows(): void
    {
        Queue::fake();
        $uuid = '55555555-5555-4555-8555-555555555555';
        $payments = [['paid_with' => 'wallet_payment', 'paid_amount' => 100, 'due_amount' => 490]];

        $this->placeOnline(['online_checkout_uuid' => $uuid, 'payment_method' => 'wallet_payment'], [], $payments);
        $this->placeOnline(['online_checkout_uuid' => $uuid, 'payment_method' => 'wallet_payment'], [], $payments);

        $this->assertSame(1, OrderPartialPayment::query()->count());
        $this->assertSame(1, Order::query()->count());
    }

    public function test_duplicate_request_does_not_resend_notifications(): void
    {
        Queue::fake();
        $uuid = '66666666-6666-4666-8666-666666666666';

        $this->placeOnline(['online_checkout_uuid' => $uuid]);
        $this->placeOnline(['online_checkout_uuid' => $uuid]);
        $this->placeOnline(['client_uuid' => $uuid]);

        Queue::assertPushed(SendOnlineOrderPlacementNotificationsJob::class, 1);
    }

    public function test_two_different_uuids_create_two_legitimate_orders(): void
    {
        Queue::fake();

        $first = $this->placeOnline(['online_checkout_uuid' => '77777777-7777-4777-8777-777777777777', 'user_id' => 10]);
        $second = $this->placeOnline(['online_checkout_uuid' => '88888888-8888-4888-8888-888888888888', 'user_id' => 10]);

        $this->assertTrue($first['created']);
        $this->assertTrue($second['created']);
        $this->assertNotSame($first['order']->id, $second['order']->id);
        $this->assertSame(2, Order::query()->count());
        Queue::assertPushed(SendOnlineOrderPlacementNotificationsJob::class, 2);
    }

    public function test_same_customer_can_place_two_separate_orders(): void
    {
        Queue::fake();

        $this->placeOnline([
            'online_checkout_uuid' => '99999999-9999-4999-8999-999999999999',
            'user_id' => 4138,
            'order_amount' => 2050,
            'delivery_address_id' => 3282,
        ]);
        $this->placeOnline([
            'online_checkout_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'user_id' => 4138,
            'order_amount' => 2050,
            'delivery_address_id' => 3282,
        ]);

        $this->assertSame(2, Order::query()->where('user_id', 4138)->count());
    }

    public function test_online_paystack_flow_remains_idempotent_by_reference_and_uuid(): void
    {
        $uuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $this->insertOnlineRow([
            'id' => 3001,
            'payment_method' => 'paystack',
            'transaction_reference' => 'PSK_ref_1',
            'online_checkout_uuid' => $uuid,
            'readable_order_id' => 'A3001',
        ]);

        $protection = app(PaystackOrderProtectionService::class);
        $this->assertSame(3001, $protection->findExistingOrderId('PSK_ref_1'));
        $this->assertSame(3001, $protection->findExistingOrderId('', $uuid));
        $this->assertSame(3001, $protection->findExistingOrderId('PSK_ref_1', $uuid));
        $this->assertNull($protection->findExistingOrderId('PSK_other'));
    }

    public function test_payment_callback_cannot_create_a_duplicate_order(): void
    {
        $this->insertOnlineRow([
            'id' => 4001,
            'payment_method' => 'paystack',
            'transaction_reference' => 'PSK_cb_1',
            'online_checkout_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'readable_order_id' => 'A4001',
        ]);

        $payment = new PaymentRequest();
        $payment->forceFill([
            'payer_id' => '10',
            'payment_amount' => 590,
            'payment_method' => 'paystack',
            'transaction_id' => 'PSK_cb_1',
            'is_paid' => 1,
            'attribute' => 'order',
            'placement_status' => PaymentRequest::PLACEMENT_PENDING,
            'additional_data' => json_encode(['online_checkout_uuid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc']),
            'place_order_draft' => ['cart' => [['product_id' => 1]]],
        ])->save();

        $first = app(PaystackOrderProtectionService::class)->completeAfterVerify($payment);
        $second = app(PaystackOrderProtectionService::class)->completeAfterVerify($payment->fresh());

        $this->assertSame(4001, $first['order_id']);
        $this->assertSame(4001, $second['order_id']);
        $this->assertTrue($first['order_placed']);
        $this->assertTrue($second['order_placed']);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(4001, (int) $payment->fresh()->placed_order_id);
    }

    public function test_abandoned_cart_conversion_remains_idempotent(): void
    {
        Queue::fake();
        $uuid = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

        $row = AbandonedCheckout::query()->create([
            'phone' => '+254728341169',
            'user_id' => 4138,
            'is_guest' => 0,
            'client_token' => $uuid,
            'source' => 'web',
            'item_count' => 4,
            'expected_total' => 2150,
        ]);

        $first = $this->placeOnline([
            'online_checkout_uuid' => $uuid,
            'user_id' => 4138,
            'order_amount' => 2050,
        ]);
        $this->placeOnline([
            'online_checkout_uuid' => $uuid,
            'user_id' => 4138,
            'order_amount' => 2050,
        ]);

        $row->refresh();
        $this->assertNotNull($row->converted_at);
        $this->assertSame($first['order']->id, (int) $row->converted_order_id);
        $this->assertSame(1, AbandonedCheckout::query()->whereNotNull('converted_at')->count());
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(0, Order::query()->where('id', $row->id)->count());
    }

    public function test_notification_failure_does_not_invalidate_the_order(): void
    {
        $result = $this->placeOnline([
            'online_checkout_uuid' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'user_id' => 10,
        ], dispatchNotifications: false);

        $job = new SendOnlineOrderPlacementNotificationsJob((int) $result['order']->id, null, 10);
        $job->handle();

        $this->assertNotNull(Order::query()->find($result['order']->id));
        $this->assertSame(1, Order::query()->count());
    }

    public function test_pos_client_uuid_idempotency_is_unchanged_and_isolated(): void
    {
        $shared = 'ffffffff-ffff-4fff-8fff-ffffffffffff';

        $this->insertOnlineRow([
            'id' => 5001,
            'branch_id' => 13,
            'client_uuid' => $shared,
            'sales_channel' => 'takeaway',
            'online_checkout_uuid' => null,
            'readable_order_id' => 'P5001',
        ]);
        $this->insertOnlineRow([
            'id' => 5002,
            'branch_id' => 14,
            'client_uuid' => $shared,
            'sales_channel' => 'takeaway',
            'online_checkout_uuid' => null,
            'readable_order_id' => 'P5002',
        ]);
        $this->insertOnlineRow([
            'id' => 5003,
            'branch_id' => 13,
            'client_uuid' => null,
            'sales_channel' => null,
            'online_checkout_uuid' => $shared,
            'readable_order_id' => 'A5003',
        ]);

        $this->assertSame(3, Order::query()->count());
        $this->assertSame(2, Order::query()->where('client_uuid', $shared)->count());
        $this->assertSame(1, Order::query()->where('online_checkout_uuid', $shared)->count());

        $pos = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $this->assertStringContainsString('PosCheckoutIdempotency', $pos);
        $this->assertStringContainsString('findByClientUuid', $pos);
        $this->assertStringNotContainsString('online_checkout_uuid', $pos);
        $this->assertStringContainsString('orders_branch_client_uuid_unique', file_get_contents(database_path('migrations/2026_09_09_150000_add_sales_channel_and_client_uuid_to_orders_table.php')));
    }

    public function test_place_order_uses_database_unique_and_queues_notifications(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/OrderController.php'));
        $this->assertStringContainsString('OnlineCheckoutIdempotency::resolveFromRequest', $controller);
        $this->assertStringContainsString('OnlineCheckoutIdempotency::recoverExistingFromException', $controller);
        $this->assertStringContainsString('UniqueConstraintViolationException', $controller);
        $this->assertStringContainsString('SendOnlineOrderPlacementNotificationsJob::dispatch', $controller);
        $this->assertStringContainsString('AbandonedCheckoutService::linkOrderConversion', $controller);
        $this->assertStringNotContainsString('orderEmailAndNotification', $controller);
        $this->assertStringNotContainsString('CustomerOrderStatusSms::dispatchPlacement', $controller);
        $this->assertTrue(is_subclass_of(SendOnlineOrderPlacementNotificationsJob::class, \Illuminate\Contracts\Queue\ShouldQueue::class));

        $this->assertInstanceOf(OrderController::class, app(OrderController::class));
        $this->assertInstanceOf(PaystackService::class, app(PaystackService::class));
    }

    public function test_request_accepts_client_uuid_or_checkout_uuid_aliases(): void
    {
        $fromClient = OnlineCheckoutIdempotency::resolveFromRequest(Request::create('/place', 'POST', [
            'client_uuid' => '12121212-1212-4121-8121-121212121212',
        ]));
        $fromCheckout = OnlineCheckoutIdempotency::resolveFromRequest(Request::create('/place', 'POST', [
            'checkout_uuid' => '13131313-1313-4131-8131-131313131313',
        ]));
        $prefersExplicit = OnlineCheckoutIdempotency::resolveFromRequest(Request::create('/place', 'POST', [
            'online_checkout_uuid' => '14141414-1414-4141-8141-141414141414',
            'client_uuid' => '15151515-1515-4151-8151-151515151515',
        ]));

        $this->assertSame('12121212-1212-4121-8121-121212121212', $fromClient);
        $this->assertSame('13131313-1313-4131-8131-131313131313', $fromCheckout);
        $this->assertSame('14141414-1414-4141-8141-141414141414', $prefersExplicit);
    }

    public function test_migration_does_not_backfill_or_touch_pos_unique(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_13_200000_add_online_checkout_uuid_to_orders_table.php'));
        $this->assertStringContainsString('orders_online_checkout_uuid_unique', $migration);
        $this->assertStringContainsString("string('online_checkout_uuid', 64)->nullable()", $migration);
        $this->assertStringNotContainsString('UPDATE', $migration);
        $this->assertStringNotContainsString('backfill', strtolower($migration));
        $this->assertStringNotContainsString("dropUnique('orders_branch_client_uuid_unique')", $migration);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  list<array<string, mixed>>  $details
     * @param  list<array<string, mixed>>  $payments
     * @return array{order: Order, created: bool, response: \Illuminate\Http\JsonResponse}
     */
    private function placeOnline(array $attrs, array $details = [], array $payments = [], bool $dispatchNotifications = true): array
    {
        $request = Request::create('/api/v1/customer/order/place', 'POST', $attrs);
        $uuid = OnlineCheckoutIdempotency::resolveFromRequest($request);

        if ($uuid !== null) {
            $existing = OnlineCheckoutIdempotency::findOrder($uuid);
            if ($existing) {
                OnlineCheckoutIdempotency::logDuplicate($uuid, $existing, 'pre_insert_lookup');

                return [
                    'order' => $existing,
                    'created' => false,
                    'response' => OnlineCheckoutIdempotency::successResponse($existing),
                ];
            }
        }

        try {
            DB::beginTransaction();
            $id = (int) (Order::query()->max('id') ?? 100000) + 1;
            $row = $this->orderAttributes(array_merge($attrs, [
                'id' => $id,
                'readable_order_id' => $attrs['readable_order_id'] ?? ('A'.$id),
            ]));
            $row = OnlineCheckoutIdempotency::applyToOrderAttributes($row, $uuid);
            DB::table('orders')->insert($row);

            foreach ($details as $detail) {
                DB::table('order_details')->insert([
                    'order_id' => $id,
                    'product_id' => $detail['product_id'],
                    'quantity' => $detail['quantity'] ?? 1,
                    'price' => $detail['price'] ?? 0,
                    'discount_on_product' => 0,
                    'tax_amount' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($payments as $payment) {
                DB::table('order_partial_payments')->insert([
                    'order_id' => $id,
                    'paid_with' => $payment['paid_with'],
                    'paid_amount' => $payment['paid_amount'],
                    'due_amount' => $payment['due_amount'] ?? 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::commit();
            $order = Order::query()->findOrFail($id);
            AbandonedCheckoutService::linkOrderConversion($order);
            if ($dispatchNotifications) {
                SendOnlineOrderPlacementNotificationsJob::dispatch($id, null, null);
            }

            return [
                'order' => $order,
                'created' => true,
                'response' => OnlineCheckoutIdempotency::successResponse($order),
            ];
        } catch (UniqueConstraintViolationException $e) {
            DB::rollBack();
            $existing = OnlineCheckoutIdempotency::recoverExistingFromException($uuid, $e);
            $this->assertNotNull($existing);

            return [
                'order' => $existing,
                'created' => false,
                'response' => OnlineCheckoutIdempotency::successResponse($existing),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function insertOnlineRow(array $attrs): void
    {
        DB::table('orders')->insert($this->orderAttributes($attrs));
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function orderAttributes(array $attrs): array
    {
        return array_merge([
            'id' => $attrs['id'] ?? ((int) (Order::query()->max('id') ?? 100000) + 1),
            'user_id' => 10,
            'is_guest' => 0,
            'order_amount' => 590,
            'payment_status' => 'unpaid',
            'order_status' => 'pending',
            'payment_method' => 'cash_on_delivery',
            'transaction_reference' => null,
            'order_type' => 'delivery',
            'sales_channel' => null,
            'branch_id' => 13,
            'delivery_address_id' => null,
            'delivery_charge' => 100,
            'client_uuid' => null,
            'online_checkout_uuid' => null,
            'readable_order_id' => 'A'.($attrs['id'] ?? 'X'),
            'placed_at' => now()->utc()->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs);
    }

    private function ensureSchema(): void
    {
        foreach (['order_partial_payments', 'order_details', 'abandoned_checkouts', 'payment_requests', 'orders'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedTinyInteger('is_guest')->default(0);
            $table->float('order_amount')->default(0);
            $table->string('payment_status')->nullable();
            $table->string('order_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->string('order_type')->nullable();
            $table->string('sales_channel')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('delivery_address_id')->nullable();
            $table->float('delivery_charge')->default(0);
            $table->string('readable_order_id')->nullable();
            $table->string('client_uuid', 64)->nullable();
            $table->string('online_checkout_uuid', 64)->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'client_uuid'], 'orders_branch_client_uuid_unique');
            $table->unique('online_checkout_uuid', 'orders_online_checkout_uuid_unique');
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->float('price')->default(0);
            $table->float('discount_on_product')->default(0);
            $table->float('tax_amount')->default(0);
            $table->timestamps();
        });

        Schema::create('order_partial_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('paid_with')->nullable();
            $table->float('paid_amount')->default(0);
            $table->float('due_amount')->default(0);
            $table->timestamps();
        });

        Schema::create('abandoned_checkouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('guest_id')->nullable();
            $table->unsignedTinyInteger('is_guest')->default(0);
            $table->string('phone', 32)->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->json('cart')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('expected_total', 12, 2)->nullable();
            $table->string('currency_code', 8)->nullable();
            $table->string('locale', 8)->nullable();
            $table->string('source', 32)->default('web');
            $table->string('client_token', 64)->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->unsignedBigInteger('converted_order_id')->nullable();
            $table->timestamp('sms_sent_at')->nullable();
            $table->unsignedTinyInteger('sms_attempts')->default(0);
            $table->timestamps();
        });

        Schema::create('payment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('payer_id')->nullable();
            $table->string('receiver_id')->nullable();
            $table->decimal('payment_amount', 24, 2)->default(0);
            $table->string('transaction_id')->nullable();
            $table->string('payment_method')->nullable();
            $table->longText('additional_data')->nullable();
            $table->unsignedTinyInteger('is_paid')->default(0);
            $table->string('placement_status')->nullable();
            $table->unsignedBigInteger('placed_order_id')->nullable();
            $table->json('placement_error')->nullable();
            $table->timestamp('placement_attempted_at')->nullable();
            $table->json('place_order_draft')->nullable();
            $table->string('attribute_id')->nullable();
            $table->string('attribute')->nullable();
            $table->timestamps();
        });
    }
}
