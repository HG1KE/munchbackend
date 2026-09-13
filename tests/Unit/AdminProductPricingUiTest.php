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
        $this->assertStringContainsString("munch-product-pricing.js') }}?v=2.2", $list);
        $this->assertStringContainsString("munch-product-pricing.css') }}?v=1.7", $list);
        $this->assertStringContainsString("cache: 'no-store'", $js);
        $this->assertStringContainsString('bulkProductSeq', $js);
        $this->assertStringContainsString('bulkPriceSeq', $js);
        $this->assertStringContainsString('invalidateBulkPrices', $js);
        $this->assertStringContainsString('refreshBulkPrices', $js);
        $this->assertStringContainsString('schedulePriceRefresh', $js);
        $this->assertStringContainsString('rowsForCurrentSelection', $js);
        $this->assertStringContainsString('dropUnselectedCurrentRows', $js);
        $this->assertStringContainsString('resetAfterBulkPriceApply', $js);
        $this->assertStringContainsString('validateBulkPriceApply', $js);
        $this->assertStringContainsString('setBulkApplyBusy', $js);
        $this->assertStringContainsString('bulkApplyShouldDisable', $js);
        $this->assertStringContainsString('closest(\'#bulk-apply\')', $js);
        $this->assertStringContainsString('No price changes to apply.', $js);
        $this->assertStringContainsString('Applying…', $js);
        $this->assertStringContainsString('errorMessage', $js);
        $this->assertStringNotContainsString('if (!bulk.preview || !bulk.preview.count) return;', $js);
        $this->assertStringContainsString('Changes applied successfully', $js);
        $this->assertStringContainsString('Set Exact Price', $js);
        $this->assertStringContainsString('Advanced adjustments', $js);
        $this->assertStringContainsString('Channels — select one or more', $js);
        $this->assertStringContainsString('Fill All', $js);
        $this->assertStringContainsString('data-bulk-product-value', $js);
        $this->assertStringContainsString('product_values', $js);
        $this->assertStringContainsString('renderProductEditors', $js);
        $this->assertStringContainsString('renderVariationEditors', $js);
        $this->assertStringContainsString('renderInlineVariationEditors', $js);
        $this->assertStringContainsString('data-bulk-product-variations', $js);
        $this->assertStringContainsString('renderProductListItem', $js);
        $this->assertStringContainsString('munch-pricing-variation-badge', $js);
        $this->assertStringContainsString('variation_values', $js);
        $this->assertStringContainsString('Variation level', $js);
        $this->assertStringContainsString('Current Selling Price', $js);
        $this->assertStringContainsString('New Price', $js);
        $this->assertStringContainsString("id=\"bulk-advanced\"", $js);
        $this->assertStringContainsString("money(row.current_price) + ' → ' + money(row.new_price)", $js);
        $this->assertStringNotContainsString('product.price', $js);
        $this->assertStringContainsString('munch-pricing-product-editor', $css);
        $this->assertStringContainsString('munch-pricing-product-editor.is-invalid', $css);
        $this->assertStringContainsString('munch-pricing-variation-table', $css);
        $this->assertStringContainsString('#bulk-product-list.munch-pricing-checklist', $css);
        $this->assertStringContainsString('munch-pricing-product-item.is-selected', $css);
        $this->assertStringContainsString("input('action', 'set_exact')", $controller);
        $this->assertStringContainsString('bulkProductValues', $controller);
        $this->assertStringContainsString('bulkVariationValues', $controller);
        $this->assertStringContainsString('variation_values', $controller);
        $this->assertStringContainsString('normalizeProductValues', $bulk);
        $this->assertStringContainsString('normalizeAddonValues', $bulk);
        $this->assertStringContainsString('addon_values', $controller);
        $this->assertStringContainsString('bulkAddonValues', $controller);
        $this->assertStringContainsString('Addon prices', $js);
        $this->assertStringContainsString('no-store, no-cache, must-revalidate', $controller);
        $this->assertStringContainsString('function currentPrices', $bulk);
        $this->assertStringContainsString('defaultSellingPrice', $bulk);
        $this->assertStringContainsString('effectiveSellingPrice', $bulk);
    }

    public function test_product_edit_exposes_variation_marketplace_prices(): void
    {
        $edit = file_get_contents(resource_path('views/admin-views/product/edit.blade.php'));
        $partial = file_get_contents(resource_path('views/admin-views/product/partials/_new_variations.blade.php'));
        $fields = file_get_contents(resource_path('views/admin-views/product/partials/_variation-marketplace-prices.blade.php'));

        $this->assertStringContainsString('_variation-marketplace-prices', $partial);
        $this->assertStringContainsString('Uber Price', $fields);
        $this->assertStringContainsString('Glovo Price', $fields);
        $this->assertStringContainsString('Bolt Food Price', $fields);
        $this->assertStringContainsString('channelPrices][uber]', $fields);
        $this->assertStringContainsString('channelPrices][bolt_food]', $fields);
        $this->assertStringContainsString('channelPrices][uber]', $edit);
        $this->assertStringContainsString('optionFromInput', file_get_contents(app_path('Http/Controllers/Admin/ProductController.php')));
        $this->assertStringContainsString('_addon-marketplace-prices', $edit);
        $this->assertStringContainsString('_addon-marketplace-prices', file_get_contents(resource_path('views/admin-views/product/index.blade.php')));
        $addonFields = file_get_contents(resource_path('views/admin-views/product/partials/_addon-marketplace-prices.blade.php'));
        $this->assertStringContainsString('addon_channel_prices', $addonFields);
        $this->assertStringContainsString('Uber Price', $addonFields);
        $this->assertStringContainsString('Glovo Price', $addonFields);
        $this->assertStringContainsString('Bolt Food Price', $addonFields);
        $this->assertStringContainsString('saveAddonChannelPrices', file_get_contents(app_path('Http/Controllers/Admin/ProductController.php')));
    }

    public function test_pos_catalog_and_checkout_use_channel_hierarchy(): void
    {
        $catalog = file_get_contents(app_path('Services/BranchPosCatalogService.php'));
        $this->assertStringContainsString('channel_prices', $catalog);
        $this->assertStringContainsString('channel_available', $catalog);
        $this->assertStringContainsString('pos-channel-pricing-1', $catalog);
        $this->assertStringContainsString('pos-variation-channel-1', $catalog);
        $this->assertStringContainsString('channelPricesFromOption', $catalog);
        $this->assertStringContainsString('anyChannelAvailable', $catalog);

        $pos = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $this->assertStringContainsString('resolveForSale', $pos);
        $this->assertStringContainsString('Product is not available for this channel', $pos);

        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $this->assertStringContainsString('function resolvedProductPrice', $js);
        $this->assertStringContainsString('function resolvedAddonPrice', $js);
        $this->assertStringContainsString('function productChannelAvailable', $js);
        $this->assertStringContainsString('channel_prices', $js);
        $this->assertStringContainsString('channel_available', $js);
        $this->assertStringContainsString('pos-addon-channel-1', $catalog);
        $this->assertStringContainsString('AddonChannelPricing', $catalog);
        $this->assertStringContainsString('AddonChannelPricing::unitPrices', $pos);
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

    public function test_bulk_price_edit_exposes_variation_fields_under_the_product(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-product-pricing.js'));
        $controller = file_get_contents(app_path('Http/Controllers/Admin/ProductPricingController.php'));

        $editors = $this->functionBody($js, 'function renderProductEditors');
        $this->assertStringContainsString('data-bulk-product-value', $editors);
        $this->assertStringNotContainsString('renderVariationEditors(p)', $editors);

        $listItem = $this->functionBody($js, 'function renderProductListItem');
        $this->assertStringContainsString('data-bulk-product-variations', $listItem);
        $this->assertStringContainsString('munch-pricing-variation-badge', $listItem);

        $inline = $this->functionBody($js, 'function renderInlineVariationEditors');
        $this->assertStringContainsString('renderVariationEditors(product)', $inline);
        $this->assertStringContainsString('renderAddonEditors(product)', $inline);

        $addonTable = $this->functionBody($js, 'function renderAddonEditors');
        $this->assertStringContainsString('Addon prices', $addonTable);
        $this->assertStringContainsString('data-bulk-addon-value', $addonTable);
        $this->assertStringContainsString('selectedMarketplaceChannels()', $addonTable);

        $variationTable = $this->functionBody($js, 'function renderVariationEditors');
        $this->assertStringContainsString('Uber', $js);
        $this->assertStringContainsString('Glovo', $js);
        $this->assertStringContainsString('Bolt Food', $js);
        $this->assertStringContainsString('data-bulk-variation-value', $variationTable);
        $this->assertStringContainsString('selectedMarketplaceChannels()', $variationTable);
        $this->assertStringContainsString('munch-pricing-variation-name', $variationTable);

        $this->assertStringContainsString('bulkSearchVariations', $controller);
        $this->assertStringContainsString("'variations' => \$this->bulkSearchVariations", $controller);

        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for Bulk Price Edit variation UI scenarios');
        }

        $script = base_path('tests/Js/bulk-variation-pricing.test.js');
        $output = [];
        $code = 0;
        exec(escapeshellcmd($node).' '.escapeshellarg($script).' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
        $combined = implode("\n", $output);
        $this->assertStringContainsString('variation rows render under the product', $combined);
        $this->assertStringContainsString('uber variation price field renders', $combined);
        $this->assertStringContainsString('glovo variation price field renders', $combined);
        $this->assertStringContainsString('bolt food variation price field renders', $combined);
        $this->assertStringContainsString('channel selection controls marketplace columns', $combined);
        $this->assertStringContainsString('products without variations keep the product editor', $combined);

        $addonScript = base_path('tests/Js/bulk-addon-pricing.test.js');
        $addonOutput = [];
        $addonCode = 0;
        exec(escapeshellcmd($node).' '.escapeshellarg($addonScript).' 2>&1', $addonOutput, $addonCode);
        $this->assertSame(0, $addonCode, implode("\n", $addonOutput));
        $addonCombined = implode("\n", $addonOutput);
        $this->assertStringContainsString('addon rows render in a separate ADDON PRICES section', $addonCombined);
        $this->assertStringContainsString('variations and addons stay in separate sections', $addonCombined);
        $this->assertStringContainsString('channel selection controls addon marketplace columns', $addonCombined);
        $this->assertStringContainsString('products without addons do not show an empty addon section', $addonCombined);
    }

    private function functionBody(string $source, string $needle, int $length = 2500): string
    {
        $start = strpos($source, $needle);
        $this->assertNotFalse($start, $needle.' not found');

        return substr($source, $start, $length);
    }
}
