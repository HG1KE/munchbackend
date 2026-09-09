<?php

namespace Tests\Unit;

use App\Support\PosOrderTypes;
use Tests\TestCase;

class BranchPosOrderTypesTest extends TestCase
{
    public function test_ui_types_include_aggregator_channels_without_renaming_take_away(): void
    {
        $this->assertSame([
            'delivery',
            'take_away',
            'dine_in',
            'glovo',
            'uber',
            'bolt_food',
        ], PosOrderTypes::uiTypes());
    }

    public function test_home_delivery_alias_normalizes_to_delivery(): void
    {
        $this->assertSame('delivery', PosOrderTypes::normalize('home_delivery'));
        $this->assertTrue(PosOrderTypes::isDelivery('home_delivery'));
        $this->assertTrue(PosOrderTypes::showsDeliveryCharge('delivery'));
        $this->assertFalse(PosOrderTypes::showsDeliveryCharge('take_away'));
        $this->assertFalse(PosOrderTypes::showsDeliveryCharge('glovo'));
        $this->assertFalse(PosOrderTypes::showsDeliveryCharge('uber'));
        $this->assertFalse(PosOrderTypes::showsDeliveryCharge('bolt_food'));
        $this->assertFalse(PosOrderTypes::showsDeliveryCharge('dine_in'));
    }

    public function test_marketplace_channels_persist_as_pos_so_online_orders_ignore_them(): void
    {
        $this->assertSame('pos', PosOrderTypes::databaseType('take_away'));
        $this->assertSame('pos', PosOrderTypes::databaseType('glovo'));
        $this->assertSame('pos', PosOrderTypes::databaseType('uber'));
        $this->assertSame('pos', PosOrderTypes::databaseType('bolt_food'));
        $this->assertSame('dine_in', PosOrderTypes::databaseType('dine_in'));
        $this->assertSame('delivery', PosOrderTypes::databaseType('delivery'));
        $this->assertSame('takeaway', PosOrderTypes::salesChannel('take_away'));
        $this->assertSame('delivery', PosOrderTypes::salesChannel('delivery'));
        $this->assertSame('dine_in', PosOrderTypes::salesChannel('dine_in'));
        $this->assertSame('glovo', PosOrderTypes::salesChannel('glovo'));
        $this->assertSame('uber', PosOrderTypes::salesChannel('uber'));
        $this->assertSame('bolt_food', PosOrderTypes::salesChannel('bolt_food'));
        $this->assertSame('takeaway', PosOrderTypes::salesChannel('unknown'));
        $this->assertSame([
            'pos', 'delivery', 'takeaway', 'dine_in', 'glovo', 'uber', 'bolt_food',
        ], PosOrderTypes::salesChannels());
        $this->assertFalse(method_exists(PosOrderTypes::class, 'orderNote'));
        $this->assertSame('Glovo', PosOrderTypes::channelLabel('glovo'));
        $this->assertSame('Take Away', PosOrderTypes::channelLabel('takeaway', 'pos'));
        $this->assertSame('POS', PosOrderTypes::channelLabel(null, 'pos'));
    }

    public function test_paid_and_status_defaults_match_existing_takeaway_and_dine_in_rules(): void
    {
        $this->assertSame('delivered', PosOrderTypes::defaultStatus('take_away'));
        $this->assertSame('delivered', PosOrderTypes::defaultStatus('glovo'));
        $this->assertSame('confirmed', PosOrderTypes::defaultStatus('dine_in'));
        $this->assertSame('confirmed', PosOrderTypes::defaultStatus('delivery'));
        $this->assertTrue(PosOrderTypes::isPaidImmediately('take_away', 'cash'));
        $this->assertTrue(PosOrderTypes::isPaidImmediately('dine_in', 'card'));
        $this->assertFalse(PosOrderTypes::isPaidImmediately('dine_in', 'pay_after_eating'));
        $this->assertFalse(PosOrderTypes::isPaidImmediately('delivery', 'cash_on_delivery'));
    }
}
