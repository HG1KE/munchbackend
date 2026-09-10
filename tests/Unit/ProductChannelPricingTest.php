<?php

namespace Tests\Unit;

use App\Services\ProductBulkPricingService;
use App\Services\ProductChannelPricingService;
use App\Services\ProductPricingAuditLogger;
use App\Support\ProductPricingChannels;
use Tests\TestCase;

class ProductChannelPricingTest extends TestCase
{
    private function pricing(): ProductChannelPricingService
    {
        return new ProductChannelPricingService(new ProductPricingAuditLogger());
    }

    public function test_pos_uses_override_otherwise_default(): void
    {
        $pricing = $this->pricing();

        $this->assertSame(1200.0, $pricing->resolvePrice('pos', 1200, null, null));
        $this->assertSame(1250.0, $pricing->resolvePrice('pos', 1200, 1250, null));
        $this->assertSame(1200.0, $pricing->resolvePrice('default', 1200, 1250, 9999));
    }

    public function test_marketplace_falls_back_to_pos_then_default(): void
    {
        $pricing = $this->pricing();

        $this->assertSame(1200.0, $pricing->resolvePrice('uber', 1200, null, null));
        $this->assertSame(1250.0, $pricing->resolvePrice('uber', 1200, 1250, null));
        $this->assertSame(1400.0, $pricing->resolvePrice('uber', 1200, 1250, 1400));
        $this->assertSame(1400.0, $pricing->resolvePrice('glovo', 1200, 1250, 1400));
        $this->assertSame(1380.0, $pricing->resolvePrice('bolt_food', 1200, 1250, 1380));
        $this->assertSame(1250.0, $pricing->resolvePrice('glovo', 1200, 1250, null));
        $this->assertSame(1200.0, $pricing->resolvePrice('bolt_food', 1200, null, null));
    }

    public function test_order_types_map_to_pricing_channels(): void
    {
        $this->assertSame('pos', ProductPricingChannels::fromOrderType('take_away'));
        $this->assertSame('pos', ProductPricingChannels::fromOrderType('dine_in'));
        $this->assertSame('pos', ProductPricingChannels::fromOrderType('delivery'));
        $this->assertSame('uber', ProductPricingChannels::fromOrderType('uber'));
        $this->assertSame('glovo', ProductPricingChannels::fromOrderType('glovo'));
        $this->assertSame('bolt_food', ProductPricingChannels::fromOrderType('bolt_food'));
    }

    public function test_matrix_inherits_marketplace_availability_from_pos_until_overridden(): void
    {
        $pricing = $this->pricing();
        $matrix = $pricing->resolveMatrix(1200, null, []);

        $this->assertFalse($matrix['available']['pos']);
        $this->assertFalse($matrix['available']['uber']);
        $this->assertSame(1200.0, $matrix['prices']['pos']);
        $this->assertSame(1200.0, $matrix['prices']['uber']);
        $this->assertFalse($pricing->anyChannelAvailable($matrix['available']));
    }

    public function test_bulk_price_actions_and_rounding(): void
    {
        $bulk = new ProductBulkPricingService($this->pricing());

        $this->assertSame(1100.0, $bulk->applyAction(1000, 'increase_percent', 10));
        $this->assertSame(900.0, $bulk->applyAction(1000, 'decrease_percent', 10));
        $this->assertSame(1050.0, $bulk->applyAction(1000, 'increase_amount', 50));
        $this->assertSame(950.0, $bulk->applyAction(1000, 'decrease_amount', 50));
        $this->assertSame(1300.0, $bulk->applyAction(1000, 'set_exact', 1300));
        $this->assertSame(1235.0, $bulk->applyAction(1233, 'round_5', 0));
        $this->assertSame(1230.0, $bulk->applyAction(1234, 'round_10', 0));
        $this->assertSame(0.0, $bulk->applyAction(10, 'decrease_amount', 50));
    }

    public function test_availability_actions_parse_to_channel_and_flag(): void
    {
        $bulk = new ProductBulkPricingService($this->pricing());

        $this->assertSame(['pos', true], $bulk->parseAvailabilityAction('enable_pos'));
        $this->assertSame(['uber', false], $bulk->parseAvailabilityAction('disable_uber'));
        $this->assertSame(['glovo', true], $bulk->parseAvailabilityAction('enable_glovo'));
        $this->assertSame(['bolt_food', false], $bulk->parseAvailabilityAction('disable_bolt_food'));
        $this->assertNull($bulk->parseAvailabilityAction('delete_everything'));
    }

    public function test_effective_selling_price_reuses_storefront_discount_helper(): void
    {
        $pricing = $this->pricing();
        $amount = ['discount_type' => 'amount', 'discount' => 150];
        $percent = ['discount_type' => 'percent', 'discount' => 20];

        $this->assertSame(790.0, $pricing->effectiveSellingPrice(940, $amount));
        $this->assertSame(752.0, $pricing->effectiveSellingPrice(940, $percent));
        $this->assertSame(940.0, $pricing->sellingToUnit(790, $amount));
        $this->assertSame(1019.0, $pricing->sellingToUnit(869, $amount));
        $this->assertSame(869.0, $pricing->effectiveSellingPrice(1019, $amount));
        $this->assertSame(790.0, $pricing->displayPrice('uber', 940, $amount));
        $this->assertSame(940.0, $pricing->displayPrice('pos', 940, $amount));
    }

    public function test_marketplace_previews_inherit_effective_selling_price(): void
    {
        $pricing = $this->pricing();
        $discount = ['discount_type' => 'amount', 'discount' => 150];
        $matrix = $pricing->resolveMatrix(940, null, [], $discount);
        $bulk = new ProductBulkPricingService($pricing);

        $this->assertSame(940.0, $matrix['prices']['pos']);
        $this->assertSame(940.0, $matrix['prices']['uber']);
        $this->assertSame(790.0, $matrix['display_prices']['uber']);
        $this->assertSame(790.0, $matrix['inherited_price']['uber']);
        $this->assertSame(869.0, $bulk->applyAction($matrix['display_prices']['uber'], 'increase_percent', 10));
        $this->assertSame(900.0, $bulk->applyAction($matrix['display_prices']['glovo'], 'set_exact', 900));
        $this->assertSame(840.0, $bulk->applyAction($matrix['display_prices']['bolt_food'], 'increase_amount', 50));
        $this->assertTrue(ProductPricingChannels::isMarketplace('uber'));
        $this->assertFalse(ProductPricingChannels::isMarketplace('pos'));
    }

    public function test_bulk_price_edit_starts_from_effective_selling_price(): void
    {
        $pricing = $this->pricing();
        $discount = ['discount_type' => 'amount', 'discount' => 100];
        $bulk = new ProductBulkPricingService($pricing);

        $this->assertSame(590.0, $pricing->effectiveSellingPrice(690, $discount));
        $this->assertSame(649.0, $bulk->applyAction(590, 'increase_percent', 10));
        $this->assertSame(690.0, $pricing->sellingToUnit(590, $discount));
        $this->assertSame(749.0, $pricing->sellingToUnit(649, $discount));
        $this->assertSame(640.0, $bulk->applyAction(590, 'increase_amount', 50));
        $this->assertSame(900.0, $bulk->applyAction(590, 'set_exact', 900));
        $this->assertSame(590.0, $bulk->applyAction(588, 'round_5', 0));
    }

    public function test_bulk_operations_normalize_multiple_channel_actions(): void
    {
        $bulk = new ProductBulkPricingService($this->pricing());
        $ops = $bulk->normalizePriceOperations([
            ['channel' => 'uber', 'action' => 'increase_percent', 'value' => 10],
            ['channel' => 'glovo', 'action' => 'set_exact', 'value' => 900],
            ['channel' => 'bolt_food', 'action' => 'increase_amount', 'value' => 50],
            ['channel' => 'invalid', 'action' => 'increase_percent', 'value' => 10],
            ['channel' => 'pos', 'action' => 'not_real', 'value' => 1],
        ]);

        $this->assertCount(3, $ops);
        $this->assertSame('uber', $ops[0]['channel']);
        $this->assertSame(10.0, $ops[0]['value']);
        $this->assertSame('set_exact', $ops[1]['action']);
        $this->assertSame(50.0, $ops[2]['value']);
        $this->assertSame(['uber', 'glovo', 'bolt_food'], ProductPricingChannels::filterOverrideChannels(['uber', 'glovo', 'bolt_food', 'default', 'uber']));
    }

    public function test_bulk_current_prices_read_effective_selling_space(): void
    {
        $src = file_get_contents(app_path('Services/ProductBulkPricingService.php'));
        $this->assertStringContainsString('function currentPrices', $src);
        $this->assertMatchesRegularExpression('/function currentPrices[\s\S]*defaultSellingPrice[\s\S]*resolvedPairsByChannels/', $src);
        $this->assertStringContainsString("'current_price' => \$pair['price']", $src);
        $this->assertStringContainsString('effectiveSellingPrice($unit, $payload)', $src);
        $this->assertStringContainsString("input('action', 'set_exact')", file_get_contents(app_path('Http/Controllers/Admin/ProductPricingController.php')));
    }

    public function test_bulk_set_exact_accepts_a_different_price_per_product(): void
    {
        $bulk = new ProductBulkPricingService($this->pricing());
        $values = $bulk->normalizeProductValues([
            '12' => '900',
            15 => 650,
            ['product_id' => 20, 'value' => 780],
            9 => 0,
        ]);

        $this->assertSame(900.0, $values[12]);
        $this->assertSame(650.0, $values[15]);
        $this->assertSame(780.0, $values[20]);
        $this->assertArrayNotHasKey(9, $values);
        $this->assertSame(900.0, $bulk->applyAction(790, 'set_exact', $values[12]));
        $this->assertSame(650.0, $bulk->applyAction(590, 'set_exact', $values[15]));
    }
}
