<?php

namespace Tests\Unit;

use Tests\TestCase;

class AdminProductPricingUiTest extends TestCase
{
    public function test_product_list_keeps_columns_and_adds_pricing_action(): void
    {
        $list = file_get_contents(resource_path('views/admin-views/product/list.blade.php'));
        $this->assertStringContainsString('translate(\'selling_price\')', $list);
        $this->assertStringContainsString('data-pricing=', $list);
        $this->assertStringContainsString("translate('Pricing')", $list);
        $this->assertStringContainsString('data-bulk-pricing', $list);
        $this->assertStringContainsString("translate('Bulk Price Edit')", $list);
        $this->assertStringNotContainsString('Uber Price', $list);
        $this->assertStringNotContainsString('Glovo Price', $list);
        $this->assertStringContainsString('munch-pricing-drawer', $list);
        $this->assertStringContainsString('munch-product-pricing.js', $list);
        $this->assertStringContainsString('Search branches', $list);
        $this->assertStringContainsString('Search products', $list);
    }

    public function test_pricing_routes_are_lazy_admin_endpoints(): void
    {
        $routes = file_get_contents(base_path('routes/admin.php'));
        $this->assertStringContainsString("pricing/{id}", $routes);
        $this->assertStringContainsString('bulk-price/preview', $routes);
        $this->assertStringContainsString('bulk-availability/preview', $routes);
        $this->assertStringContainsString('ProductPricingController', $routes);
    }

    public function test_drawer_saves_only_changed_rows_and_previews_bulk_edits(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-product-pricing.js'));
        $this->assertStringContainsString('collectChanges()', $js);
        $this->assertStringContainsString('Loading pricing', $js);
        $this->assertStringContainsString('Preview Changes', $js);
        $this->assertStringContainsString('confirmed: true', $js);
        $this->assertStringContainsString('Reset to Default', $js);
        $this->assertStringContainsString('Current Price', $js);
        $this->assertStringContainsString('New Price', $js);
        $this->assertStringContainsString('Difference', $js);
        $controller = file_get_contents(app_path('Http/Controllers/Admin/ProductPricingController.php'));
        $this->assertStringContainsString('boolean(\'confirmed\')', $controller);
    }

    public function test_pos_catalog_and_checkout_use_channel_hierarchy(): void
    {
        $catalog = file_get_contents(app_path('Services/BranchPosCatalogService.php'));
        $this->assertStringContainsString('channel_prices', $catalog);
        $this->assertStringContainsString('channel_available', $catalog);
        $this->assertStringContainsString('pos-channel-pricing-1', $catalog);
        $this->assertStringContainsString('anyChannelAvailable', $catalog);

        $pos = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $this->assertStringContainsString('resolveForSale', $pos);
        $this->assertStringContainsString('Product is not available for this channel', $pos);

        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $this->assertStringContainsString('function resolvedProductPrice', $js);
        $this->assertStringContainsString('function productChannelAvailable', $js);
        $this->assertStringContainsString('channel_prices', $js);
        $this->assertStringContainsString('channel_available', $js);
    }

    public function test_audit_log_captures_who_when_old_new_branch_channel_product(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_10_120100_create_product_price_audit_logs_table.php'));
        $this->assertStringContainsString('admin_id', $migration);
        $this->assertStringContainsString('product_id', $migration);
        $this->assertStringContainsString('branch_id', $migration);
        $this->assertStringContainsString('channel', $migration);
        $this->assertStringContainsString('old_value', $migration);
        $this->assertStringContainsString('new_value', $migration);
        $this->assertStringContainsString('created_at', $migration);

        $logger = file_get_contents(app_path('Services/ProductPricingAuditLogger.php'));
        $this->assertStringContainsString('actor_type', $logger);
        $this->assertStringContainsString('ip_address', $logger);
    }
}
