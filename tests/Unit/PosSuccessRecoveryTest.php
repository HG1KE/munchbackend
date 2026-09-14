<?php

namespace Tests\Unit;

use App\Support\PosClientVersion;
use Tests\TestCase;

class PosSuccessRecoveryTest extends TestCase
{
    public function test_success_modal_cannot_be_dismissed_from_the_backdrop(): void
    {
        $app = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $page = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));

        $this->assertStringNotContainsString("ev.target.id === 'pos-success-modal'", $app);
        $this->assertStringContainsString('els.successClose.addEventListener(\'click\', dismissPlacedOrder)', $app);
        $this->assertStringContainsString('els.successDone.addEventListener(\'click\', dismissPlacedOrder)', $app);
        $this->assertStringContainsString('id="pos-success-done"', $page);
        $this->assertStringContainsString('id="pos-success-close"', $page);
        $this->assertStringContainsString('id="pos-success-kitchen"', $page);
        $this->assertStringContainsString('id="pos-success-receipt"', $page);
    }

    public function test_timeout_recovers_through_existing_client_uuid_idempotency(): void
    {
        $app = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $guard = file_get_contents(public_path('assets/admin/js/munch-pos-submit-guard.js'));

        $this->assertStringContainsString('function recoverUncertainSubmit', $app);
        $this->assertStringContainsString('function handlePostedResult', $app);
        $this->assertStringContainsString('function acceptPostedOrder', $app);
        $this->assertStringContainsString('function leaveSafeRetry', $app);
        $this->assertStringContainsString('Checking order status...', $app);
        $this->assertStringContainsString('Still confirming this order. Tap Place Order to try again.', $app);
        $this->assertStringContainsString('buildAttemptPayload', $app);
        $this->assertStringContainsString('nextAttemptKeys', $app);
        $this->assertStringNotContainsString('reportSubmitFailure(timeoutMessage(), true)', $app);
        $this->assertStringContainsString('function recoverPlaceOutcome', $guard);
        $this->assertStringContainsString('isUncertainPlaceFailure', $guard);
        $this->assertSame('6.8', PosClientVersion::ASSET);
    }

    public function test_node_success_recovery_scenarios(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for POS success-recovery scenarios');
        }

        $script = base_path('tests/Js/pos-success-recovery.test.js');
        $output = [];
        $code = 0;
        exec(escapeshellcmd($node).' '.escapeshellarg($script).' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertStringContainsString('normal 200 opens the success modal', implode("\n", $output));
        $this->assertStringContainsString('timeout but order exists', implode("\n", $output));
        $this->assertStringContainsString('network failure but order exists', implode("\n", $output));
        $this->assertStringContainsString('retries the same uuid', implode("\n", $output));
        $this->assertStringContainsString('cannot create a duplicate order', implode("\n", $output));
        $this->assertStringContainsString('backdrop click does not dismiss', implode("\n", $output));
    }

    private function functionBody(string $source, string $needle): string
    {
        $start = strpos($source, $needle);
        $this->assertNotFalse($start, $needle.' not found');

        return substr($source, $start, 2500);
    }
}
