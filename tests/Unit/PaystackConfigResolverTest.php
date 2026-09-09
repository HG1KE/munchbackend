<?php

namespace Tests\Unit;

use App\Http\Controllers\PaystackController;
use App\Services\Paystack\PaystackConfigResolver;
use App\Services\Paystack\PaystackFulfillmentService;
use App\Services\Paystack\PaystackWebhookSignatureVerifier;
use App\Services\PaystackService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use Tests\TestCase;

class PaystackConfigResolverTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnv = [
            'PAYSTACK_PUBLIC_KEY' => $_ENV['PAYSTACK_PUBLIC_KEY'] ?? null,
            'PAYSTACK_SECRET_KEY' => $_ENV['PAYSTACK_SECRET_KEY'] ?? null,
            'PAYSTACK_PAYMENT_URL' => $_ENV['PAYSTACK_PAYMENT_URL'] ?? null,
            'MERCHANT_EMAIL' => $_ENV['MERCHANT_EMAIL'] ?? null,
        ];

        $this->clearPaystackEnv();
        $this->resetPaystackConfigSnapshot(null, null);
        $this->ensureAddonSettingsSchema();
        $this->forgetResolvedPaystack();
    }

    protected function tearDown(): void
    {
        $this->restorePaystackEnv();
        $this->forgetResolvedPaystack();

        parent::tearDown();
    }

    public function test_empty_env_uses_admin_payment_settings(): void
    {
        $this->seedAdminPaystack('pk_live_admin', 'sk_live_admin', 'pay@munch.test');

        $config = app(PaystackConfigResolver::class)->resolveConfig();

        $this->assertSame('pk_live_admin', $config['public_key']);
        $this->assertSame('sk_live_admin', $config['secret_key']);
        $this->assertSame('pk_live_admin', $config['publicKey']);
        $this->assertSame('sk_live_admin', $config['secretKey']);
        $this->assertSame('pay@munch.test', $config['merchant_email']);
        $this->assertTrue(app(PaystackConfigResolver::class)->resolve()->isConfigured());
    }

    public function test_nonempty_env_overrides_admin(): void
    {
        $this->seedAdminPaystack('pk_live_admin', 'sk_live_admin');
        $this->setPaystackEnv('pk_env_override', 'sk_env_override');

        $config = app(PaystackConfigResolver::class)->resolveConfig();

        $this->assertSame('pk_env_override', $config['public_key']);
        $this->assertSame('sk_env_override', $config['secret_key']);
    }

    public function test_empty_string_env_does_not_override_admin(): void
    {
        $this->seedAdminPaystack('pk_live_admin', 'sk_live_admin');
        $this->setPaystackEnv('', '');
        $this->resetPaystackConfigSnapshot('', '');

        $config = app(PaystackConfigResolver::class)->resolveConfig();

        $this->assertSame('pk_live_admin', $config['public_key']);
        $this->assertSame('sk_live_admin', $config['secret_key']);
    }

    public function test_false_env_does_not_override_admin(): void
    {
        $this->seedAdminPaystack('pk_live_admin', 'sk_live_admin');
        $this->resetPaystackConfigSnapshot(false, false);

        $config = app(PaystackConfigResolver::class)->resolveConfig();

        $this->assertSame('pk_live_admin', $config['public_key']);
        $this->assertSame('sk_live_admin', $config['secret_key']);
    }

    public function test_cached_empty_camelcase_keys_do_not_shadow_admin(): void
    {
        $this->seedAdminPaystack('pk_live_admin', 'sk_live_admin');
        $this->resetPaystackConfigSnapshot('', '');

        $service = PaystackService::fromConfig(app(PaystackConfigResolver::class)->resolveConfig());

        $this->assertTrue($service->isConfigured());
        $this->assertSame('pk_live_admin', $service->getPublicKey());
        $this->assertSame('sk_live_admin', $service->getSecretKey());
    }

    public function test_from_config_skips_empty_false_and_null_camelcase_keys(): void
    {
        Config::set('paystack.public_key', null);
        Config::set('paystack.secret_key', null);
        Config::set('paystack.publicKey', null);
        Config::set('paystack.secretKey', null);

        $service = PaystackService::fromConfig([
            'publicKey' => '',
            'secretKey' => false,
            'public_key' => 'pk_from_admin',
            'secret_key' => 'sk_from_admin',
        ]);

        $this->assertTrue($service->isConfigured());
        $this->assertSame('pk_from_admin', $service->getPublicKey());
        $this->assertSame('sk_from_admin', $service->getSecretKey());
    }

    public function test_hosted_and_inline_resolve_identical_keys(): void
    {
        $this->seedAdminPaystack('pk_shared', 'sk_shared', 'merchant@munch.test');
        $this->resetPaystackConfigSnapshot('', '');
        $this->forgetResolvedPaystack();

        $resolverService = app(PaystackConfigResolver::class)->resolve();
        $singleton = app(PaystackService::class);
        $controller = app(PaystackController::class);
        $fulfillment = app(PaystackFulfillmentService::class);
        $verifier = app(PaystackWebhookSignatureVerifier::class);

        $controllerService = $this->controllerPaystack($controller);
        $fulfillmentService = $this->serviceProperty($fulfillment, 'paystack');
        $verifierService = $this->serviceProperty($verifier, 'paystack');

        $this->assertSame($singleton, $controllerService);
        $this->assertSame($singleton, $fulfillmentService);
        $this->assertSame($singleton, $verifierService);
        $this->assertSame('pk_shared', $singleton->getPublicKey());
        $this->assertSame('sk_shared', $singleton->getSecretKey());
        $this->assertSame($resolverService->getPublicKey(), $singleton->getPublicKey());
        $this->assertSame($resolverService->getSecretKey(), $singleton->getSecretKey());
        $this->assertTrue($singleton->isConfigured());
    }

    public function test_admin_credentials_mean_initialize_is_configured(): void
    {
        $this->seedAdminPaystack('pk_live_admin', 'sk_live_admin');
        $this->resetPaystackConfigSnapshot('', '');
        $this->forgetResolvedPaystack();

        $this->assertTrue(app(PaystackService::class)->isConfigured());
        $this->assertTrue($this->controllerPaystack(app(PaystackController::class))->isConfigured());
    }

    private function seedAdminPaystack(string $publicKey, string $secretKey, string $merchantEmail = 'pay@munch.test'): void
    {
        DB::table('addon_settings')->where('key_name', 'paystack')->delete();
        DB::table('addon_settings')->insert([
            'id' => (string) Str::uuid(),
            'key_name' => 'paystack',
            'settings_type' => 'payment_config',
            'mode' => 'live',
            'is_active' => 1,
            'live_values' => json_encode([
                'gateway' => 'paystack',
                'mode' => 'live',
                'status' => '1',
                'public_key' => $publicKey,
                'secret_key' => $secretKey,
                'merchant_email' => $merchantEmail,
            ]),
            'test_values' => json_encode([
                'public_key' => 'pk_test_admin',
                'secret_key' => 'sk_test_admin',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resetPaystackConfigSnapshot(mixed $publicKey, mixed $secretKey): void
    {
        Config::set('paystack.publicKey', $publicKey);
        Config::set('paystack.secretKey', $secretKey);
        Config::set('paystack.public_key', $publicKey);
        Config::set('paystack.secret_key', $secretKey);
    }

    private function setPaystackEnv(?string $publicKey, ?string $secretKey): void
    {
        foreach ([
            'PAYSTACK_PUBLIC_KEY' => $publicKey,
            'PAYSTACK_SECRET_KEY' => $secretKey,
        ] as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function clearPaystackEnv(): void
    {
        foreach (['PAYSTACK_PUBLIC_KEY', 'PAYSTACK_SECRET_KEY', 'PAYSTACK_PAYMENT_URL', 'MERCHANT_EMAIL'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    private function restorePaystackEnv(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private function forgetResolvedPaystack(): void
    {
        $this->app->forgetInstance(PaystackService::class);
        $this->app->forgetInstance(PaystackConfigResolver::class);
        $this->app->forgetInstance(PaystackController::class);
        $this->app->forgetInstance(PaystackFulfillmentService::class);
        $this->app->forgetInstance(PaystackWebhookSignatureVerifier::class);
    }

    private function controllerPaystack(PaystackController $controller): PaystackService
    {
        return $this->serviceProperty($controller, 'paystack');
    }

    private function serviceProperty(object $instance, string $property): PaystackService
    {
        $reflection = new ReflectionClass($instance);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $value = $prop->getValue($instance);
        $this->assertInstanceOf(PaystackService::class, $value);

        return $value;
    }

    private function ensureAddonSettingsSchema(): void
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
                $table->timestamps();
            });

            return;
        }

        DB::table('addon_settings')->where('key_name', 'paystack')->delete();
    }
}
