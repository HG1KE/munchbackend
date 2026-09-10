<?php

namespace Tests\Unit;

use Tests\TestCase;

class PosTouchLayoutTest extends TestCase
{
    public function test_fifteen_inch_screens_force_three_product_columns(): void
    {
        $css = file_get_contents(public_path('assets/admin/css/munch-pos.css'));

        $this->assertStringContainsString('@media (min-width: 900px) and (max-width: 1440px)', $css);
        $this->assertStringContainsString('grid-template-columns: repeat(3, minmax(0, 1fr))', $css);
        $this->assertStringContainsString('@media (min-width: 1441px)', $css);
        $this->assertStringContainsString('repeat(auto-fill, minmax(220px, 1fr))', $css);
        $this->assertStringContainsString('minmax(300px, 340px)', $css);
        $this->assertStringContainsString('-webkit-line-clamp: 2', $css);
        $this->assertStringContainsString('overflow-x: hidden', $css);
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

    public function test_virtualized_grid_reads_computed_columns_instead_of_a_fixed_divisor(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));

        $this->assertStringContainsString('function gridColumnCount', $js);
        $this->assertStringContainsString('function gridRowHeight', $js);
        $this->assertStringContainsString('gridColumnCount()', $js);
        $this->assertStringContainsString('gridRowHeight()', $js);
        $this->assertStringNotContainsString('clientWidth / 180', $js);
        $this->assertStringContainsString('gridW', $js);
        $this->assertStringContainsString('gridH', $js);
    }

    public function test_product_grid_invalidates_layout_after_initial_paint(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));

        $this->assertStringContainsString('function invalidateGridLayout', $js);
        $this->assertStringContainsString('function scheduleLayoutPass', $js);
        $this->assertStringContainsString('function gridLayoutReady', $js);
        $this->assertStringContainsString('function observeGridLayout', $js);
        $this->assertStringContainsString('ResizeObserver', $js);
        $this->assertStringContainsString('document.fonts.ready', $js);
        $this->assertStringContainsString('list.length > 48 && gridLayoutReady()', $js);
        $this->assertStringContainsString('scheduleLayoutPass()', $js);
        $this->assertStringContainsString("window.addEventListener('resize', invalidateGridLayout)", $js);
        $this->assertStringNotContainsString('setTimeout(invalidateGridLayout', $js);
    }

    public function test_cart_rows_are_compact_with_touch_sized_quantity_controls(): void
    {
        $css = file_get_contents(public_path('assets/admin/css/munch-pos.css'));
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));

        $this->assertStringContainsString('munch-pos-line__main', $js);
        $this->assertStringContainsString('munch-pos-line__details', $js);
        $this->assertStringContainsString('munch-pos-line__price', $js);
        $this->assertStringContainsString("money(lineUnit(line)) + ' × ' + qty", $js);
        $this->assertStringContainsString('.munch-pos-line__name', $css);
        $this->assertStringContainsString('font-weight: 600', $css);
        $this->assertStringContainsString('font-weight: 500', $css);
        $this->assertStringContainsString('flex-wrap: nowrap', $css);
        $this->assertStringContainsString('min-width: 44px', $css);
        $this->assertStringContainsString('min-height: 44px', $css);
        $this->assertStringNotContainsString('width: 48px;' . "\n" . '        height: 48px;' . "\n" . '        min-width: 48px;' . "\n" . '        min-height: 48px;', $css);
    }
}
