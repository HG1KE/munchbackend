<?php

namespace Tests\Unit;

use Tests\TestCase;

class PosDeliveryResetTest extends TestCase
{
    public function test_pos_delivery_form_is_cleared_after_every_successful_sale(): void
    {
        $page = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));
        $app = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $helper = file_get_contents(public_path('assets/admin/js/munch-pos-delivery.js'));

        $this->assertStringContainsString('munch-pos-delivery.js', $page);
        $this->assertStringContainsString('id="pos-del-name"', $page);
        $this->assertStringContainsString('autocomplete="off"', $page);
        $this->assertStringNotContainsString('autocomplete="name"', $page);
        $this->assertStringNotContainsString('autocomplete="tel"', $page);

        $this->assertStringContainsString('MunchPosDelivery', $app);
        $this->assertStringContainsString('function resetDelivery', $app);
        $this->assertStringContainsString('applyEmptyDelivery(state.cart)', $app);
        $this->assertStringContainsString('fillDeliveryModal()', $app);
        $this->assertStringContainsString('persistableCart(state.cart)', $app);
        $this->assertStringContainsString('hydrateCart(state.cart, results[1])', $app);

        $clear = $this->functionBody($app, 'function clearCart');
        $this->assertStringContainsString('resetDelivery()', $clear);

        $dismiss = $this->functionBody($app, 'function dismissPlacedOrder');
        $this->assertStringContainsString('clearCart()', $dismiss);

        $queued = $this->functionBody($app, 'function finishQueuedOrder');
        $this->assertTrue(
            strpos($queued, 'openSuccessModal(snapshotPrintJob') < strpos($queued, 'clearCart()'),
            'offline success must snapshot delivery details before clearing the form'
        );

        $submit = $this->functionBody($app, 'function submitPlacedOrder');
        $this->assertTrue(
            strpos($submit, 'openSuccessModal(snapshotPrintJob(body))') < strpos($submit, 'clearCart()'),
            'online success must snapshot delivery details before clearing the form'
        );
        $this->assertStringContainsString('clearCart()', $this->successBranch($submit));

        $this->assertStringContainsString('function persistableCart', $helper);
        $this->assertStringContainsString('function hydrateCart', $helper);
        $this->assertStringContainsString("deliveryFee: 0", $helper);
        $this->assertStringContainsString("contact_person_name: ''", $helper);
        $this->assertStringNotContainsString("rider_name: ''", $helper);
        $this->assertStringNotContainsString('pos-del-rider-name', $page);
        $this->assertStringNotContainsString('Who will deliver this order?', $page);
    }

    public function test_node_delivery_reset_scenarios(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for POS delivery reset scenarios');
        }

        $script = base_path('tests/Js/pos-delivery-reset.test.js');
        $output = [];
        $code = 0;
        exec(escapeshellcmd($node).' '.escapeshellarg($script).' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $joined = implode("\n", $output);
        $this->assertStringContainsString('applyEmptyDelivery clears every customer delivery field', $joined);
        $this->assertStringContainsString('persistableCart never writes delivery details', $joined);
        $this->assertStringContainsString('IndexedDB leftover delivery is not restored on a new sale', $joined);
        $this->assertStringContainsString('IndexedDB leftover delivery is not restored with in-progress lines', $joined);
        $this->assertStringContainsString('offline queue payload is independent', $joined);
    }

    private function successBranch(string $submit): string
    {
        $start = strpos($submit, 'if (body && body.success === 1)');
        $this->assertNotFalse($start, 'online success branch not found');

        return substr($submit, $start, 400);
    }

    private function functionBody(string $source, string $needle): string
    {
        $start = strpos($source, $needle);
        $this->assertNotFalse($start, $needle.' not found');

        return substr($source, $start, 1600);
    }
}
