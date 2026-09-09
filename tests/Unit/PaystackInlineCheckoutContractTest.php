<?php

namespace {
    if (! function_exists('paystack_test_success_hook')) {
        function paystack_test_success_hook($data): void
        {
            $GLOBALS['paystack_test_success_hook_calls'] = ($GLOBALS['paystack_test_success_hook_calls'] ?? 0) + 1;
        }
    }
}

namespace Tests\Unit {
use App\Http\Controllers\Api\V1\DigitalPaymentController;
use App\Http\Controllers\PaystackController;
use App\Model\BusinessSetting;
use App\Models\PaymentRequest;
use App\Services\Payments\Intent\Support\PaymentInitiationResponder;
use App\Services\PaystackService;
use App\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaystackInlineCheckoutContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['paystack_test_success_hook_calls'] = 0;
        $this->ensureSchema();
        $this->seedBusinessSettings();
        \App\CentralLogics\Helpers::forgetBusinessSettingsRuntimeCache();
        $this->bindTestPaystack();
    }

    public function test_initialize_returns_meatco_inline_payload(): void
    {
        Http::fake([
            '*/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'access_code' => 'acc_inline',
                    'reference' => 'PSK_inline_ref',
                    'authorization_url' => 'https://paystack.test/authorize',
                ],
            ], 200),
        ]);

        $payment = $this->makePaymentRequest('order');

        $response = app(PaystackController::class)->initializeInline(Request::create('/api/v1/paystack/initialize', 'POST', [
            'payment_id' => (string) $payment->id,
            'email' => 'checkout@example.com',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);

        $this->assertSame('paystack_inline', $payload['checkout_mode']);
        $this->assertSame((string) $payment->id, $payload['payment_id']);
        $this->assertSame('acc_inline', $payload['paystack']['access_code']);
        $this->assertSame('PSK_inline_ref', $payload['paystack']['reference']);
        $this->assertSame('https://paystack.test/authorize', $payload['paystack']['authorization_url']);
        $this->assertSame('pk_test_inline', $payload['paystack']['public_key']);
        $this->assertSame('checkout@example.com', $payload['paystack']['email']);
        $this->assertSame(25000, $payload['paystack']['amount']);
        $this->assertSame('KES', $payload['paystack']['currency']);
        $this->assertSame($payment->attribute_id, $payload['paystack']['metadata']['attribute_id']);
        $this->assertSame((string) $payment->id, $payload['paystack']['metadata']['payment_request_id']);
        $this->assertSame('order', $payload['paystack']['metadata']['attribute']);
    }

    public function test_payment_mobile_inline_checkout_returns_popup_payload(): void
    {
        Http::fake([
            '*/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'access_code' => 'acc_mobile',
                    'reference' => 'PSK_mobile_ref',
                    'authorization_url' => 'https://paystack.test/mobile',
                ],
            ], 200),
        ]);

        $payment = $this->makePaymentRequest('order');
        $redirectLink = url('payment/paystack/pay/?payment_id='.(string) $payment->id);

        $response = app(PaymentInitiationResponder::class)
            ->paystackInlineCheckoutResponse($redirectLink, 'mobile@example.com');

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame('paystack_inline', $payload['checkout_mode']);
        $this->assertArrayHasKey('payment_id', $payload);
        $this->assertArrayHasKey('access_code', $payload['paystack']);
        $this->assertArrayHasKey('reference', $payload['paystack']);
        $this->assertArrayHasKey('authorization_url', $payload['paystack']);
        $this->assertArrayHasKey('public_key', $payload['paystack']);
        $this->assertArrayHasKey('amount', $payload['paystack']);
        $this->assertArrayHasKey('currency', $payload['paystack']);
        $this->assertArrayHasKey('email', $payload['paystack']);
        $this->assertArrayHasKey('metadata', $payload['paystack']);
    }

    public function test_add_fund_without_inline_checkout_keeps_redirect_link_only(): void
    {
        $customer = $this->makeCustomer();

        $response = app(DigitalPaymentController::class)->addFund(Request::create('/api/v1/add-fund-wallet', 'POST', [
            'amount' => 250,
            'payment_method' => 'paystack',
            'payment_platform' => 'web',
            'call_back' => 'https://portal.munch.co.ke/wallet/web-payment',
            'customer_id' => $customer->id,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame(['redirect_link'], array_keys($payload));
        $this->assertStringContainsString('payment/paystack/pay', $payload['redirect_link']);
        $this->assertStringContainsString('payment_id=', $payload['redirect_link']);
    }

    public function test_add_fund_inline_checkout_returns_popup_payload(): void
    {
        Http::fake([
            '*/transaction/initialize' => Http::response([
                'status' => true,
                'data' => [
                    'access_code' => 'acc_wallet',
                    'reference' => 'PSK_wallet_ref',
                    'authorization_url' => 'https://paystack.test/wallet',
                ],
            ], 200),
        ]);

        $customer = $this->makeCustomer();

        $response = app(DigitalPaymentController::class)->addFund(Request::create('/api/v1/add-fund-wallet', 'POST', [
            'amount' => 250,
            'payment_method' => 'paystack',
            'payment_platform' => 'web',
            'call_back' => 'https://portal.munch.co.ke/wallet/web-payment',
            'customer_id' => $customer->id,
            'inline_checkout' => true,
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $payload = $response->getData(true);
        $this->assertSame('paystack_inline', $payload['checkout_mode']);
        $this->assertArrayHasKey('payment_id', $payload);
        $this->assertArrayHasKey('paystack', $payload);
        $this->assertArrayNotHasKey('redirect_link', $payload);
    }

    public function test_verify_marks_paid_runs_hook_and_is_idempotent(): void
    {
        $reference = 'PSK_verify_'.Str::lower(Str::random(8));
        $payment = $this->makePaymentRequest('add-fund', $reference);
        $payment->success_hook = 'paystack_test_success_hook';
        $payment->save();

        Http::fake([
            '*/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success',
                    'amount' => 25000,
                    'currency' => 'KES',
                    'metadata' => [
                        'payment_request_id' => $payment->id,
                        'attribute_id' => $payment->attribute_id,
                    ],
                ],
            ], 200),
        ]);

        $request = Request::create('/api/v1/paystack/verify', 'POST', [
            'reference' => $reference,
            'payment_id' => (string) $payment->id,
        ]);

        $first = app(PaystackController::class)->verifyInline($request);
        $second = app(PaystackController::class)->verifyInline($request);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame(200, $second->getStatusCode());

        $payload = $first->getData(true);
        $this->assertSame('success', $payload['status']);
        $this->assertSame($reference, $payload['reference']);
        $this->assertArrayHasKey('token', $payload);
        $this->assertArrayHasKey('callback_url', $payload);
        $this->assertSame($payment->attribute_id, $payload['attribute_id']);
        $this->assertSame((string) $payment->id, $payload['payment_id']);
        $this->assertArrayHasKey('order_id', $payload);
        $this->assertArrayHasKey('readable_order_id', $payload);
        $this->assertArrayHasKey('order_display_id', $payload);
        $this->assertArrayHasKey('order_placed', $payload);
        $this->assertArrayHasKey('placement_status', $payload);
        $this->assertArrayHasKey('placement_error', $payload);

        $this->assertSame(1, $GLOBALS['paystack_test_success_hook_calls']);
        $this->assertSame(1, (int) PaymentRequest::query()->find($payment->id)->is_paid);
    }

    public function test_verify_unpaid_gateway_status_returns_402(): void
    {
        $reference = 'PSK_fail_'.Str::lower(Str::random(8));
        $payment = $this->makePaymentRequest('order', $reference);

        Http::fake([
            '*/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'abandoned',
                    'metadata' => ['payment_request_id' => $payment->id],
                ],
            ], 200),
        ]);

        $response = app(PaystackController::class)->verifyInline(Request::create('/api/v1/paystack/verify', 'POST', [
            'reference' => $reference,
        ]));

        $this->assertSame(402, $response->getStatusCode());
        $this->assertSame('fail', $response->getData(true)['status']);
        $this->assertSame(0, (int) PaymentRequest::query()->find($payment->id)->is_paid);
        $this->assertSame(0, $GLOBALS['paystack_test_success_hook_calls']);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $response = app(\App\Http\Controllers\Api\V1\PaystackWebhookController::class)
            ->handle(Request::create('/api/v1/paystack/webhook', 'POST', [], [], [], [], '{"event":"charge.success"}'));

        $this->assertSame(401, $response->getStatusCode());
    }

    private function makeCustomer(): User
    {
        $user = new User();
        $user->f_name = 'Paystack';
        $user->l_name = 'Customer';
        $user->email = 'paystack.'.Str::lower(Str::random(8)).'@example.com';
        $user->phone = '2547'.random_int(10000000, 99999999);
        $user->is_active = 1;
        $user->password = bcrypt('secret');
        $user->save();

        return $user;
    }

    private function makePaymentRequest(string $attribute, ?string $reference = null): PaymentRequest
    {
        $payment = new PaymentRequest();
        $payment->payer_id = '1';
        $payment->receiver_id = '100';
        $payment->payment_amount = 250;
        $payment->success_hook = 'paystack_test_success_hook';
        $payment->failure_hook = 'order_cancel';
        $payment->transaction_id = $reference;
        $payment->currency_code = 'KES';
        $payment->payment_method = 'paystack';
        $payment->additional_data = json_encode(['business_name' => 'Munch']);
        $payment->is_paid = 0;
        $payment->payer_information = json_encode(['email' => 'payer@example.com', 'phone' => '254700000000']);
        $payment->receiver_information = json_encode([]);
        $payment->external_redirect_link = 'https://portal.munch.co.ke/checkout/web-payment';
        $payment->attribute = $attribute;
        $payment->attribute_id = (string) time();
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
    }

    private function seedBusinessSettings(): void
    {
        BusinessSetting::query()->delete();
        BusinessSetting::query()->insert([
            ['key' => 'add_fund_to_wallet', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'wallet_status', 'value' => '1', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'currency', 'value' => 'KES', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'restaurant_name', 'value' => 'Munch', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'logo', 'value' => 'logo.png', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function ensureSchema(): void
    {
        Schema::dropIfExists('payment_requests');
        Schema::dropIfExists('business_settings');
        Schema::dropIfExists('users');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('addon_settings');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
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

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('payment_method')->nullable();
            $table->string('transaction_reference')->nullable();
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
