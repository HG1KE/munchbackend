<?php

namespace {
    if (! function_exists('paystack_test_throwing_fulfillment_hook')) {
        function paystack_test_throwing_fulfillment_hook($data): void
        {
            throw new \Error('Class "App\\CentralLogics\\BusinessSetting" not found');
        }
    }
}

namespace Tests\Unit {

use App\CentralLogics\CustomerLogic;
use App\Http\Controllers\Api\V1\PaystackWebhookController;
use App\Http\Controllers\PaystackController;
use App\Model\BusinessSetting;
use App\Model\Order;
use App\Model\WalletTransaction;
use App\Models\PaymentRequest;
use App\Services\Paystack\PaystackFulfillmentService;
use App\Services\Paystack\PaystackFulfillmentSource;
use App\Services\Paystack\PaystackOrderProtectionService;
use App\Services\PaystackService;
use App\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaystackWalletFulfillmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSchema();
        $this->seedBusinessSettings();
        \App\CentralLogics\Helpers::forgetBusinessSettingsRuntimeCache();
        $this->bindTestPaystack();
    }

    public function test_successful_paystack_add_fund_credits_wallet_ledger(): void
    {
        $customer = $this->makeCustomer(0);
        $reference = 'PSK_wallet_'.Str::lower(Str::random(8));
        $payment = $this->makePaymentRequest('add-fund', $reference, $customer->id, 800, 'add_fund_success');

        $this->fakeSuccessfulVerify($reference, $payment, 80000);

        $result = app(PaystackFulfillmentService::class)->fulfillPaidCharge(
            $reference,
            PaystackFulfillmentSource::WEBHOOK,
            (string) $payment->id,
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('verified_non_order', $result['outcome']);
        $this->assertSame(PaymentRequest::PLACEMENT_RECONCILED, $result['placement_status']);
        $this->assertSame(1, (int) $payment->fresh()->is_paid);
        $this->assertSame(800.0, (float) $customer->fresh()->wallet_balance);

        $ledger = WalletTransaction::query()->where('user_id', $customer->id)->get();
        $this->assertCount(1, $ledger);
        $this->assertSame('add_fund', $ledger->first()->transaction_type);
        $this->assertSame(800.0, (float) $ledger->first()->credit);
        $this->assertSame(0.0, (float) $ledger->first()->debit);
        $this->assertSame(800.0, (float) $ledger->first()->balance);
    }

    public function test_successful_partial_paystack_order_debits_wallet_and_places_order(): void
    {
        $customer = $this->makeCustomer(250);
        $reference = 'PSK_partial_'.Str::lower(Str::random(8));
        $payment = $this->makePaymentRequest('order', $reference, $customer->id, 880, 'order_place');
        $payment->place_order_draft = [
            'is_partial' => '1',
            'order_amount' => 1030,
            'wallet_debit' => 250,
        ];
        $payment->save();

        $this->bindPartialOrderProtection();
        $this->fakeSuccessfulVerify($reference, $payment, 88000);

        $result = app(PaystackFulfillmentService::class)->fulfillPaidCharge(
            $reference,
            PaystackFulfillmentSource::WEBHOOK,
            (string) $payment->id,
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('order_placed', $result['outcome']);
        $this->assertTrue($result['order_placed']);
        $this->assertNotNull($result['order_id']);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame($reference, Order::query()->first()->transaction_reference);
        $this->assertSame(0.0, (float) $customer->fresh()->wallet_balance);

        $debit = WalletTransaction::query()
            ->where('user_id', $customer->id)
            ->where('transaction_type', 'order_place')
            ->first();
        $this->assertNotNull($debit);
        $this->assertSame(250.0, (float) $debit->debit);
        $this->assertSame(0.0, (float) $debit->credit);
        $this->assertSame(0.0, (float) $debit->balance);
    }

    public function test_fulfillment_exception_is_not_reported_as_success(): void
    {
        $customer = $this->makeCustomer(0);
        $reference = 'PSK_fail_'.Str::lower(Str::random(8));
        $payment = $this->makePaymentRequest(
            'add-fund',
            $reference,
            $customer->id,
            800,
            'paystack_test_throwing_fulfillment_hook'
        );

        $this->fakeSuccessfulVerify($reference, $payment, 80000);

        $result = app(PaystackFulfillmentService::class)->fulfillPaidCharge(
            $reference,
            PaystackFulfillmentSource::WEBHOOK,
            (string) $payment->id,
        );

        $this->assertSame('failed', $result['status']);
        $this->assertSame('verified_non_order', $result['outcome']);
        $this->assertFalse($result['order_placed']);
        $this->assertSame(PaymentRequest::PLACEMENT_PENDING, $result['placement_status']);
        $this->assertSame('non_order_fulfillment_exception', $result['placement_error']['code'] ?? null);
        $this->assertSame(1, (int) $payment->fresh()->is_paid);
        $this->assertSame(0.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(0, WalletTransaction::query()->count());

        $verify = app(PaystackController::class)->verifyInline(Request::create('/api/v1/paystack/verify', 'POST', [
            'reference' => $reference,
            'payment_id' => (string) $payment->id,
        ]));

        $this->assertSame(422, $verify->getStatusCode());
        $this->assertSame('fail', $verify->getData(true)['status']);
        $this->assertSame(0.0, (float) $customer->fresh()->wallet_balance);
    }

    public function test_duplicate_webhook_and_verify_remain_idempotent(): void
    {
        $customer = $this->makeCustomer(0);
        $reference = 'PSK_idemp_'.Str::lower(Str::random(8));
        $payment = $this->makePaymentRequest('add-fund', $reference, $customer->id, 800, 'add_fund_success');

        $this->fakeSuccessfulVerify($reference, $payment, 80000);

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'reference' => $reference,
                'status' => 'success',
                'amount' => 80000,
                'metadata' => [
                    'payment_request_id' => (string) $payment->id,
                    'attribute' => 'add-fund',
                ],
            ],
        ]);
        $signature = hash_hmac('sha512', $payload, 'sk_test_inline');

        $webhook = function () use ($payload, $signature) {
            $request = Request::create('/api/v1/paystack/webhook', 'POST', [], [], [], [], $payload);
            $request->headers->set('Content-Type', 'application/json');
            $request->headers->set('x-paystack-signature', $signature);

            return app(PaystackWebhookController::class)->handle($request);
        };

        $first = $webhook();
        $second = $webhook();
        $verify = app(PaystackController::class)->verifyInline(Request::create('/api/v1/paystack/verify', 'POST', [
            'reference' => $reference,
            'payment_id' => (string) $payment->id,
        ]));

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());
        $this->assertSame(200, $verify->getStatusCode());
        $this->assertSame('success', $verify->getData(true)['status']);
        $this->assertSame(1, (int) $payment->fresh()->is_paid);
        $this->assertSame(PaymentRequest::PLACEMENT_RECONCILED, $payment->fresh()->placement_status);
        $this->assertSame(800.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame(1, WalletTransaction::query()->where('user_id', $customer->id)->count());
    }

    public function test_place_order_rolls_back_php_throwable(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/OrderController.php'));
        $this->assertMatchesRegularExpression(
            '/catch \(Throwable \$e\) \{\s*DB::rollBack\(\);\s*throw \$e;/',
            $controller
        );
    }

    public function test_create_wallet_transaction_uses_business_setting_model(): void
    {
        $customer = $this->makeCustomer(0);

        $txn = CustomerLogic::create_wallet_transaction($customer->id, 800, 'add_fund', 'add-fund');

        $this->assertNotFalse($txn);
        $this->assertSame(800.0, (float) $customer->fresh()->wallet_balance);
        $this->assertSame('add_fund', $txn->transaction_type);
    }

    private function bindPartialOrderProtection(): void
    {
        $protection = new class(app(PaystackService::class)) extends PaystackOrderProtectionService
        {
            public function completeAfterVerify(PaymentRequest $paymentRequest, ?array $paymentDetails = null): array
            {
                $draft = is_array($paymentRequest->place_order_draft) ? $paymentRequest->place_order_draft : [];
                $debit = (float) ($draft['wallet_debit'] ?? 0);
                $userId = (int) $paymentRequest->payer_id;

                if ($debit > 0) {
                    $txn = CustomerLogic::create_wallet_transaction(
                        $userId,
                        $debit,
                        'order_place',
                        (string) $paymentRequest->id
                    );
                    if (! $txn) {
                        throw new \RuntimeException('Wallet debit failed.');
                    }
                }

                $orderId = (int) (Order::query()->max('id') ?? 100000) + 1;
                DB::table('orders')->insert([
                    'id' => $orderId,
                    'user_id' => $userId,
                    'order_amount' => $draft['order_amount'] ?? 1030,
                    'payment_method' => 'paystack',
                    'transaction_reference' => $paymentRequest->transaction_id,
                    'payment_status' => 'paid',
                    'order_status' => 'pending',
                    'readable_order_id' => 'A'.$orderId,
                ]);

                PaymentRequest::query()->where('id', $paymentRequest->id)->update([
                    'placement_status' => PaymentRequest::PLACEMENT_PLACED,
                    'placed_order_id' => $orderId,
                    'placement_error' => null,
                    'placement_attempted_at' => now(),
                ]);

                return [
                    'order_id' => $orderId,
                    'order_placed' => true,
                    'placement_status' => PaymentRequest::PLACEMENT_PLACED,
                    'placement_error' => null,
                ];
            }
        };

        $this->app->instance(PaystackOrderProtectionService::class, $protection);
        $this->app->forgetInstance(PaystackFulfillmentService::class);
    }

    /**
     * @param  PaymentRequest  $payment
     */
    private function fakeSuccessfulVerify(string $reference, PaymentRequest $payment, int $amountMinor): void
    {
        Http::fake([
            '*/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'amount' => $amountMinor,
                    'currency' => 'KES',
                    'metadata' => [
                        'payment_request_id' => (string) $payment->id,
                        'attribute_id' => $payment->attribute_id,
                        'attribute' => $payment->attribute,
                    ],
                ],
            ], 200),
        ]);
    }

    private function makeCustomer(float $walletBalance = 0): User
    {
        $user = new User();
        $user->f_name = 'Paystack';
        $user->l_name = 'Wallet';
        $user->email = 'wallet.'.Str::lower(Str::random(8)).'@example.com';
        $user->phone = '2547'.random_int(10000000, 99999999);
        $user->is_active = 1;
        $user->password = bcrypt('secret');
        $user->wallet_balance = $walletBalance;
        $user->language_code = 'en';
        $user->save();

        return $user;
    }

    private function makePaymentRequest(
        string $attribute,
        string $reference,
        int $payerId,
        float $amount,
        string $successHook,
    ): PaymentRequest {
        $payment = new PaymentRequest();
        $payment->payer_id = (string) $payerId;
        $payment->receiver_id = '100';
        $payment->payment_amount = $amount;
        $payment->success_hook = $successHook;
        $payment->failure_hook = 'order_cancel';
        $payment->transaction_id = $reference;
        $payment->currency_code = 'KES';
        $payment->payment_method = 'paystack';
        $payment->additional_data = json_encode(['business_name' => 'Munch']);
        $payment->is_paid = 0;
        $payment->placement_status = PaymentRequest::PLACEMENT_PENDING;
        $payment->payer_information = json_encode(['email' => 'payer@example.com', 'phone' => '254700000000']);
        $payment->receiver_information = json_encode([]);
        $payment->external_redirect_link = 'https://portal.munch.co.ke/checkout/web-payment';
        $payment->attribute = $attribute;
        $payment->attribute_id = (string) time().Str::lower(Str::random(4));
        $payment->payment_platform = 'web';
        $payment->save();

        return $payment;
    }

    private function bindTestPaystack(): void
    {
        $this->app->instance(PaystackService::class, new PaystackService(
            publicKey: 'pk_test_inline',
            secretKey: 'sk_test_inline',
            baseUrl: 'https://api.paystack.co',
            merchantEmail: 'merchant@example.com',
        ));
        $this->app->forgetInstance(PaystackFulfillmentService::class);
        $this->app->forgetInstance(PaystackController::class);
        $this->app->forgetInstance(PaystackWebhookController::class);
        $this->app->forgetInstance(PaystackOrderProtectionService::class);
    }

    private function seedBusinessSettings(): void
    {
        BusinessSetting::query()->delete();
        BusinessSetting::query()->insert([
            ['key' => 'wallet_status', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'add_fund_to_wallet', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'currency', 'value' => 'KES', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'restaurant_name', 'value' => 'Munch', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'add_wallet_message', 'value' => json_encode(['status' => 0, 'message' => '']), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'add_wallet_bonus_message', 'value' => json_encode(['status' => 0, 'message' => '']), 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function ensureSchema(): void
    {
        foreach ([
            'wallet_transactions',
            'wallet_bonuses',
            'payment_requests',
            'business_settings',
            'users',
            'orders',
            'addon_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('language_code')->nullable();
            $table->string('cm_firebase_token')->nullable();
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->decimal('wallet_balance', 24, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('transaction_id');
            $table->decimal('credit', 24, 3)->default(0);
            $table->decimal('debit', 24, 3)->default(0);
            $table->decimal('admin_bonus', 24, 3)->default(0);
            $table->decimal('balance', 24, 3)->default(0);
            $table->string('transaction_type')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_bonuses', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('bonus_type')->nullable();
            $table->decimal('bonus_amount', 24, 3)->default(0);
            $table->decimal('minimum_add_amount', 24, 3)->default(0);
            $table->decimal('maximum_bonus_amount', 24, 3)->default(0);
            $table->unsignedTinyInteger('status')->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->float('order_amount')->default(0);
            $table->string('payment_method')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('order_status')->nullable();
            $table->string('readable_order_id')->nullable();
        });

        Schema::create('addon_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key_name')->nullable();
            $table->string('settings_type')->nullable();
            $table->string('mode')->nullable();
            $table->longText('live_values')->nullable();
            $table->longText('test_values')->nullable();
        });

        Schema::create('payment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('payer_id')->nullable();
            $table->string('receiver_id')->nullable();
            $table->decimal('payment_amount', 24, 2)->default(0);
            $table->string('gateway_callback_url')->nullable();
            $table->string('success_hook')->nullable();
            $table->string('failure_hook')->nullable();
            $table->string('transaction_id')->nullable();
            $table->string('currency_code')->nullable();
            $table->string('payment_method')->nullable();
            $table->longText('additional_data')->nullable();
            $table->unsignedTinyInteger('is_paid')->default(0);
            $table->string('placement_status')->nullable();
            $table->unsignedBigInteger('placed_order_id')->nullable();
            $table->json('placement_error')->nullable();
            $table->timestamp('placement_attempted_at')->nullable();
            $table->json('place_order_draft')->nullable();
            $table->longText('payer_information')->nullable();
            $table->longText('external_redirect_link')->nullable();
            $table->longText('receiver_information')->nullable();
            $table->string('attribute_id')->nullable();
            $table->string('attribute')->nullable();
            $table->string('payment_platform')->nullable();
            $table->timestamps();
        });
    }
}
}
