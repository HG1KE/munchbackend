<?php

namespace Tests\Unit;

use Tests\TestCase;

class PortalLoginChooserTest extends TestCase
{
    public function test_stocks_manager_sign_in_has_translation(): void
    {
        $en = include resource_path('lang/en/messages.php');

        $this->assertArrayHasKey('stocks_manager_sign_in', $en);
        $this->assertSame('Stocks manager sign in', $en['stocks_manager_sign_in']);
        $this->assertArrayHasKey('Admin Login', $en);
        $this->assertArrayHasKey('Branch Login', $en);
        $this->assertArrayHasKey('Stocks Login', $en);
        $this->assertArrayHasKey('Choose your login', $en);
        $this->assertArrayHasKey('Change login type', $en);
    }

    public function test_login_pages_use_seamless_js_mode_chooser(): void
    {
        $admin = file_get_contents(resource_path('views/admin-views/auth/login.blade.php'));
        $branch = file_get_contents(resource_path('views/branch-views/auth/login.blade.php'));
        $stocks = file_get_contents(resource_path('views/stocks-views/auth/login.blade.php'));
        $chooser = file_get_contents(resource_path('views/admin-views/auth/partials/_munch-login-chooser.blade.php'));
        $panel = file_get_contents(resource_path('views/admin-views/auth/partials/_munch-login-panel.blade.php'));
        $script = file_get_contents(resource_path('views/admin-views/auth/partials/_munch-login-mode-script.blade.php'));
        $styles = file_get_contents(resource_path('views/admin-views/auth/partials/_munch-login-styles.blade.php'));

        foreach ([$admin, $branch, $stocks] as $view) {
            $this->assertStringContainsString("_munch-login-panel", $view);
            $this->assertStringContainsString("_munch-login-head-assets", $view);
            $this->assertStringContainsString("_munch-login-scripts", $view);
            $this->assertStringNotContainsString('vendor.min.js', $view);
            $this->assertStringNotContainsString('theme.minc619.css', $view);
            $this->assertStringNotContainsString("@endphp", $view);
            $this->assertStringNotContainsString('google.com/recaptcha', $view);
            $this->assertStringNotContainsString('g-recaptcha', $view);
        }

        $scripts = file_get_contents(resource_path('views/admin-views/auth/partials/_munch-login-scripts.blade.php'));
        $this->assertStringContainsString("_munch-login-mode-script", $scripts);
        $this->assertStringContainsString('jquery.min.js', $scripts);
        $this->assertStringNotContainsString('/js/vendor.min.js', $scripts);

        $head = file_get_contents(resource_path('views/admin-views/auth/partials/_munch-login-head-assets.blade.php'));
        $this->assertStringContainsString('get_business_settings', $head);
        $this->assertStringNotContainsString('BusinessSetting::where', $head);
        $this->assertStringNotContainsString('fonts.googleapis.com', $head);

        $this->assertStringContainsString('data-munch-login-mode="admin"', $chooser);
        $this->assertStringContainsString('data-munch-login-action=', $chooser);
        $this->assertStringContainsString('type="button"', $chooser);

        $this->assertStringContainsString('name="login_panel"', $panel);
        $this->assertStringContainsString('name="email"', $panel);
        $this->assertStringContainsString('name="password"', $panel);
        $this->assertStringNotContainsString('default_captcha_value', $panel);
        $this->assertStringNotContainsString('g-recaptcha-response', $panel);
        $this->assertStringNotContainsString("@endphp", $panel);

        $this->assertStringContainsString('window.MUNCH_LOGIN_MODES', $script);
        $this->assertStringContainsString("route('admin.auth.login')", $script);
        $this->assertStringContainsString("route('branch.auth.login')", $script);
        $this->assertStringContainsString("route('stocks.auth.login')", $script);
        $this->assertStringContainsString("closest('[data-munch-login-mode]')", $script);
        $this->assertStringNotContainsString('JSON.parse', $script);

        $this->assertStringContainsString('munch-auth-chooser__btn--admin', $styles);
        $this->assertStringContainsString('munch-auth-chooser__btn--branch', $styles);
        $this->assertStringContainsString('munch-auth-chooser__btn--stocks', $styles);
    }

    public function test_login_controllers_skip_captcha_and_preserve_login_panel(): void
    {
        $admin = file_get_contents(app_path('Http/Controllers/Admin/Auth/LoginController.php'));
        $branch = file_get_contents(app_path('Http/Controllers/Branch/Auth/LoginController.php'));
        $stocks = file_get_contents(app_path('Http/Controllers/Stocks/Auth/LoginController.php'));

        foreach ([$admin, $branch, $stocks] as $controller) {
            $this->assertStringContainsString("withInput(\$request->only('email', 'login_panel'))", $controller);
            $this->assertStringNotContainsString('Captcha Failed', $controller);
            $this->assertStringNotContainsString('g-recaptcha-response', $controller);
            $this->assertStringNotContainsString('Recaptcha Failed', $controller);
        }
    }
}
