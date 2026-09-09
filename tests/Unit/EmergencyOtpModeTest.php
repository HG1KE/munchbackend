<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\Auth\CustomerAuthController;
use App\Model\BusinessSetting;
use App\Model\PhoneVerification;
use App\Models\LoginSetup;
use App\Models\ReferralCustomer;
use App\Services\Auth\EmergencyOtpModeService;
use App\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmergencyOtpModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    public function test_otp_flow_is_unchanged_when_emergency_mode_is_disabled(): void
    {
        LoginSetup::query()->create(['key' => EmergencyOtpModeService::SETTING_KEY, 'value' => '0']);
        LoginSetup::query()->create(['key' => 'phone_verification', 'value' => '0']);

        $response = $this->controller()->checkPhone($this->phoneRequest());
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('inactive', $payload['token']);
        $this->assertSame(translate('Number is ready to register'), $payload['message']);
        $this->assertArrayNotHasKey('emergency_otp_mode', $payload);
        $this->assertArrayNotHasKey('otp_required', $payload);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('phone_verifications', 0);
    }

    public function test_invalid_otp_is_rejected_when_emergency_mode_is_disabled(): void
    {
        LoginSetup::query()->create(['key' => EmergencyOtpModeService::SETTING_KEY, 'value' => '0']);
        $this->createCustomer('0712345678');
        DB::table('phone_verifications')->insert([
            'phone' => '0712345678',
            'token' => '123456',
            'otp_hit_count' => 0,
            'is_temp_blocked' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->controller()->verifyOTP(Request::create('/api/v1/auth/verify-otp', 'POST', [
            'phone' => '0712345678',
            'token' => '000000',
        ]));
        $payload = $response->getData(true);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertArrayHasKey('errors', $payload);
        $this->assertSame('token', $payload['errors'][0]['code']);
    }

    public function test_emergency_mode_verify_otp_bypasses_code_for_existing_customer(): void
    {
        LoginSetup::query()->create(['key' => EmergencyOtpModeService::SETTING_KEY, 'value' => '1']);
        $customer = $this->createCustomer('0712345678');

        $response = $this->controller()->verifyOTP(Request::create('/api/v1/auth/verify-otp', 'POST', [
            'phone' => '0712345678',
            'token' => '000000',
        ]));
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test-token-'.$customer->id, $payload['token']);
        $this->assertTrue($payload['status']);
        $this->assertTrue($payload['emergency_otp_mode']);
        $this->assertFalse($payload['otp_required']);
    }

    public function test_emergency_mode_logs_in_existing_customer_without_generating_otp(): void
    {
        LoginSetup::query()->create(['key' => EmergencyOtpModeService::SETTING_KEY, 'value' => '1']);
        $customer = $this->createCustomer('0712345678');

        $response = $this->controller()->checkPhone($this->phoneRequest());
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('test-token-'.$customer->id, $payload['token']);
        $this->assertTrue($payload['status']);
        $this->assertFalse($payload['otp_required']);
        $this->assertTrue($payload['emergency_otp_mode']);
        $this->assertFalse($payload['customer_created']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('phone_verifications', 0);
    }

    public function test_emergency_mode_routes_new_phone_to_registration_without_creating_customer(): void
    {
        LoginSetup::query()->create(['key' => EmergencyOtpModeService::SETTING_KEY, 'value' => '1']);

        $response = $this->controller()->checkPhone($this->phoneRequest());
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertArrayHasKey('temporary_token', $payload);
        $this->assertArrayNotHasKey('temp_token', $payload);
        $this->assertNotEmpty($payload['temporary_token']);
        $this->assertFalse($payload['status']);
        $this->assertFalse($payload['otp_required']);
        $this->assertTrue($payload['emergency_otp_mode']);
        $this->assertFalse($payload['customer_created']);
        $this->assertArrayNotHasKey('token', $payload);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('phone_verifications', 1);
        $this->assertDatabaseHas('phone_verifications', [
            'phone' => '0712345678',
            'token' => $payload['temporary_token'],
        ]);
    }

    public function test_emergency_mode_off_immediately_restores_otp_verification(): void
    {
        $service = app(EmergencyOtpModeService::class);
        $service->set(true, 'SMS outage', 1, '127.0.0.1');
        $this->assertTrue($service->enabled());

        $service->set(false, 'SMS restored', 1, '127.0.0.1');
        $this->assertFalse($service->enabled());

        LoginSetup::query()->updateOrCreate(['key' => 'phone_verification'], ['value' => '0']);

        $response = $this->controller()->checkPhone($this->phoneRequest());
        $payload = $response->getData(true);

        $this->assertSame('inactive', $payload['token']);
        $this->assertArrayNotHasKey('emergency_otp_mode', $payload);
    }

    public function test_business_setting_toggle_records_audit_log(): void
    {
        $service = app(EmergencyOtpModeService::class);

        $service->set(true, 'Safaricom SMS outage', 7, '10.0.0.1');
        $service->set(false, 'SMS restored', 8, '10.0.0.2');

        $this->assertFalse($service->enabled());
        $this->assertDatabaseHas('login_setups', [
            'key' => EmergencyOtpModeService::SETTING_KEY,
            'value' => '0',
        ]);
        $this->assertDatabaseHas('emergency_otp_mode_audit_logs', [
            'admin_id' => 7,
            'action' => 'enabled',
            'enabled' => 1,
            'ip_address' => '10.0.0.1',
            'reason' => 'Safaricom SMS outage',
        ]);
        $this->assertDatabaseHas('emergency_otp_mode_audit_logs', [
            'admin_id' => 8,
            'action' => 'disabled',
            'enabled' => 0,
            'ip_address' => '10.0.0.2',
            'reason' => 'SMS restored',
        ]);
    }

    public function test_admin_authentication_page_exposes_emergency_warning_and_contract(): void
    {
        $view = file_get_contents(resource_path('views/admin-views/business-settings/login-setup.blade.php'));
        $controller = file_get_contents(app_path('Http/Controllers/Api/V1/Auth/CustomerAuthController.php'));

        $this->assertStringContainsString('Emergency OTP Mode', $view);
        $this->assertStringContainsString('OTP verification is currently DISABLED', $view);
        $this->assertStringContainsString('Any customer can access an account using only a phone number', $view);
        $this->assertStringContainsString('emergency_otp_mode_reason', $view);
        $this->assertStringContainsString('otp_required', $controller);
        $this->assertStringContainsString('emergency_otp_mode', $controller);
    }

    public function test_emergency_change_does_not_touch_forbidden_domains_or_logout_routes(): void
    {
        foreach ([
            'AbandonedCheckout',
            'OrderController',
            'PaymentRequest',
            'Mpesa',
            'CustomerAddress',
            'Supplier',
            'Purchase',
        ] as $term) {
            $this->assertStringNotContainsString(
                $term,
                file_get_contents(app_path('Services/Auth/EmergencyOtpModeService.php'))
            );
        }

        $routes = file_get_contents(base_path('routes/api/v1/api.php'));
        $this->assertStringNotContainsString('emergency-otp', $routes);
        $this->assertSame(1, substr_count($routes, "Route::post('logout'"));
    }

    public function test_storefront_config_includes_emergency_otp_flag(): void
    {
        $source = file_get_contents(app_path('CentralLogics/StorefrontConfigService.php'));

        $this->assertStringContainsString("'emergency_otp_mode'", $source);
    }

    private function controller(): CustomerAuthController
    {
        return new class (
            new User(),
            new BusinessSetting(),
            new PhoneVerification(),
            new LoginSetup(),
            new ReferralCustomer(),
            app(EmergencyOtpModeService::class),
        ) extends CustomerAuthController {
            protected function issueRestaurantCustomerToken(User $user): string
            {
                return 'test-token-'.$user->id;
            }
        };
    }

    private function phoneRequest(): Request
    {
        return Request::create('/api/v1/auth/check-phone', 'POST', [
            'phone' => '0712345678',
        ]);
    }

    private function createCustomer(string $phone): User
    {
        $user = new User();
        $user->f_name = 'Existing';
        $user->l_name = 'Customer';
        $user->phone = $phone;
        $user->email = null;
        $user->password = bcrypt('password');
        $user->is_active = 1;
        $user->is_phone_verified = 1;
        $user->user_type = null;
        $user->save();

        return $user;
    }

    private function ensureSchema(): void
    {
        foreach ([
            'emergency_otp_mode_audit_logs',
            'phone_verifications',
            'login_setups',
            'referral_customers',
            'business_settings',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->unique();
            $table->string('email')->nullable();
            $table->string('password');
            $table->unsignedTinyInteger('is_phone_verified')->default(0);
            $table->unsignedTinyInteger('is_active')->default(1);
            $table->string('user_type')->nullable();
            $table->string('refer_code')->nullable();
            $table->string('login_medium')->nullable();
            $table->string('temporary_token')->nullable();
            $table->string('language_code')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('login_setups', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('phone');
            $table->string('token')->nullable();
            $table->unsignedInteger('otp_hit_count')->default(0);
            $table->boolean('is_temp_blocked')->default(false);
            $table->timestamp('temp_block_time')->nullable();
            $table->timestamps();
        });

        Schema::create('referral_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('refer_by')->nullable();
            $table->timestamps();
        });

        Schema::create('emergency_otp_mode_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('action', 32);
            $table->boolean('enabled')->default(false);
            $table->string('ip_address', 64)->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
