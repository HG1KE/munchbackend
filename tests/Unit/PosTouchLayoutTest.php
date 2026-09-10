<?php

namespace Tests\Unit;

use Tests\TestCase;

class PosTouchLayoutTest extends TestCase
{
    public function test_fifteen_inch_screens_force_three_product_columns(): void
    {
        $css = file_get_contents(public_path('assets/admin/css/munch-pos.css'));

        $this->assertStringContainsString('@media (min-width: 900px) and (max-width: 1440px)', $css);
        $this->assertStringContainsString('grid-template-columns: repeat(3, minmax(160px, 1fr))', $css);
        $this->assertStringContainsString('@media (min-width: 1441px)', $css);
        $this->assertStringContainsString('repeat(auto-fill, minmax(220px, 1fr))', $css);
        $this->assertStringContainsString('minmax(300px, 340px)', $css);
        $this->assertStringContainsString('-webkit-line-clamp: 2', $css);
        $this->assertStringContainsString('overflow-x: hidden', $css);
        $this->assertStringNotContainsString('grid-template-columns: repeat(3, minmax(0, 1fr))', $css);
    }

    public function test_cart_lines_scroll_while_totals_and_place_order_stay_pinned(): void
    {
        $css = file_get_contents(public_path('assets/admin/css/munch-pos.css'));
        $page = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));

        $types = strpos($page, 'id="pos-types"');
        $scroll = strpos($page, 'munch-pos-cart__scroll');
        $lines = strpos($page, 'id="pos-lines"');
        $footer = strpos($page, 'munch-pos-footer');
        $place = strpos($page, 'id="pos-place"');

        $this->assertNotFalse($types);
        $this->assertNotFalse($scroll);
        $this->assertLessThan($scroll, $types);
        $this->assertGreaterThan($scroll, $lines);
        $this->assertGreaterThan($lines, $footer);
        $this->assertGreaterThan($footer, $place);

        $this->assertStringContainsString('.munch-pos-cart__scroll', $css);
        $this->assertStringContainsString('overflow-y: auto', $css);
        $this->assertStringContainsString('.munch-pos-footer', $css);
        $this->assertStringContainsString('flex: 0 0 auto', $css);
        $this->assertStringContainsString('min-height: 52px', $css);
        $this->assertStringContainsString('min-height: 48px', $css);
    }

    public function test_catalog_renders_the_full_list_without_virtualization(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));

        $this->assertStringContainsString('function catalogGridKey', $js);
        $this->assertStringContainsString('function renderGrid', $js);
        $this->assertStringContainsString('list.map(productCard)', $js);
        $this->assertStringContainsString('lastGridKey = \'\'', $js);
        $this->assertStringContainsString('function measuredGridColumns', $js);
        $this->assertStringNotContainsString('gridTemplateColumns', $js);
        $this->assertStringNotContainsString('munch-pos-virt', $js);
        $this->assertStringNotContainsString('gridPrimed', $js);
        $this->assertStringNotContainsString('gridLayoutReady', $js);
        $this->assertStringNotContainsString('invalidateGridLayout', $js);
        $this->assertStringNotContainsString('function gridColumnCount', $js);
        $this->assertStringNotContainsString('clientWidth / 180', $js);
        $this->assertStringNotContainsString('split(/\\s+(?![^(]*\\))/)', $js);
        $this->assertStringNotContainsString('setTimeout(renderGrid', $js);
        $this->assertStringContainsString("addEventListener('resize', updateTabArrows)", $js);
    }

    public function test_product_cards_always_include_name_price_and_add_control(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $css = file_get_contents(public_path('assets/admin/css/munch-pos.css'));
        $card = $this->functionBody($js, 'function productCard');

        $this->assertStringContainsString('munch-pos-card__name', $card);
        $this->assertStringContainsString('munch-pos-card__price', $card);
        $this->assertStringContainsString('munch-pos-card__actions', $card);
        $this->assertStringContainsString('munch-pos-card__opt', $card);
        $this->assertStringContainsString('productNeedsVariation(product)', $card);
        $this->assertStringContainsString('variationBadge(product)', $card);
        $this->assertTrue(
            strpos($card, '<img') < strpos($card, 'munch-pos-card__name'),
            'the image must not replace the name block'
        );
        $this->assertTrue(
            strpos($card, 'munch-pos-card__name') < strpos($card, 'munch-pos-card__price'),
            'name and price must both render in the card body'
        );
        $this->assertStringNotContainsString('content-visibility', $css);
        $this->assertStringNotContainsString('contain-intrinsic-size', $css);
        $this->assertDoesNotMatchRegularExpression('/\.munch-pos-card\s*\{[^}]*min-height:\s*0/', $css);
        $this->assertStringContainsString('min-height: 220px', $css);
        $this->assertStringContainsString('min-height: 280px', $css);
    }

    public function test_cart_rows_are_balanced_for_fifteen_inch_touch(): void
    {
        $css = file_get_contents(public_path('assets/admin/css/munch-pos.css'));
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $line = $this->functionBody($js, 'function renderLines');

        $this->assertStringContainsString('munch-pos-line__main', $line);
        $this->assertStringContainsString('munch-pos-line__price', $line);
        $this->assertStringContainsString('munch-pos-line__sub', $line);
        $this->assertStringContainsString("money(lineUnit(line)) + ' × ' + qty", $line);
        $this->assertTrue(
            strpos($line, 'munch-pos-line__main') < strpos($line, 'munch-pos-line__sub'),
            'line total belongs on the right of the name stack'
        );
        $this->assertTrue(
            strpos($line, 'munch-pos-line__sub') < strpos($line, 'munch-pos-qty'),
            'quantity controls stay horizontal beside the line total'
        );
        $this->assertStringContainsString('gap: 0.55rem', $css);
        $this->assertStringContainsString('flex-wrap: nowrap', $css);
        $this->assertStringContainsString('min-width: 44px', $css);
        $this->assertStringContainsString('min-height: 44px', $css);
        $this->assertStringContainsString('minmax(300px, 340px)', $css);
        $this->assertStringNotContainsString('min-height: 62px', $css);
        $this->assertStringNotContainsString('min-height: 70px', $css);
    }

    public function test_cart_variations_wrap_instead_of_truncating(): void
    {
        $css = file_get_contents(public_path('assets/admin/css/munch-pos.css'));
        $metaStart = strpos($css, '.munch-pos-line__meta');
        $this->assertNotFalse($metaStart);
        $meta = substr($css, $metaStart, 280);
        $nameStart = strpos($css, '.munch-pos-line__name');
        $this->assertNotFalse($nameStart);
        $name = substr($css, $nameStart, 280);

        $this->assertStringContainsString('white-space: normal', $meta);
        $this->assertStringContainsString('overflow-wrap: break-word', $meta);
        $this->assertStringContainsString('word-break: normal', $meta);
        $this->assertStringNotContainsString('text-overflow: ellipsis', $meta);
        $this->assertStringNotContainsString('white-space: nowrap', $meta);
        $this->assertStringContainsString('white-space: normal', $name);
        $this->assertStringContainsString('overflow-wrap: break-word', $name);
        $this->assertStringNotContainsString('text-overflow: ellipsis', $name);
        $this->assertStringNotContainsString('-webkit-line-clamp', $name);
        $this->assertStringContainsString('height: auto', $css);
    }

    public function test_search_checkout_and_offline_hooks_are_unchanged(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));

        $this->assertStringContainsString('function filteredProducts', $js);
        $this->assertStringContainsString('function productInCategory', $js);
        $this->assertStringContainsString('function placeOrder', $js);
        $this->assertStringContainsString('function enqueue', $js);
        $this->assertStringContainsString('indexedDB', $js);
        $this->assertStringContainsString('function persistCart', $js);
        $this->assertStringContainsString('state.searchDraft', $js);
    }

    public function test_success_modal_keeps_both_print_actions_visible(): void
    {
        $css = file_get_contents(public_path('assets/admin/css/munch-pos.css'));
        $page = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $success = substr($page, strpos($page, 'id="pos-success-modal"'), 1600);

        $this->assertStringContainsString('munch-pos-success__actions', $success);
        $this->assertTrue(
            strpos($success, 'pos-success-kitchen') < strpos($success, 'pos-success-receipt'),
            'Kitchen print must stay beside Receipt'
        );
        $this->assertTrue(
            strpos($success, 'munch-pos-success__body') < strpos($success, 'munch-pos-success__actions'),
            'print actions stay pinned under the order summary'
        );
        $this->assertStringContainsString('.munch-pos-success__actions', $css);
        $this->assertStringContainsString('grid-template-columns: 1fr 1fr', $css);
        $this->assertStringContainsString('max-height: calc(100dvh - 1.2rem)', $css);
        $this->assertStringContainsString('.munch-pos-dialog__actions', $css);
        $this->assertStringContainsString('munch-pos-dialog__body', $js);
        $this->assertStringContainsString('pos-mod-add', $js);
        $this->assertStringContainsString('function printOneTicket', $js);
        $this->assertStringContainsString('function enqueue', $js);
        $this->assertStringContainsString('.munch-pos-footer .munch-pos-place { grid-area: place; }', $css);
        $this->assertStringNotContainsString("\n    .munch-pos-place { grid-area: place; }", $css);
    }

    public function test_catalog_render_scenarios(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for POS catalog render scenarios');
        }

        $script = base_path('tests/Js/pos-catalog-render.test.js');
        $output = [];
        $code = 0;
        exec(escapeshellcmd($node).' '.escapeshellarg($script).' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertStringContainsString('products render immediately', implode("\n", $output));
        $this->assertStringContainsString('resize is never required', implode("\n", $output));
        $this->assertStringContainsString('product names, prices', implode("\n", $output));
        $this->assertStringContainsString('three-column layout', implode("\n", $output));
    }

    private function functionBody(string $source, string $needle): string
    {
        $start = strpos($source, $needle);
        $this->assertNotFalse($start, $needle.' not found');

        return substr($source, $start, 1800);
    }
}
