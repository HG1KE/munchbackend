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
        $this->assertStringContainsString("translate('Copy From Branch')", $list);
        $this->assertStringContainsString('munch-pricing-copy-modal', $list);
        $this->assertStringContainsString("translate('Default Selling Price')", $list);
        $this->assertStringNotContainsString("translate('Default Price')", $list);
    }

    public function test_pricing_routes_are_lazy_admin_endpoints(): void
    {
        $routes = file_get_contents(base_path('routes/admin.php'));
        $this->assertStringContainsString("pricing/{id}", $routes);
        $this->assertStringContainsString('bulk-price/preview', $routes);
        $this->assertStringContainsString('bulk-price/current', $routes);
        $this->assertStringContainsString('bulk-availability/preview', $routes);
        $this->assertStringContainsString('pricing/copy/preview', $routes);
        $this->assertStringContainsString('pricing/copy/apply', $routes);
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
        $this->assertStringContainsString('default_selling_price', $js);
        $this->assertStringContainsString('inherited_price', $js);
        $this->assertStringContainsString('default selling price', $js);
        $this->assertStringContainsString('munch-pricing-preview-group', $js);
        $this->assertStringContainsString('data-bulk-channel-map', $js);
        $this->assertStringContainsString('bulk-advanced', $js);
        $this->assertStringContainsString('operations', $js);
        $this->assertStringContainsString('copy-marketplace', $js);
        $this->assertStringContainsString('selling_price', $js);
        $this->assertStringContainsString('product.selling_price', $js);
        $controller = file_get_contents(app_path('Http/Controllers/Admin/ProductPricingController.php'));
        $this->assertStringContainsString('boolean(\'confirmed\')', $controller);
        $js = file_get_contents(public_path('assets/admin/js/munch-product-pricing.js'));
        $this->assertStringContainsString('Copy From Branch', file_get_contents(resource_path('views/admin-views/product/list.blade.php')));
        $this->assertStringContainsString('runCopyPreview', $js);
        $this->assertStringContainsString('Overwrite everything', $js);
        $this->assertStringContainsString('Only fill missing overrides', $js);
        $this->assertStringContainsString('Skip existing overrides', $js);
        $this->assertStringContainsString('copy_branch_pricing', file_get_contents(app_path('Services/ProductBranchPricingCopyService.php')));
        $this->assertStringContainsString('saveDrawer($product, null, $changes, \'drawer\')', $controller);
        $this->assertStringContainsString('effectiveSellingPrice', file_get_contents(app_path('Services/ProductChannelPricingService.php')));
        $this->assertStringContainsString('sellingToUnit', file_get_contents(app_path('Services/ProductChannelPricingService.php')));
        $this->assertStringContainsString('effectiveSellingPrice', file_get_contents(app_path('Services/ProductBulkPricingService.php')));
        $this->assertStringContainsString('normalizePriceOperations', file_get_contents(app_path('Services/ProductBulkPricingService.php')));
        $this->assertStringContainsString('selling_price', $controller);
        $this->assertStringContainsString('filterOverrideChannels', file_get_contents(app_path('Support/ProductPricingChannels.php')));
    }

    public function test_bulk_price_edit_reloads_selling_prices_and_defaults_to_set_exact(): void
    {
        $list = file_get_contents(resource_path('views/admin-views/product/list.blade.php'));
        $js = file_get_contents(public_path('assets/admin/js/munch-product-pricing.js'));
        $css = file_get_contents(public_path('assets/admin/css/munch-product-pricing.css'));
        $controller = file_get_contents(app_path('Http/Controllers/Admin/ProductPricingController.php'));
        $bulk = file_get_contents(app_path('Services/ProductBulkPricingService.php'));

        $this->assertStringContainsString('data-current-price-url', $list);
        $this->assertStringContainsString("munch-product-pricing.js') }}?v=1.8", $list);
        $this->assertStringContainsString("munch-product-pricing.css') }}?v=1.3", $list);
        $this->assertStringContainsString("cache: 'no-store'", $js);
        $this->assertStringContainsString('bulkProductSeq', $js);
        $this->assertStringContainsString('bulkPriceSeq', $js);
        $this->assertStringContainsString('invalidateBulkPrices', $js);
        $this->assertStringContainsString('refreshBulkPrices', $js);
        $this->assertStringContainsString('schedulePriceRefresh', $js);
        $this->assertStringContainsString('rowsForCurrentSelection', $js);
        $this->assertStringContainsString('dropUnselectedCurrentRows', $js);
        $this->assertStringContainsString('resetAfterBulkPriceApply', $js);
        $this->assertStringContainsString('Changes applied successfully', $js);
        $this->assertStringContainsString('Set Exact Price', $js);
        $this->assertStringContainsString('Advanced adjustments', $js);
        $this->assertStringContainsString('Channels — select one or more', $js);
        $this->assertStringContainsString('Fill All', $js);
        $this->assertStringContainsString('data-bulk-product-value', $js);
        $this->assertStringContainsString('product_values', $js);
        $this->assertStringContainsString('renderProductEditors', $js);
        $this->assertStringContainsString('Current Selling Price', $js);
        $this->assertStringContainsString('New Price', $js);
        $this->assertStringContainsString("id=\"bulk-advanced\"", $js);
        $this->assertStringContainsString("money(row.current_price) + ' → ' + money(row.new_price)", $js);
        $this->assertStringNotContainsString('product.price', $js);
        $this->assertStringContainsString('munch-pricing-product-editor', $css);
        $this->assertStringContainsString("input('action', 'set_exact')", $controller);
        $this->assertStringContainsString('bulkProductValues', $controller);
        $this->assertStringContainsString('normalizeProductValues', $bulk);
        $this->assertStringContainsString('no-store, no-cache, must-revalidate', $controller);
        $this->assertStringContainsString('function currentPrices', $bulk);
        $this->assertStringContainsString('defaultSellingPrice', $bulk);
        $this->assertStringContainsString('effectiveSellingPrice', $bulk);
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
        $this->assertStringContainsString('source_branch_id', file_get_contents(database_path('migrations/2026_09_10_140000_add_source_branch_id_to_product_price_audit_logs.php')));
        $this->assertStringContainsString('source_branch_id', file_get_contents(app_path('Services/ProductPricingAuditLogger.php')));

        $logger = file_get_contents(app_path('Services/ProductPricingAuditLogger.php'));
        $this->assertStringContainsString('actor_type', $logger);
        $this->assertStringContainsString('ip_address', $logger);
    }
}
