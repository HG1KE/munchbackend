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
}
