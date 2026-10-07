<?php

namespace Tests\Unit;

use App\CentralLogics\Helpers;
use App\CentralLogics\StorefrontConfigService;
use App\Models\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

class StorefrontPaymentMethodsConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureSchemas();
        $this->seedBaseBusinessSettings();
        Helpers::forgetBusinessSettingsRuntimeCache();
        StorefrontConfigService::forgetCachedConfiguration();
        Config::set('get_payment_publish_status', []);
    }

    protected function tearDown(): void
    {
        StorefrontConfigService::forgetCachedConfiguration();
        Helpers::forgetBusinessSettingsRuntimeCache();

        parent::tearDown();
    }

    public function test_default_payment_gateways_path_includes_enabled_paystack(): void
    {
        $this->seedPaymentGateway('paystack', true, 'Debit/Credit Card Online', [
            'public_key' => 'pk_test_fake',
            'secret_key' => 'sk_test_fake',
        ]);

        $payload = $this->configurationPayload();

        $this->assertSame('true', $payload['digital_payment_info']['default_payment_gateways']);
        $this->assertSame('false', $payload['digital_payment_info']['plugin_payment_gateways']);
        $this->assertContainsGateway($payload, 'paystack', 'Debit/Credit Card Online');
    }

    public function test_default_payment_gateways_path_includes_enabled_palpluss(): void
    {
        $this->seedPaymentGateway('paystack', true, 'Debit/Credit Card Online', [
            'public_key' => 'pk_test_fake',
            'secret_key' => 'sk_test_fake',
        ]);
        $this->seedPaymentGateway('palpluss', true, 'M-PESA (PalPluss)', [
            'api_key_encrypted' => 'enc:fake-not-a-real-key',
            'channel_id' => 'fake-channel-id',
            'channel_type' => 'TILL',
            'channel_shortcode' => '000000',
        ]);

        $payload = $this->configurationPayload();

        $this->assertSame('true', $payload['digital_payment_info']['default_payment_gateways']);
        $this->assertSame('false', $payload['digital_payment_info']['plugin_payment_gateways']);
        $this->assertContainsGateway($payload, 'paystack', 'Debit/Credit Card Online');
        $this->assertContainsGateway($payload, 'palpluss', 'M-PESA (PalPluss)');
    }

    public function test_disabled_palpluss_is_hidden(): void
    {
        $this->seedPaymentGateway('paystack', true, 'Debit/Credit Card Online', [
            'public_key' => 'pk_test_fake',
            'secret_key' => 'sk_test_fake',
        ]);
        $this->seedPaymentGateway('palpluss', false, 'M-PESA (PalPluss)', [
            'api_key_encrypted' => 'enc:fake-not-a-real-key',
            'channel_id' => 'fake-channel-id',
        ]);

        $payload = $this->configurationPayload();

        $this->assertContainsGateway($payload, 'paystack', 'Debit/Credit Card Online');
        $this->assertNotContainsGateway($payload, 'palpluss');
    }

    public function test_admin_gateway_title_is_returned_for_palpluss(): void
    {
        $this->seedPaymentGateway('palpluss', true, 'Custom PalPluss Title', [
            'api_key_encrypted' => 'enc:fake-not-a-real-key',
            'channel_id' => 'fake-channel-id',
        ]);

        $payload = $this->configurationPayload();

        $this->assertContainsGateway($payload, 'palpluss', 'Custom PalPluss Title');
    }

    public function test_payment_config_change_invalidates_storefront_cache(): void
    {
        $this->seedPaymentGateway('palpluss', true, 'Original Title', [
            'api_key_encrypted' => 'enc:fake-not-a-real-key',
            'channel_id' => 'fake-channel-id',
        ]);

        $first = $this->configurationPayload();
        $this->assertContainsGateway($first, 'palpluss', 'Original Title');
        $this->assertTrue(Cache::has(StorefrontConfigService::cacheKey()));

        $row = Setting::query()
            ->where('key_name', 'palpluss')
            ->where('settings_type', 'payment_config')
            ->firstOrFail();

        $row->additional_data = json_encode([
            'gateway_title' => 'Updated Title From Admin',
            'gateway_image' => '',
        ]);
        $row->save();

        $this->assertFalse(
            Cache::has(StorefrontConfigService::cacheKey()),
            'payment_config updates must invalidate storefront_api_config_v1'
        );

        $second = $this->configurationPayload();
        $this->assertContainsGateway($second, 'palpluss', 'Updated Title From Admin');
    }

    public function test_storefront_response_does_not_expose_payment_secrets(): void
    {
        $this->seedPaymentGateway('palpluss', true, 'M-PESA (PalPluss)', [
            'api_key_encrypted' => 'enc:super-secret-key-material',
            'channel_id' => 'secret-channel-id',
            'channel_type' => 'TILL',
            'channel_shortcode' => '123456',
            'status' => 1,
        ]);
        $this->seedPaymentGateway('paystack', true, 'Debit/Credit Card Online', [
            'public_key' => 'pk_live_secretish',
            'secret_key' => 'sk_live_secretish',
        ]);

        $payload = $this->configurationPayload();
        $encoded = json_encode($payload);

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('enc:super-secret-key-material', $encoded);
        $this->assertStringNotContainsString('secret-channel-id', $encoded);
        $this->assertStringNotContainsString('sk_live_secretish', $encoded);
        $this->assertStringNotContainsString('pk_live_secretish', $encoded);
        $this->assertStringNotContainsString('api_key_encrypted', $encoded);
        $this->assertStringNotContainsString('channel_id', $encoded);
        $this->assertStringNotContainsString('secret_key', $encoded);

        foreach ($payload['active_payment_method_list'] as $method) {
            $this->assertSame(['gateway', 'gateway_title', 'gateway_image'], array_keys($method));
        }
    }

    public function test_source_no_longer_uses_hardcoded_gateway_whitelist(): void
    {
        $source = file_get_contents(app_path('CentralLogics/StorefrontConfigService.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('getActivePaymentMethodsFromAddonSettings', $source);
        $this->assertStringNotContainsString("whereIn('key_name'", $source);
        $this->assertStringNotContainsString("'mercadopago'", $source);
        $this->assertStringNotContainsString("'ssl_commerz'", $source);
    }

    public function test_active_payment_methods_helper_matches_admin_enabled_rows(): void
    {
        $this->seedPaymentGateway('paystack', true, 'Paystack', ['public_key' => 'pk']);
        $this->seedPaymentGateway('palpluss', true, 'PalPluss', ['api_key_encrypted' => 'enc:x']);
        $this->seedPaymentGateway('stripe', false, 'Stripe Hidden', ['api_key' => 'x']);

        $methods = $this->invokeActivePaymentMethods();
        $gateways = array_column($methods, 'gateway');

        $this->assertSame(['paystack', 'palpluss'], $gateways);
    }

    /**
     * @return array<string, mixed>
     */
    private function configurationPayload(): array
    {
        Helpers::forgetBusinessSettingsRuntimeCache();

        return StorefrontConfigService::getConfigurationPayload();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function invokeActivePaymentMethods(): array
    {
        $method = new ReflectionMethod(StorefrontConfigService::class, 'getActivePaymentMethodsFromAddonSettings');
        $method->setAccessible(true);

        /** @var array<int, array<string, mixed>> $result */
        $result = $method->invoke(null);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertContainsGateway(array $payload, string $gateway, string $title): void
    {
        $match = null;
        foreach ($payload['active_payment_method_list'] ?? [] as $method) {
            if (($method['gateway'] ?? null) === $gateway) {
                $match = $method;
                break;
            }
        }

        $this->assertNotNull($match, "Expected gateway [{$gateway}] in active_payment_method_list");
        $this->assertSame($title, $match['gateway_title'] ?? null);
        $this->assertArrayHasKey('gateway_image', $match);
        $this->assertArrayNotHasKey('api_key_encrypted', $match);
        $this->assertArrayNotHasKey('channel_id', $match);
        $this->assertArrayNotHasKey('secret_key', $match);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertNotContainsGateway(array $payload, string $gateway): void
    {
        foreach ($payload['active_payment_method_list'] ?? [] as $method) {
            $this->assertNotSame($gateway, $method['gateway'] ?? null);
        }
    }

    /**
     * @param  array<string, mixed>  $liveValues
     */
    private function seedPaymentGateway(string $keyName, bool $enabled, string $title, array $liveValues): void
    {
        DB::table('addon_settings')->where('key_name', $keyName)->where('settings_type', 'payment_config')->delete();

        $values = array_merge(['gateway' => $keyName, 'mode' => 'live', 'status' => $enabled ? 1 : 0], $liveValues);

        DB::table('addon_settings')->insert([
            'id' => (string) Str::uuid(),
            'key_name' => $keyName,
            'settings_type' => 'payment_config',
            'mode' => 'live',
            'is_active' => $enabled ? 1 : 0,
            'live_values' => json_encode($values),
            'test_values' => json_encode($values),
            'additional_data' => json_encode([
                'gateway_title' => $title,
                'gateway_image' => '',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedBaseBusinessSettings(): void
    {
        DB::table('business_settings')->delete();
        DB::table('business_settings')->insert([
            [
                'key' => 'digital_payment',
                'value' => json_encode(['status' => 1]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'cash_on_delivery',
                'value' => json_encode(['status' => 1]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'currency',
                'value' => json_encode('KES'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'restaurant_name',
                'value' => 'Munch Test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    private function ensureSchemas(): void
    {
        if (! Schema::hasTable('addon_settings')) {
            Schema::create('addon_settings', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('key_name')->nullable();
                $table->string('settings_type')->nullable();
                $table->string('mode')->nullable();
                $table->tinyInteger('is_active')->default(1);
                $table->longText('live_values')->nullable();
                $table->longText('test_values')->nullable();
                $table->longText('additional_data')->nullable();
                $table->timestamps();
            });
        } else {
            DB::table('addon_settings')->delete();
            if (! Schema::hasColumn('addon_settings', 'additional_data')) {
                Schema::table('addon_settings', function (Blueprint $table) {
                    $table->longText('additional_data')->nullable();
                });
            }
        }

        if (! Schema::hasTable('business_settings')) {
            Schema::create('business_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key');
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('login_setups')) {
            Schema::create('login_setups', function (Blueprint $table) {
                $table->id();
                $table->string('key');
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('currencies')) {
            Schema::create('currencies', function (Blueprint $table) {
                $table->id();
                $table->string('country')->nullable();
                $table->string('currency_code')->nullable();
                $table->string('currency_symbol')->nullable();
                $table->decimal('exchange_rate', 8, 2)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('time_schedules')) {
            Schema::create('time_schedules', function (Blueprint $table) {
                $table->id();
                $table->unsignedTinyInteger('day')->nullable();
                $table->time('opening_time')->nullable();
                $table->time('closing_time')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('branches')) {
            Schema::create('branches', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('longitude')->nullable();
                $table->string('latitude')->nullable();
                $table->string('address')->nullable();
                $table->integer('coverage')->nullable();
                $table->tinyInteger('status')->default(1);
                $table->string('image')->nullable();
                $table->string('cover_image')->nullable();
                $table->integer('preparation_time')->nullable();
                $table->tinyInteger('branch_promotion_status')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('branch_promotions')) {
            Schema::create('branch_promotions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('branch_time_schedules')) {
            Schema::create('branch_time_schedules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedTinyInteger('day')->nullable();
                $table->time('opening_time')->nullable();
                $table->time('closing_time')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('social_medias')) {
            Schema::create('social_medias', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('link')->nullable();
                $table->tinyInteger('status')->default(1);
                $table->tinyInteger('active_status')->default(1);
                $table->timestamps();
            });
        }
    }
}
