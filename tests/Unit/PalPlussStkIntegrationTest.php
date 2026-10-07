<?php

namespace Tests\Unit;

use App\Exceptions\PalPlussException;
use App\Models\PalPlussPaymentAttempt;
use App\Models\PaymentRequest;
use App\Models\Setting;
use App\Services\PalPluss\PalPlussConfigResolver;
use App\Services\PalPluss\PalPlussFulfillmentService;
use App\Services\PalPluss\PalPlussHttpClient;
use App\Services\PalPluss\PalPlussPhoneNormalizer;
use App\Services\PalPluss\PalPlussStkInitiator;
use App\Services\PalPluss\PalPlussTransactionVerifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PalPlussStkIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'palpluss.api_key' => null,
            'palpluss.base_url' => 'https://api.palpluss.com/v1',
            'palpluss.channel_id' => null,
            'app.url' => 'https://portal.munch.co.ke',
        ]);

        $this->ensureSchema();
        $this->seedAdminPalpluss();
    }

    private function ensureSchema(): void
    {
        Schema::dropIfExists('palpluss_payment_attempts');
        Schema::dropIfExists('payment_requests');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('addon_settings');

        Schema::create('addon_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key_name');
            $table->json('live_values')->nullable();
            $table->json('test_values')->nullable();
            $table->string('settings_type');
            $table->string('mode')->default('live');
            $table->tinyInteger('is_active')->default(0);
            $table->text('additional_data')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('payment_method')->nullable();
            $table->string('transaction_reference')->nullable();
            $table->string('online_checkout_uuid')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('payer_id')->nullable();
            $table->string('receiver_id')->nullable();
            $table->string('payment_amount')->nullable();
            $table->string('success_hook')->nullable();
            $table->string('failure_hook')->nullable();
            $table->string('currency_code')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('transaction_id')->nullable();
            $table->tinyInteger('is_paid')->default(0);
            $table->string('attribute')->nullable();
            $table->string('attribute_id')->nullable();
            $table->string('payment_platform')->nullable();
            $table->text('additional_data')->nullable();
            $table->text('payer_information')->nullable();
            $table->text('receiver_information')->nullable();
            $table->string('external_redirect_link')->nullable();
            $table->json('place_order_draft')->nullable();
            $table->string('placement_status', 24)->default('pending');
            $table->unsignedBigInteger('placed_order_id')->nullable();
            $table->json('placement_error')->nullable();
            $table->timestamp('placement_attempted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('palpluss_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('payment_request_id');
            $table->string('transaction_id', 64)->nullable()->unique();
            $table->string('account_reference', 12);
            $table->string('phone', 20);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('KES');
            $table->string('channel_id', 64)->nullable();
            $table->string('status', 32)->default('initiated');
            $table->string('provider_request_id', 128)->nullable();
            $table->string('provider_checkout_id', 128)->nullable();
            $table->string('mpesa_receipt', 64)->nullable();
            $table->string('result_code', 32)->nullable();
            $table->string('result_desc', 255)->nullable();
            $table->json('last_webhook_payload')->nullable();
            $table->timestamp('stk_initiated_at')->nullable();
            $table->timestamp('terminal_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->unsignedBigInteger('placed_order_id')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });
    }

    private function seedAdminPalpluss(): void
    {
        $resolver = app(PalPlussConfigResolver::class);
        $values = [
            'gateway' => 'palpluss',
            'mode' => 'live',
            'status' => 1,
            'api_key_encrypted' => $resolver->encryptApiKey('pk_test_fake'),
            'channel_id' => '11111111-1111-1111-1111-111111111111',
            'channel_shortcode' => '123456',
            'channel_type' => 'TILL_NUMBER',
            'channel_name' => 'Test Till',
        ];

        $setting = new Setting();
        $setting->id = (string) Str::uuid();
        $setting->key_name = 'palpluss';
        $setting->settings_type = 'payment_config';
        $setting->live_values = $values;
        $setting->test_values = $values;
        $setting->mode = 'live';
        $setting->is_active = 1;
        $setting->additional_data = json_encode(['gateway_title' => 'M-PESA (PalPluss)']);
        $setting->save();
    }

    private function makePaymentRequest(array $overrides = []): PaymentRequest
    {
        $payment = new PaymentRequest();
        $payment->payer_id = '1';
        $payment->receiver_id = '100';
        $payment->payment_amount = 250;
        $payment->success_hook = 'order_place';
        $payment->failure_hook = 'order_cancel';
        $payment->currency_code = 'KES';
        $payment->payment_method = 'palpluss';
        $payment->is_paid = 0;
        $payment->attribute = 'order';
        $payment->attribute_id = (string) time();
        $payment->payment_platform = 'web';
        $payment->additional_data = json_encode([]);
        $payment->payer_information = json_encode([]);
        $payment->receiver_information = json_encode([]);
        $payment->external_redirect_link = 'https://portal.munch.co.ke/checkout';
        $payment->place_order_draft = ['cart' => [['id' => 1]]];
        $payment->placement_status = 'pending';

        foreach ($overrides as $key => $value) {
            $payment->{$key} = $value;
        }

        $payment->save();

        return $payment;
    }

    public function test_phone_normalizer_accepts_kenyan_formats(): void
    {
        $n = new PalPlussPhoneNormalizer();
        $this->assertSame('254712345678', $n->normalize('0712345678'));
        $this->assertSame('254712345678', $n->normalize('+254712345678'));
        $this->assertSame('254712345678', $n->normalize('254712345678'));
        $this->assertSame('254712345678', $n->normalize('712345678'));
        $this->assertNull($n->normalize('abc'));
    }

    public function test_http_client_uses_basic_auth_and_channel_on_stk(): void
    {
        Http::fake([
            'https://api.palpluss.com/v1/payments/stk' => Http::response([
                'success' => true,
                'requestId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'data' => [
                    'transactionId' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                    'status' => 'PENDING',
                    'amount' => 500,
                    'currency' => 'KES',
                    'phone' => '254712345678',
                ],
            ], 200),
        ]);

        $client = app(PalPlussHttpClient::class);
        $data = $client->post('/payments/stk', [
            'amount' => 500,
            'phone' => '254712345678',
            'channelId' => $client->channelId(),
            'accountReference' => 'ABCDEF123456',
            'transactionDesc' => 'Munch order',
            'callbackUrl' => 'https://portal.munch.co.ke/api/v1/palpluss/webhook',
        ]);

        $this->assertSame('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', $data['transactionId']);

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization')[0] ?? '';
            $expected = 'Basic '.base64_encode('pk_test_fake:');
            $body = $request->data();

            return $auth === $expected
                && ($body['channelId'] ?? null) === '11111111-1111-1111-1111-111111111111'
                && ! array_key_exists('credential_id', $body)
                && (float) ($body['amount'] ?? 0) === 500.0;
        });
    }

    public function test_stk_initiator_persists_attempt_and_does_not_mark_paid(): void
    {
        Http::fake([
            'https://api.palpluss.com/v1/payments/stk' => Http::response([
                'success' => true,
                'requestId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'data' => [
                    'transactionId' => 'cccccccc-cccc-cccc-cccc-cccccccccccc',
                    'status' => 'PENDING',
                    'amount' => 250,
                    'currency' => 'KES',
                    'phone' => '254700000001',
                    'providerRequestId' => 'req-1',
                    'providerCheckoutId' => 'chk-1',
                ],
            ], 200),
        ]);

        $pr = $this->makePaymentRequest([
            'id' => '123e4567-e89b-12d3-a456-426614174000',
            'payment_amount' => 250,
        ]);

        $result = app(PalPlussStkInitiator::class)->initiate($pr, '0700000001');

        $this->assertSame('palpluss_stk', $result['checkout_mode']);
        $this->assertSame('cccccccc-cccc-cccc-cccc-cccccccccccc', $result['transaction_id']);
        $this->assertSame(0, (int) $pr->fresh()->is_paid);

        $attempt = PalPlussPaymentAttempt::query()->first();
        $this->assertNotNull($attempt);
        $this->assertSame('pending', $attempt->status);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $attempt->channel_id);
        $this->assertNull($attempt->fulfilled_at);
    }

    public function test_stk_initiation_failure_does_not_fulfill(): void
    {
        Http::fake([
            'https://api.palpluss.com/v1/payments/stk' => Http::response([
                'success' => false,
                'requestId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'error' => ['code' => 'INVALID_PHONE', 'message' => 'bad phone'],
            ], 400),
        ]);

        $pr = $this->makePaymentRequest(['payment_amount' => 100]);

        $this->expectException(PalPlussException::class);
        app(PalPlussStkInitiator::class)->initiate($pr, '0700000001');
    }

    public function test_amount_mismatch_rejects_fulfillment(): void
    {
        $pr = $this->makePaymentRequest([
            'id' => '323e4567-e89b-12d3-a456-426614174000',
            'payment_amount' => 500,
            'transaction_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
        ]);

        PalPlussPaymentAttempt::query()->create([
            'payment_request_id' => $pr->id,
            'transaction_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
            'account_reference' => '323e4567e89b',
            'phone' => '254700000001',
            'amount' => 500,
            'currency' => 'KES',
            'channel_id' => '11111111-1111-1111-1111-111111111111',
            'status' => 'pending',
        ]);

        Http::fake([
            'https://api.palpluss.com/v1/transactions/*' => Http::response([
                'success' => true,
                'requestId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'data' => [
                    'transaction_id' => 'dddddddd-dddd-dddd-dddd-dddddddddddd',
                    'status' => 'SUCCESS',
                    'amount' => 100,
                    'currency' => 'KES',
                    'external_reference' => '323e4567e89b',
                    'mpesa_receipt' => 'ABC123',
                ],
            ], 200),
        ]);

        $result = app(PalPlussFulfillmentService::class)->fulfill(
            'dddddddd-dddd-dddd-dddd-dddddddddddd',
            PalPlussFulfillmentService::SOURCE_WEBHOOK
        );

        $this->assertSame('failed', $result['outcome']);
        $this->assertSame(0, (int) $pr->fresh()->is_paid);
        $this->assertStringContainsString('Amount mismatch', (string) $result['message']);
    }

    public function test_duplicate_webhook_does_not_double_fulfill_when_already_placed(): void
    {
        $pr = $this->makePaymentRequest([
            'transaction_id' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
            'payment_amount' => 200,
            'is_paid' => 1,
            'placement_status' => 'placed',
            'placed_order_id' => 999001,
        ]);

        PalPlussPaymentAttempt::query()->create([
            'payment_request_id' => $pr->id,
            'transaction_id' => 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
            'account_reference' => '423e4567e89b',
            'phone' => '254700000001',
            'amount' => 200,
            'currency' => 'KES',
            'channel_id' => '11111111-1111-1111-1111-111111111111',
            'status' => 'success',
            'placed_order_id' => 999001,
            'fulfilled_at' => now(),
        ]);

        Http::fake();

        $result = app(PalPlussFulfillmentService::class)->fulfill(
            'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee',
            PalPlussFulfillmentService::SOURCE_WEBHOOK
        );

        $this->assertSame('already_placed', $result['outcome']);
        $this->assertSame(999001, $result['order_id']);
        Http::assertNothingSent();
    }

    public function test_cancelled_status_does_not_mark_paid(): void
    {
        $pr = $this->makePaymentRequest([
            'transaction_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
            'payment_amount' => 150,
        ]);

        PalPlussPaymentAttempt::query()->create([
            'payment_request_id' => $pr->id,
            'transaction_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
            'account_reference' => '523e4567e89b',
            'phone' => '254700000001',
            'amount' => 150,
            'currency' => 'KES',
            'channel_id' => '11111111-1111-1111-1111-111111111111',
            'status' => 'pending',
        ]);

        Http::fake([
            'https://api.palpluss.com/v1/transactions/*' => Http::response([
                'success' => true,
                'requestId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'data' => [
                    'transaction_id' => 'ffffffff-ffff-ffff-ffff-ffffffffffff',
                    'status' => 'CANCELLED',
                    'amount' => 150,
                    'currency' => 'KES',
                    'external_reference' => '523e4567e89b',
                ],
            ], 200),
        ]);

        $result = app(PalPlussFulfillmentService::class)->fulfill(
            'ffffffff-ffff-ffff-ffff-ffffffffffff',
            PalPlussFulfillmentService::SOURCE_BROWSER_VERIFY
        );

        $this->assertSame('not_paid', $result['outcome']);
        $this->assertSame(0, (int) $pr->fresh()->is_paid);
        $this->assertSame('cancelled', PalPlussPaymentAttempt::query()->first()->status);
    }

    public function test_verifier_rejects_currency_mismatch(): void
    {
        $pr = new PaymentRequest();
        $pr->payment_amount = 100;
        $attempt = new PalPlussPaymentAttempt();
        $attempt->transaction_id = 'tx-1';
        $attempt->account_reference = 'REF123';

        $this->expectException(PalPlussException::class);
        app(PalPlussTransactionVerifier::class)->assertSuccessfulForPayment($pr, $attempt, [
            'transaction_id' => 'tx-1',
            'status' => 'SUCCESS',
            'amount' => 100,
            'currency' => 'USD',
            'external_reference' => 'REF123',
        ]);
    }

    public function test_stk_body_never_includes_credential_id_or_till_number(): void
    {
        Http::fake([
            'https://api.palpluss.com/v1/payments/stk' => Http::response([
                'success' => true,
                'requestId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'data' => [
                    'transactionId' => '99999999-9999-9999-9999-999999999999',
                    'status' => 'PENDING',
                    'amount' => 50,
                    'currency' => 'KES',
                    'phone' => '254711111111',
                ],
            ], 200),
        ]);

        $pr = $this->makePaymentRequest(['payment_amount' => 50]);
        app(PalPlussStkInitiator::class)->initiate($pr, '0711111111');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ! array_key_exists('credential_id', $body)
                && ! array_key_exists('credentialId', $body)
                && ! array_key_exists('tillNumber', $body)
                && ! array_key_exists('shortcode', $body)
                && ($body['channelId'] ?? null) === '11111111-1111-1111-1111-111111111111';
        });
    }
}
