<?php

namespace Tests\Unit;

use App\Jobs\SendPosDeliveryCustomerSmsJob;
use App\Support\PosClientVersion;
use App\Support\PosOrderTypes;
use Tests\TestCase;

class PosDeliverySubmitAuditTest extends TestCase
{
    public function test_frontend_keeps_delivery_modal_open_until_a_real_outcome(): void
    {
        $app = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $confirm = $this->functionBody($app, 'function confirmDeliveryAndPlace');
        $submit = $this->functionBody($app, 'function submitPlacedOrder');

        $this->assertStringNotContainsString('closeDeliveryModal();', $confirm);
        $this->assertStringContainsString('submitPlacedOrder()', $confirm);
        $this->assertStringContainsString('Posting order...', $app);
        $this->assertStringContainsString('setDeliveryFormBusy(true)', $app);
        $this->assertStringContainsString('reportSubmitFailure', $submit);
        $this->assertStringContainsString("body._http === 422", $submit);
        $this->assertStringContainsString('closeDeliveryModal(true)', $submit);
        $this->assertStringContainsString("err.code === 'timeout'", $submit);
        $this->assertStringContainsString('AbortController', $app);
        $this->assertStringContainsString('buildAttemptPayload', $app);
        $this->assertStringContainsString('reportSubmitFailure(timeoutMessage(), true)', $submit);
    }

    public function test_phone_rules_match_on_frontend_and_backend(): void
    {
        $this->assertSame('0712345678', PosOrderTypes::canonicalPosDeliveryPhone('0712 345 678'));
        $this->assertSame('Enter a valid 10-digit phone number.', PosOrderTypes::posDeliveryPhoneError('071234567'));
        $this->assertSame('Enter a valid 10-digit phone number.', PosOrderTypes::posDeliveryPhoneError('+254712345678'));
        $this->assertNull(PosOrderTypes::posDeliveryPhoneError('0712345678'));

        $helper = file_get_contents(public_path('assets/admin/js/munch-pos-delivery.js'));
        $this->assertStringContainsString('function normalizePosDeliveryPhone', $helper);
        $this->assertStringContainsString('Enter a valid 10-digit phone number.', $helper);

        $app = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $this->assertStringContainsString('bindDeliveryPhoneField', $app);
        $this->assertStringContainsString("'input', 'change', 'blur', 'paste'", $app);
        $this->assertStringContainsString('canonicalDeliveryPhone', $app);
        $this->assertStringContainsString('pos_asset_version', $app);
        $this->assertStringContainsString('isPosClientStale', $app);
    }

    public function test_sms_is_queued_after_commit_and_does_not_block_json(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $this->assertStringContainsString('SendPosDeliveryCustomerSmsJob::dispatch((int) $order->id)->afterResponse()', $controller);
        $this->assertStringContainsString("'pos_asset_version' => PosClientVersion::ASSET", $controller);
        $this->assertStringContainsString('canonicalPosDeliveryPhone', $controller);

        $job = file_get_contents(app_path('Jobs/SendPosDeliveryCustomerSmsJob.php'));
        $this->assertStringContainsString('PosDeliveryCustomerSms::dispatch($order)', $job);
        $this->assertStringContainsString('implements ShouldQueue', $job);

        $this->assertSame('6.0', PosClientVersion::ASSET);
        $this->assertTrue(is_a(SendPosDeliveryCustomerSmsJob::class, \Illuminate\Contracts\Queue\ShouldQueue::class, true));
    }

    public function test_print_failure_cannot_look_like_a_missing_sale(): void
    {
        $app = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $print = $this->functionBody($app, 'function printPlacedTicket');
        $this->assertStringContainsString('postedPrintFailed', $print);
        $this->assertStringNotContainsString('clearPendingAttempt()', $print);
        $this->assertStringNotContainsString('buildAttemptPayload()', $print);
        $this->assertStringContainsString('Order {n} posted successfully, but receipt printing failed.', $app);
    }

    public function test_boot_does_not_wipe_an_open_delivery_modal(): void
    {
        $app = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $boot = $this->functionBody($app, 'function boot');
        $this->assertStringContainsString('isDeliveryModalOpen()', $boot);
        $this->assertStringContainsString('keepLiveDelivery', $boot);
        $this->assertStringContainsString('if (!isDeliveryModalOpen() && !state.orderSubmitting)', $boot);
    }

    public function test_node_delivery_submit_and_phone_scenarios(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for POS delivery submit scenarios');
        }

        foreach ([
            'tests/Js/pos-delivery-reset.test.js',
            'tests/Js/pos-duplicate-submission.test.js',
            'tests/Js/pos-marketplace-order-number.test.js',
        ] as $relative) {
            $output = [];
            $code = 0;
            exec(escapeshellcmd($node).' '.escapeshellarg(base_path($relative)).' 2>&1', $output, $code);
            $this->assertSame(0, $code, $relative."\n".implode("\n", $output));
        }
    }

    private function functionBody(string $source, string $needle): string
    {
        $start = strpos($source, $needle);
        $this->assertNotFalse($start, $needle.' not found');

        return substr($source, $start, 4000);
    }
}
