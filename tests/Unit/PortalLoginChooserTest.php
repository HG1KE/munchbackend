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
    }

    public function test_login_pages_hide_credentials_until_mode_chosen(): void
    {
        $admin = file_get_contents(resource_path('views/admin-views/auth/login.blade.php'));
        $branch = file_get_contents(resource_path('views/branch-views/auth/login.blade.php'));
        $stocks = file_get_contents(resource_path('views/stocks-views/auth/login.blade.php'));
        $chooser = file_get_contents(resource_path('views/admin-views/auth/partials/_munch-login-chooser.blade.php'));

        foreach ([$admin, $branch, $stocks] as $view) {
            $this->assertStringContainsString("_munch-login-chooser", $view);
            $this->assertStringContainsString("request()->boolean('form')", $view);
            $this->assertStringContainsString("translate('Change login type')", $view);
        }

        $this->assertStringContainsString("translate('Admin Login')", $chooser);
        $this->assertStringContainsString("translate('Branch Login')", $chooser);
        $this->assertStringContainsString("translate('Stocks Login')", $chooser);
        $this->assertStringContainsString("route('admin.auth.login', ['form' => 1])", $chooser);
        $this->assertStringContainsString("route('branch.auth.login', ['form' => 1])", $chooser);
        $this->assertStringContainsString("route('stocks.auth.login', ['form' => 1])", $chooser);

        $this->assertStringContainsString("translate('stocks_manager_sign_in')", $stocks);
        $this->assertStringContainsString('name="email"', $admin);
        $this->assertStringContainsString('name="password"', $admin);
    }
}
