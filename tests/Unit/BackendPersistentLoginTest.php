<?php

namespace Tests\Unit;

use Tests\TestCase;

class BackendPersistentLoginTest extends TestCase
{
    public function test_admin_and_branch_login_pages_have_no_remember_me_checkbox(): void
    {
        $admin = file_get_contents(resource_path('views/admin-views/auth/login.blade.php'));
        $branch = file_get_contents(resource_path('views/branch-views/auth/login.blade.php'));

        $this->assertStringNotContainsString('name="remember"', $admin);
        $this->assertStringNotContainsString("translate('remember_me')", $admin);
        $this->assertStringNotContainsString('termsCheckbox', $admin);

        $this->assertStringNotContainsString('name="remember"', $branch);
        $this->assertStringNotContainsString("translate('remember_me')", $branch);
        $this->assertStringNotContainsString('termsCheckbox', $branch);
    }

    public function test_admin_and_branch_logins_always_issue_remember_cookies(): void
    {
        $admin = file_get_contents(app_path('Http/Controllers/Admin/Auth/LoginController.php'));
        $branch = file_get_contents(app_path('Http/Controllers/Branch/Auth/LoginController.php'));

        $this->assertStringContainsString("auth('admin')->attempt(['email' => \$request->email, 'password' => \$request->password], true)", $admin);
        $this->assertStringNotContainsString('$request->remember', $admin);
        $this->assertStringContainsString("withInput(\$request->only('email'))", $admin);

        $this->assertStringContainsString("'status' => 1", $branch);
        $this->assertStringContainsString('], true))', $branch);
        $this->assertStringNotContainsString('$request->remember', $branch);
        $this->assertStringContainsString("withInput(\$request->only('email'))", $branch);
    }

    public function test_backend_sessions_and_remember_cookies_are_long_lived(): void
    {
        $this->assertFalse((bool) config('session.expire_on_close'));
        $this->assertGreaterThanOrEqual(525600, (int) config('session.lifetime'));
        $this->assertGreaterThanOrEqual(5256000, (int) config('auth.remember_duration'));

        $session = file_get_contents(config_path('session.php'));
        $auth = file_get_contents(config_path('auth.php'));
        $provider = file_get_contents(app_path('Providers/AuthServiceProvider.php'));

        $this->assertStringContainsString("max((int) env('SESSION_LIFETIME', 525600), 525600)", $session);
        $this->assertStringContainsString("'expire_on_close' => false", $session);
        $this->assertStringContainsString("env('AUTH_REMEMBER_DURATION', 5256000)", $auth);
        $this->assertStringContainsString("setRememberDuration(\$minutes)", $provider);
        $this->assertStringContainsString("'admin', 'branch'", $provider);
    }

    public function test_customer_login_is_unchanged(): void
    {
        $customer = file_get_contents(app_path('Http/Controllers/Api/V1/Auth/CustomerAuthController.php'));
        $this->assertStringNotContainsString('setRememberDuration', $customer);
        $this->assertStringNotContainsString("auth('admin')", $customer);
        $this->assertStringNotContainsString("auth('branch')", $customer);

        $webLogin = file_get_contents(resource_path('views/auth/login.blade.php'));
        $this->assertStringContainsString('name="remember"', $webLogin);
    }

    public function test_explicit_logout_routes_remain(): void
    {
        $admin = file_get_contents(app_path('Http/Controllers/Admin/Auth/LoginController.php'));
        $branch = file_get_contents(app_path('Http/Controllers/Branch/Auth/LoginController.php'));

        $this->assertStringContainsString("auth()->guard('admin')->logout()", $admin);
        $this->assertStringContainsString("auth()->guard('branch')->logout()", $branch);
    }
}
