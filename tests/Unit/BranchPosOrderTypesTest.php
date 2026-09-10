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
        $this->assertSame('pos', PosOrderTypes::databaseType('delivery'));
        $this->assertTrue(PosOrderTypes::isPosFamily('pos', 'delivery'));
        $this->assertTrue(PosOrderTypes::isPosDeliveryOrder('pos', 'delivery'));
        $this->assertTrue(PosOrderTypes::isPosDeliveryOrder('delivery', 'delivery'));
        $this->assertFalse(PosOrderTypes::isPosDeliveryOrder('delivery', null));
        $this->assertFalse(PosOrderTypes::isOnlineOrder('pos', 'delivery'));
        $this->assertTrue(PosOrderTypes::isOnlineOrder('delivery', null));
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
        $this->assertTrue(PosOrderTypes::isPaidImmediately('take_away', 'mpesa'));
        $this->assertTrue(PosOrderTypes::isPaidImmediately('dine_in', 'card'));
        $this->assertTrue(PosOrderTypes::isPaidImmediately('dine_in', 'mpesa'));
        $this->assertFalse(PosOrderTypes::isPaidImmediately('dine_in', 'pay_after_eating'));
        $this->assertFalse(PosOrderTypes::isPaidImmediately('delivery', 'cash_on_delivery'));
        $this->assertSame(['cash', 'card', 'mpesa'], PosOrderTypes::paymentMethods('take_away'));
        $this->assertSame(['cash', 'card', 'mpesa'], PosOrderTypes::paymentMethods('dine_in'));
        $this->assertSame(['cash', 'card'], PosOrderTypes::paymentMethods('take_away', false));
        $this->assertSame(['cash', 'card'], PosOrderTypes::paymentMethods('dine_in', false));
        $this->assertSame(['cash_on_delivery'], PosOrderTypes::paymentMethods('delivery'));
        $this->assertSame(['cash_on_delivery'], PosOrderTypes::paymentMethods('delivery', false));
        $this->assertSame(['glovo'], PosOrderTypes::paymentMethods('glovo'));
        $this->assertSame(['uber'], PosOrderTypes::paymentMethods('uber'));
        $this->assertSame(['bolt_food'], PosOrderTypes::paymentMethods('bolt_food'));
        $this->assertSame(['glovo'], PosOrderTypes::paymentMethods('glovo', false));
        $this->assertSame('glovo', PosOrderTypes::resolvedPaymentMethod('glovo', 'cash'));
        $this->assertSame('uber', PosOrderTypes::resolvedPaymentMethod('uber', 'card'));
        $this->assertSame('bolt_food', PosOrderTypes::resolvedPaymentMethod('bolt_food', 'mpesa'));
        $this->assertSame('cash', PosOrderTypes::resolvedPaymentMethod('take_away', 'cash'));
        $this->assertTrue(PosOrderTypes::isMarketplacePayment('glovo'));
        $this->assertFalse(PosOrderTypes::isMarketplacePayment('cash'));
        $this->assertSame('Glovo', PosOrderTypes::paymentReceiptLabel('glovo'));
        $this->assertSame('Uber', PosOrderTypes::paymentReceiptLabel('uber'));
        $this->assertSame('Bolt Food', PosOrderTypes::paymentReceiptLabel('bolt_food'));
    }

    public function test_rider_fields_are_required_only_for_delivery(): void
    {
        $complete = [
            'customer_name' => 'Jane',
            'customer_phone' => '0712345678',
            'address' => 'Ngong Road',
            'rider_name' => 'John Rider',
            'rider_phone' => '0799999999',
        ];
        $missingRider = $complete;
        $missingRider['rider_name'] = '';
        $missingRider['rider_phone'] = '';
        $missingPhone = $complete;
        $missingPhone['rider_phone'] = '  ';

        $this->assertNull(PosOrderTypes::posDeliveryFieldError('delivery', $complete));
        $this->assertNull(PosOrderTypes::posDeliveryFieldError('delivery', $missingRider));
        $this->assertSame('Rider Phone', PosOrderTypes::posDeliveryFieldError('delivery', $missingPhone));
        $this->assertSame('Customer Name', PosOrderTypes::posDeliveryFieldError('delivery', array_merge($complete, ['customer_name' => ''])));
        $this->assertSame('Invalid phone number', PosOrderTypes::posDeliveryFieldError('delivery', array_merge($complete, ['customer_phone' => '12'])));

        foreach (['take_away', 'dine_in', 'glovo', 'uber', 'bolt_food'] as $type) {
            $this->assertNull(PosOrderTypes::posDeliveryFieldError($type, $missingRider), $type);
            $this->assertNull(PosOrderTypes::posDeliveryFieldError($type, []), $type);
        }
    }

    public function test_manual_discount_is_only_allowed_for_delivery_takeaway_and_dine_in(): void
    {
        $this->assertTrue(PosOrderTypes::allowsManualDiscount('delivery'));
        $this->assertTrue(PosOrderTypes::allowsManualDiscount('take_away'));
        $this->assertTrue(PosOrderTypes::allowsManualDiscount('dine_in'));
        $this->assertFalse(PosOrderTypes::allowsManualDiscount('glovo'));
        $this->assertFalse(PosOrderTypes::allowsManualDiscount('uber'));
        $this->assertFalse(PosOrderTypes::allowsManualDiscount('bolt_food'));
    }

    public function test_pos_cancellation_is_limited_to_owned_channels(): void
    {
        $this->assertTrue(PosOrderTypes::allowsPosCancellation('dine_in'));
        $this->assertTrue(PosOrderTypes::allowsPosCancellation('takeaway'));
        $this->assertTrue(PosOrderTypes::allowsPosCancellation('delivery'));
        $this->assertTrue(PosOrderTypes::allowsPosCancellation('pos'));
        $this->assertFalse(PosOrderTypes::allowsPosCancellation('glovo'));
        $this->assertFalse(PosOrderTypes::allowsPosCancellation('uber'));
        $this->assertFalse(PosOrderTypes::allowsPosCancellation('bolt_food'));
    }

    public function test_dine_in_json_payload_requires_table_and_hydrates_ids(): void
    {
        $valid = ['order_type' => 'dine_in', 'table_id' => '12', 'people_number' => '3'];
        $this->assertSame(12, PosOrderTypes::jsonDineInTableId($valid));
        $this->assertSame(3, PosOrderTypes::jsonDineInPeople($valid));
        $this->assertNull(PosOrderTypes::jsonDineInError($valid));
        $this->assertSame('please select a table number', PosOrderTypes::jsonDineInError([
            'order_type' => 'dine_in',
            'table_id' => '',
            'people_number' => 2,
        ]));
        $this->assertSame('please enter people number', PosOrderTypes::jsonDineInError([
            'order_type' => 'dine_in',
            'table_id' => 12,
            'people_number' => 0,
        ]));
        $this->assertNull(PosOrderTypes::jsonDineInError(['order_type' => 'take_away']));
        $this->assertNull(PosOrderTypes::jsonDineInTableId(['order_type' => 'delivery', 'table_id' => 12]));
        $this->assertNull(PosOrderTypes::jsonDineInTableId(['order_type' => 'glovo', 'table_id' => 12]));
        $this->assertNull(PosOrderTypes::jsonDineInPeople(['order_type' => 'uber', 'people_number' => 4]));
    }
}
