<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Model\WalletTransaction;
use App\Services\OrderWalletCancelRefundService;
use App\Services\PosOrderCancellationService;
use App\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderWalletCancelRefundTest extends TestCase
{
    private OrderWalletCancelRefundService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSchema();
        $this->service = app(OrderWalletCancelRefundService::class);
    }

    public function test_full_wallet_cancel_restores_balance_and_writes_order_refund_ledger(): void
    {
        $customer = $this->makeCustomer(0);
        $order = $this->persistOrder([
            'user_id' => $customer->id,
            'payment_method' => 'wallet_payment',
            'order_amount' => 500,
        ]);
        $this->recordPlaceDebit($customer->id, $order->id, 500);

        $result = $this->service->refundIfNeeded($order);

        $this->assertTrue($result['refunded']);
        $this->assertFalse($result['replayed']);
        $this->assertSame(500.0, $result['amount']);
        $this->assertSame(500.0, (float) $customer->fresh()->wallet_balance);

        $ledger = WalletTransaction::query()
            ->where('user_id', $customer->id)
            ->where('transaction_type', 'order_refund')
            ->where('reference', (string) $order->id)
            ->first();
        $this->assertNotNull($ledger);
        $this->assertSame(500.0, (float) $ledger->credit);
        $this->assertSame(0.0, (float) $ledger->debit);
        $this->assertSame(500.0, (float) $ledger->balance);
        $this->assertStringStartsWith('ORDCXL_', $ledger->transaction_id);
    }

    public function test_partial_wallet_cancel_refunds_only_wallet_portion(): void
    {
        $customer = $this->makeCustomer(20);
        $order = $this->persistOrder([
            'user_id' => $customer->id,
            'payment_method' => 'digital_payment',
            'order_amount' => 800,
        ]);
        // Customer paid 250 from wallet + 550 via gateway.
        $this->recordPlaceDebit($customer->id, $order->id, 250);

        $result = $this->service->refundIfNeeded($order);

        $this->assertTrue($result['refunded']);
        $this->assertSame(250.0, $result['amount']);
        $this->assertSame(270.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(1, WalletTransaction::query()
            ->where('transaction_type', 'order_refund')
            ->where('reference', (string) $order->id)
            ->count());
    }

    public function test_double_cancel_does_not_credit_twice(): void
    {
        $customer = $this->makeCustomer(0);
        $order = $this->persistOrder([
            'user_id' => $customer->id,
            'payment_method' => 'wallet_payment',
            'order_amount' => 100,
        ]);
        $this->recordPlaceDebit($customer->id, $order->id, 100);

        $first = $this->service->refundIfNeeded($order);
        $second = $this->service->refundIfNeeded($order);

        $this->assertTrue($first['refunded']);
        $this->assertFalse($second['refunded']);
        $this->assertTrue($second['replayed']);
        $this->assertSame(100.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(1, WalletTransaction::query()
            ->where('transaction_type', 'order_refund')
            ->where('reference', (string) $order->id)
            ->count());
    }

    public function test_prior_edit_order_refund_only_restores_remaining_net(): void
    {
        $customer = $this->makeCustomer(0);
        $order = $this->persistOrder([
            'user_id' => $customer->id,
            'payment_method' => 'wallet_payment',
            'order_amount' => 400,
        ]);
        $this->recordPlaceDebit($customer->id, $order->id, 400);
        // Edit-order already returned 150 when the cart total shrank.
        $this->recordRefundCredit($customer->id, $order->id, 150);
        $customer->wallet_balance = 150;
        $customer->save();

        $result = $this->service->refundIfNeeded($order);

        $this->assertTrue($result['refunded']);
        $this->assertSame(250.0, $result['amount']);
        $this->assertSame(400.0, (float) $customer->fresh()->wallet_balance);
    }

    public function test_non_wallet_order_is_a_no_op(): void
    {
        $customer = $this->makeCustomer(75);
        $order = $this->persistOrder([
            'user_id' => $customer->id,
            'payment_method' => 'cash_on_delivery',
            'order_amount' => 200,
        ]);

        $result = $this->service->refundIfNeeded($order);

        $this->assertFalse($result['refunded']);
        $this->assertSame(75.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(0, WalletTransaction::query()->where('transaction_type', 'order_refund')->count());
    }

    public function test_pos_cancel_path_restores_wallet(): void
    {
        $customer = $this->makeCustomer(0);
        $order = $this->persistOrder([
            'user_id' => $customer->id,
            'order_status' => 'confirmed',
            'sales_channel' => 'takeaway',
            'order_type' => 'pos',
            'payment_method' => 'wallet_payment',
            'order_amount' => 320,
        ]);
        $this->recordPlaceDebit($customer->id, $order->id, 320);

        $cancel = app(PosOrderCancellationService::class)->cancel(
            $order,
            'Customer changed mind',
            'branch',
            3,
            'wallet-cancel-uuid',
            'pos'
        );

        $this->assertTrue($cancel['success']);
        $this->assertFalse($cancel['duplicate']);
        $this->assertSame('canceled', $order->fresh()->order_status);
        $this->assertSame(320.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(1, WalletTransaction::query()
            ->where('transaction_type', 'order_refund')
            ->where('reference', (string) $order->id)
            ->count());

        $replay = app(PosOrderCancellationService::class)->cancel(
            $order->fresh(),
            'Customer changed mind again',
            'branch',
            3,
            'wallet-cancel-uuid-2',
            'pos'
        );
        $this->assertTrue($replay['success']);
        $this->assertTrue($replay['duplicate']);
        $this->assertSame(320.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(1, WalletTransaction::query()
            ->where('transaction_type', 'order_refund')
            ->where('reference', (string) $order->id)
            ->count());
    }

    public function test_cancel_entry_points_invoke_wallet_refund_service(): void
    {
        $admin = file_get_contents(app_path('Http/Controllers/Admin/OrderController.php'));
        $branch = file_get_contents(app_path('Http/Controllers/Branch/OrderController.php'));
        $api = file_get_contents(app_path('Http/Controllers/Api/V1/OrderController.php'));
        $pos = file_get_contents(app_path('Services/PosOrderCancellationService.php'));

        foreach ([$admin, $branch, $api, $pos] as $source) {
            $this->assertStringContainsString('OrderWalletCancelRefundService', $source);
            $this->assertStringContainsString('refundIfNeeded', $source);
        }
    }

    private function makeCustomer(float $balance): User
    {
        $customer = new User();
        $customer->f_name = 'Wallet';
        $customer->l_name = 'Customer';
        $customer->email = 'wallet'.Str::lower(Str::random(6)).'@example.com';
        $customer->phone = '07'.random_int(10000000, 99999999);
        $customer->password = bcrypt('secret');
        $customer->wallet_balance = $balance;
        $customer->save();

        return $customer;
    }

    private function persistOrder(array $attrs): Order
    {
        $id = DB::table('orders')->insertGetId([
            'user_id' => $attrs['user_id'] ?? 0,
            'is_guest' => $attrs['is_guest'] ?? 0,
            'order_amount' => $attrs['order_amount'] ?? 100,
            'payment_status' => $attrs['payment_status'] ?? 'paid',
            'order_status' => $attrs['order_status'] ?? 'pending',
            'payment_method' => $attrs['payment_method'] ?? 'wallet_payment',
            'order_type' => $attrs['order_type'] ?? 'delivery',
            'sales_channel' => $attrs['sales_channel'] ?? null,
            'branch_id' => 7,
            'delivery_charge' => $attrs['delivery_charge'] ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::query()->findOrFail($id);
    }

    private function recordPlaceDebit(int $userId, int $orderId, float $amount): void
    {
        WalletTransaction::query()->create([
            'user_id' => $userId,
            'transaction_id' => 'PLACE_'.Str::random(12),
            'reference' => (string) $orderId,
            'transaction_type' => 'order_place',
            'credit' => 0,
            'debit' => $amount,
            'balance' => 0,
            'admin_bonus' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function recordRefundCredit(int $userId, int $orderId, float $amount): void
    {
        WalletTransaction::query()->create([
            'user_id' => $userId,
            'transaction_id' => 'EDITRF_'.Str::random(12),
            'reference' => (string) $orderId,
            'transaction_type' => 'order_refund',
            'credit' => $amount,
            'debit' => 0,
            'balance' => $amount,
            'admin_bonus' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureSchema(): void
    {
        foreach ([
            'order_cancellation_audit_logs',
            'wallet_transactions',
            'orders',
            'users',
            'branches',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });
        DB::table('branches')->insert(['id' => 7, 'name' => 'Westlands']);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->decimal('wallet_balance', 24, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('is_guest')->default(0);
            $table->float('order_amount')->default(0);
            $table->float('delivery_charge')->default(0);
            $table->string('payment_status')->nullable();
            $table->string('order_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('order_type')->nullable();
            $table->string('sales_channel')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancelled_by_type', 16)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->string('pos_cancel_client_uuid', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('transaction_id');
            $table->decimal('credit', 24, 3)->default(0);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('admin_bonus', 24, 3)->default(0);
            $table->decimal('balance', 24, 3)->default(0);
            $table->string('transaction_type')->nullable();
            $table->string('reference')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('order_cancellation_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('actor_type', 16)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32)->nullable();
            $table->string('reason', 500);
            $table->string('source', 32)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('payment_status', 32)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
