<?php

namespace Tests\Unit;

use App\Support\ProductVariationPricing;
use Tests\TestCase;

class ProductVariationPricingTest extends TestCase
{
    public function test_option_keys_round_trip(): void
    {
        $key = ProductVariationPricing::optionKey('Size', 'Regular');

        $this->assertSame(['group' => 'Size', 'label' => 'Regular'], ProductVariationPricing::parseOptionKey($key));
        $this->assertNull(ProductVariationPricing::parseOptionKey('not-a-key'));
    }

    public function test_flat_options_read_channel_prices_without_touching_master(): void
    {
        $options = ProductVariationPricing::flatOptions([
            [
                'name' => 'Size',
                'values' => [
                    [
                        'label' => 'Regular',
                        'optionPrice' => 200,
                        'channelPrices' => ['uber' => 250, 'glovo' => 260, 'bolt_food' => 270],
                    ],
                    ['label' => 'Large', 'optionPrice' => 300],
                ],
            ],
        ]);

        $this->assertCount(2, $options);
        $this->assertSame(200.0, $options[0]['option_price']);
        $this->assertSame(['uber' => 250.0, 'glovo' => 260.0, 'bolt_food' => 270.0], $options[0]['channel_prices']);
        $this->assertSame([], $options[1]['channel_prices']);
    }

    public function test_editing_one_channel_leaves_the_others_intact(): void
    {
        $variations = [[
            'name' => 'Size',
            'values' => [[
                'label' => 'Regular',
                'optionPrice' => 200,
                'channelPrices' => ['uber' => 250, 'glovo' => 260, 'bolt_food' => 270],
            ], [
                'label' => 'Large',
                'optionPrice' => 300,
                'channelPrices' => ['uber' => 350, 'glovo' => 360, 'bolt_food' => 370],
            ]],
        ]];

        $next = ProductVariationPricing::setOptionChannelPrice(
            $variations,
            ProductVariationPricing::optionKey('Size', 'Regular'),
            'uber',
            275
        );

        $regular = ProductVariationPricing::flatOptions($next)[0];
        $large = ProductVariationPricing::flatOptions($next)[1];
        $this->assertSame(200.0, $regular['option_price']);
        $this->assertSame(275.0, $regular['channel_prices']['uber']);
        $this->assertSame(260.0, $regular['channel_prices']['glovo']);
        $this->assertSame(270.0, $regular['channel_prices']['bolt_food']);
        $this->assertSame(350.0, $large['channel_prices']['uber']);
        $this->assertSame(300.0, $large['option_price']);
    }

    public function test_submitted_prices_reject_invalid_and_ignore_blank(): void
    {
        $this->assertSame([null, null], ProductVariationPricing::parseSubmittedPrice(''));
        $this->assertSame([null, 'Enter a valid variation price'], ProductVariationPricing::parseSubmittedPrice('abc'));
        $this->assertSame([null, 'Variation prices cannot be negative'], ProductVariationPricing::parseSubmittedPrice(-5));
        $this->assertSame([null, 'Enter a valid variation price'], ProductVariationPricing::parseSubmittedPrice(0));
        $this->assertSame([275.0, null], ProductVariationPricing::parseSubmittedPrice('275'));
    }

    public function test_effective_price_uses_override_or_product_plus_option(): void
    {
        $this->assertSame(275.0, ProductVariationPricing::effectivePrice(200, 0, 275));
        $this->assertSame(250.0, ProductVariationPricing::effectivePrice(200, 50, null));
        $this->assertSame(75.0, ProductVariationPricing::extraForChannel(200, 0, 275));
        $this->assertSame(50.0, ProductVariationPricing::extraForChannel(200, 50, null));
    }
}
