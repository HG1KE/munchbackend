<?php

namespace Tests\Unit;

use Tests\TestCase;

class PosDuplicateSubmissionTest extends TestCase
{
    public function test_place_order_acquires_lock_before_validation_or_queue_work(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $place = $this->functionBody($js, 'function placeOrder');
        $begin = $this->functionBody($js, 'function beginOrderSubmit');

        $this->assertStringContainsString('if (state.orderSubmitting) return', $begin);
        $this->assertStringContainsString('if (state.orderSubmitting) return', $place);
        $this->assertTrue(
            strpos($place, 'beginOrderSubmit()') < strpos($place, 'validateCart()'),
            'Place Order must lock before validation'
        );
        $this->assertTrue(
            strpos($place, 'if (state.orderSubmitting) return') < strpos($place, 'validateCart()'),
            'submission lock must be acquired before validation'
        );
        $this->assertStringContainsString('ignoreIfSubmitting', $place);
        $this->assertStringContainsString('Queueing...', $js);
        $this->assertStringContainsString('Placing...', $js);
        $this->assertStringContainsString('applySubmitLockUi', $js);
        $this->assertStringContainsString('munch-pos-place__spin', $js);
    }

    public function test_enqueue_ignores_duplicate_client_uuid(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $enqueue = $this->functionBody($js, 'function enqueue');

        $this->assertStringContainsString("idbGet('queue', id)", $enqueue);
        $this->assertStringContainsString('enqueueUnique', $enqueue);
        $this->assertStringContainsString('if (!decision.inserted)', $enqueue);
    }

    public function test_success_modal_and_sync_are_single_flight(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $sw = file_get_contents(public_path('assets/admin/js/munch-pos-sw.js'));
        $open = $this->functionBody($js, 'function openSuccessModal');

        $this->assertStringContainsString('if (successJob) return', $open);
        $this->assertStringContainsString('backgroundSyncOnce', $js);
        $this->assertStringContainsString('if (!navigator.onLine || syncInFlight) return Promise.resolve()', $js);
        $this->assertStringContainsString('var syncRunning = false', $sw);
        $this->assertStringContainsString("if (event.tag !== 'munch-pos-sync') return", $sw);
        $this->assertStringContainsString('if (syncRunning) return', $sw);
        $this->assertStringContainsString('munch-pos-shell-v14', $sw);
    }

    public function test_validation_and_queue_failure_unlock(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $place = $this->functionBody($js, 'function placeOrder');
        $finish = $this->functionBody($js, 'function finishQueuedOrder');

        $this->assertStringContainsString('endOrderSubmit()', $place);
        $this->assertStringContainsString('toast(error)', $place);
        $this->assertStringContainsString('endOrderSubmit()', $finish);
        $this->assertStringContainsString('queueFailed', $finish);
        $this->assertStringContainsString('openSuccessModal(snapshotPrintJob', $finish);
        $this->assertStringContainsString('clearCart()', $finish);
    }

    public function test_enter_and_touch_share_the_same_submit_lock(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $bind = $this->functionBody($js, 'function bindSubmitControl');

        $this->assertStringContainsString("addEventListener('pointerdown'", $bind);
        $this->assertStringContainsString("addEventListener('touchstart'", $bind);
        $this->assertStringContainsString("addEventListener('click'", $bind);
        $this->assertStringContainsString("addEventListener('keydown'", $bind);
        $this->assertStringContainsString("ev.key !== 'Enter'", $js);
        $this->assertStringContainsString('bindSubmitControl(els.place, placeOrder)', $js);
        $this->assertStringContainsString('bindSubmitControl(els.deliveryConfirm, confirmDeliveryAndPlace)', $js);
    }

    public function test_offline_cancel_queue_uses_the_same_uuid_dedupe(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $enqueue = $this->functionBody($js, 'function enqueue');
        $syncOne = $this->functionBody($js, 'function syncOne');
        $submit = $this->functionBody($js, 'function submitCancel');

        $this->assertStringContainsString("payload.action === 'cancel'", $js);
        $this->assertStringContainsString('postCancel(payload)', $syncOne);
        $this->assertStringContainsString('enqueueUnique', $enqueue);
        $this->assertStringContainsString('cancelUi.clientUuid', $submit);
        $this->assertStringContainsString('if (cancelUi.submitting || !cancelUi.order) return', $js);
        $this->assertStringContainsString('applyQueuedCancels', $js);
    }

    public function test_node_duplicate_submission_scenarios(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for POS duplicate-submission scenarios');
        }

        $script = base_path('tests/Js/pos-duplicate-submission.test.js');
        $output = [];
        $code = 0;
        exec(escapeshellcmd($node).' '.escapeshellarg($script).' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertStringContainsString('double mouse click', implode("\n", $output));
        $this->assertStringContainsString('Enter spam', implode("\n", $output));
        $this->assertStringContainsString('touch spam', implode("\n", $output));
        $this->assertStringContainsString('duplicate UUID', implode("\n", $output));
    }

    private function functionBody(string $source, string $needle): string
    {
        $start = strpos($source, $needle);
        $this->assertNotFalse($start, $needle.' not found');

        return substr($source, $start, 1600);
    }
}
