<?php

namespace Tests\Unit;

use App\Exceptions\PalPlussException;
use App\Models\Setting;
use App\Services\PalPluss\PalPlussConfigResolver;
use App\Services\PalPluss\PalPlussHttpClient;
use App\Services\PalPluss\PalPlussSettingsService;
use App\Services\PalPluss\PalPlussStkInitiator;
use App\Models\PaymentRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PalPlussAdminSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'palpluss.base_url' => 'https://api.palpluss.com/v1',
            'palpluss.api_key' => null,
            'palpluss.channel_id' => null,
        ]);
        putenv('PALPLUSS_API_KEY');
        putenv('PALPLUSS_CHANNEL_ID');
        $_ENV['PALPLUSS_API_KEY'] = '';
        $_ENV['PALPLUSS_CHANNEL_ID'] = '';
        $_SERVER['PALPLUSS_API_KEY'] = '';
        $_SERVER['PALPLUSS_CHANNEL_ID'] = '';

        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        Schema::dropIfExists('addon_settings');
        Schema::dropIfExists('palpluss_payment_attempts');
        Schema::dropIfExists('payment_requests');
        Schema::dropIfExists('orders');

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

    private function saveAdminConfig(array $overrides = []): void
    {
        $resolver = app(PalPlussConfigResolver::class);
        $apiKey = $overrides['api_key'] ?? 'pk_live_test_secret_key_value';
        unset($overrides['api_key']);

        $values = array_merge([
            'gateway' => 'palpluss',
            'mode' => 'live',
            'status' => 1,
            'api_key_encrypted' => $resolver->encryptApiKey($apiKey),
            'channel_id' => '11111111-1111-1111-1111-111111111111',
            'channel_shortcode' => '123456',
            'channel_type' => 'TILL_NUMBER',
            'channel_name' => 'Store Till',
        ], $overrides);

        Setting::updateOrCreate(
            ['key_name' => 'palpluss', 'settings_type' => 'payment_config'],
            [
                'live_values' => $values,
                'test_values' => $values,
                'mode' => 'live',
                'is_active' => (int) ($values['status'] ?? 1),
                'additional_data' => json_encode(['gateway_title' => 'M-PESA (PalPluss)']),
            ]
        );
    }

    public function test_api_key_is_encrypted_at_rest_and_masked_in_admin_view(): void
    {
        $plain = 'pk_live_super_secret_value_12345';
        app(PalPlussSettingsService::class)->update([
            'status' => 1,
            'mode' => 'live',
            'api_key' => $plain,
            'channel_id' => '11111111-1111-1111-1111-111111111111',
            'channel_type' => 'TILL_NUMBER',
            'channel_shortcode' => '123456',
            'channel_name' => 'Till',
        ]);

        $row = Setting::query()->where('key_name', 'palpluss')->first();
        $this->assertNotNull($row);
        $stored = $row->live_values['api_key_encrypted'] ?? '';
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString($plain, json_encode($row->live_values));
        $this->assertSame($plain, Crypt::decryptString($stored));

        $view = app(PalPlussConfigResolver::class)->adminSafeView();
        $this->assertTrue($view['api_key_configured']);
        $this->assertStringNotContainsString($plain, $view['api_key_masked']);
        $this->assertArrayNotHasKey('api_key', $view);
    }

    public function test_blank_api_key_keeps_existing_encrypted_value(): void
    {
        $this->saveAdminConfig(['api_key' => 'pk_live_original_key_aaaa']);
        app(PalPlussSettingsService::class)->update([
            'status' => 1,
            'api_key' => '',
            'channel_id' => '11111111-1111-1111-1111-111111111111',
            'channel_type' => 'TILL_NUMBER',
        ]);

        $this->assertSame(
            'pk_live_original_key_aaaa',
            app(PalPlussConfigResolver::class)->resolve()['api_key']
        );
    }

    public function test_rejects_non_till_channel_selection(): void
    {
        $this->expectException(PalPlussException::class);
        app(PalPlussSettingsService::class)->update([
            'status' => 1,
            'api_key' => 'pk_live_x',
            'channel_id' => '22222222-2222-2222-2222-222222222222',
            'channel_type' => 'PAYBILL',
        ]);
    }

    public function test_disabled_provider_blocks_stk(): void
    {
        $this->saveAdminConfig(['status' => 0]);
        Setting::query()->where('key_name', 'palpluss')->update(['is_active' => 0]);

        $pr = new PaymentRequest();
        $pr->payer_id = '1';
        $pr->receiver_id = '100';
        $pr->payment_amount = 100;
        $pr->success_hook = 'order_place';
        $pr->failure_hook = 'order_cancel';
        $pr->currency_code = 'KES';
        $pr->payment_method = 'palpluss';
        $pr->is_paid = 0;
        $pr->attribute = 'order';
        $pr->attribute_id = '1';
        $pr->payment_platform = 'web';
        $pr->additional_data = '{}';
        $pr->payer_information = '{}';
        $pr->receiver_information = '{}';
        $pr->external_redirect_link = 'https://example.com';
        $pr->placement_status = 'pending';
        $pr->save();

        $this->expectException(PalPlussException::class);
        app(PalPlussStkInitiator::class)->initiate($pr, '0712345678');
    }

    public function test_list_channels_uses_basic_auth_and_filters_till(): void
    {
        $this->saveAdminConfig();

        Http::fake([
            'https://api.palpluss.com/v1/payment-wallet/channels' => Http::response([
                'success' => true,
                'requestId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'data' => [
                    'items' => [
                        [
                            'id' => '11111111-1111-1111-1111-111111111111',
                            'type' => 'TILL_NUMBER',
                            'shortcode' => '123456',
                            'name' => 'Store Till',
                            'isDefault' => true,
                        ],
                        [
                            'id' => '33333333-3333-3333-3333-333333333333',
                            'type' => 'PAYBILL',
                            'shortcode' => '999999',
                            'name' => 'Paybill',
                            'isDefault' => false,
                        ],
                    ],
                ],
            ], 200),
        ]);

        $channels = app(PalPlussSettingsService::class)->listChannels();
        $this->assertCount(2, $channels);
        $this->assertTrue($channels[0]['till_like']);
        $this->assertFalse($channels[1]['till_like']);

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization')[0] ?? '';

            return $auth === 'Basic '.base64_encode('pk_live_test_secret_key_value:');
        });
    }

    public function test_stk_uses_admin_channel_id_without_credential_id(): void
    {
        $this->saveAdminConfig();

        Http::fake([
            'https://api.palpluss.com/v1/payments/stk' => Http::response([
                'success' => true,
                'requestId' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
                'data' => [
                    'transactionId' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb',
                    'status' => 'PENDING',
                    'amount' => 100,
                    'currency' => 'KES',
                    'phone' => '254712345678',
                ],
            ], 200),
        ]);

        $pr = new PaymentRequest();
        $pr->payer_id = '1';
        $pr->receiver_id = '100';
        $pr->payment_amount = 100;
        $pr->success_hook = 'order_place';
        $pr->failure_hook = 'order_cancel';
        $pr->currency_code = 'KES';
        $pr->payment_method = 'palpluss';
        $pr->is_paid = 0;
        $pr->attribute = 'order';
        $pr->attribute_id = '1';
        $pr->payment_platform = 'web';
        $pr->additional_data = '{}';
        $pr->payer_information = '{}';
        $pr->receiver_information = '{}';
        $pr->external_redirect_link = 'https://example.com';
        $pr->placement_status = 'pending';
        $pr->save();

        app(PalPlussStkInitiator::class)->initiate($pr, '0712345678');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['channelId'] ?? null) === '11111111-1111-1111-1111-111111111111'
                && ! array_key_exists('credential_id', $body)
                && ! array_key_exists('shortcode', $body);
        });
    }

    public function test_admin_takes_precedence_over_env(): void
    {
        config(['palpluss.api_key' => 'pk_env_should_not_win', 'palpluss.channel_id' => 'env-channel']);
        $this->saveAdminConfig([
            'api_key' => 'pk_admin_wins_xxxxxxxxxxxx',
            'channel_id' => '11111111-1111-1111-1111-111111111111',
        ]);

        $resolved = app(PalPlussConfigResolver::class)->resolve();
        $this->assertSame('pk_admin_wins_xxxxxxxxxxxx', $resolved['api_key']);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $resolved['channel_id']);
        $this->assertSame('admin', $resolved['source']);
    }

    public function test_http_client_is_configured_from_admin(): void
    {
        $this->assertFalse(app(PalPlussHttpClient::class)->isConfigured());
        $this->saveAdminConfig();
        $this->assertTrue(app(PalPlussHttpClient::class)->isConfigured());
        $this->assertTrue(app(PalPlussHttpClient::class)->isEnabled());
    }
}
