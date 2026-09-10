<?php

namespace Tests\Unit;

use Tests\TestCase;

class PosVariationSelectionTest extends TestCase
{
    public function test_variation_products_always_open_the_selector_on_add(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $adjust = $this->functionBody($js, 'function adjustProductQty');
        $addPath = substr($adjust, strpos($adjust, 'if (delta > 0)'), strpos($adjust, 'var idx = lastLineIndex') - strpos($adjust, 'if (delta > 0)'));

        $this->assertStringContainsString('if (productNeedsVariation(product))', $addPath);
        $this->assertStringContainsString('openModifiers(product)', $addPath);
        $this->assertStringNotContainsString('quantity +=', $addPath);
        $this->assertStringNotContainsString('lastLineIndex', $addPath);
        $this->assertStringContainsString('addSelectedVariations(product, variations, qty)', $js);
        $this->assertStringContainsString('function variationSelectionKey', $js);
        $this->assertStringContainsString('function findMatchingVariationLine', $js);
    }

    public function test_selector_does_not_reuse_a_previous_flavour(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $modal = $this->functionBody($js, 'function openModifiers', 4500);

        $this->assertDoesNotMatchRegularExpression('/\schecked|checked=/', $modal);
        $this->assertStringContainsString(':checked', $modal);
        $this->assertStringContainsString('addSelectedVariations(product, variations, qty)', $modal);
        $this->assertStringNotContainsString('state.cart.lines.push', $modal);
    }

    public function test_checkout_pricing_discounts_and_kitchen_print_are_unchanged(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $page = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));

        $this->assertStringContainsString('function placeOrder', $js);
        $this->assertStringContainsString('paid_amount: grandTotal()', $js);
        $this->assertStringContainsString('extra_discount: allowsDiscount() ? Number(state.cart.discount || 0) : 0', $js);
        $this->assertStringContainsString('function extraDiscount', $js);
        $this->assertStringContainsString('function lineUnit', $js);
        $this->assertStringContainsString('function variationPrice', $js);
        $this->assertStringContainsString('function kitchenTicketHtml', $js);
        $this->assertStringContainsString('function printOneTicket', $js);
        $this->assertStringContainsString('function enqueue', $js);
        $this->assertStringContainsString('pos-place', $page);
        $this->assertStringContainsString('pos-success-kitchen', $page);
    }

    public function test_variation_cart_scenarios(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for POS variation cart scenarios');
        }

        $script = base_path('tests/Js/pos-variation-cart.test.js');
        $output = [];
        $code = 0;
        exec(escapeshellcmd($node).' '.escapeshellarg($script).' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $combined = implode("\n", $output);
        $this->assertStringContainsString('product with variations always opens selector', $combined);
        $this->assertStringContainsString('previous variation is never auto-selected', $combined);
        $this->assertStringContainsString('BBQ then Sweet Chilli', $combined);
        $this->assertStringContainsString('BBQ selected twice', $combined);
        $this->assertStringContainsString('Sweet Chilli selected twice', $combined);
        $this->assertStringContainsString('products without variations still add instantly', $combined);
    }

    private function functionBody(string $source, string $needle, int $length = 1800): string
    {
        $start = strpos($source, $needle);
        $this->assertNotFalse($start, $needle.' not found');

        return substr($source, $start, $length);
    }
}
