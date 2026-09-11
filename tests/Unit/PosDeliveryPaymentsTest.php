<?php

namespace Tests\Unit;

use App\Support\PosOrderTypes;
use Tests\TestCase;

class PosDeliveryPaymentsTest extends TestCase
{
    public function test_delivery_keeps_cash_card_and_mpesa_and_adds_paystack(): void
    {
        $this->assertSame(['cash', 'card', 'mpesa'], PosOrderTypes::paymentMethods('take_away'));
        $this->assertSame(['cash', 'card', 'mpesa'], PosOrderTypes::paymentMethods('dine_in'));
        $this->assertSame(['cash', 'card', 'mpesa', 'paystack'], PosOrderTypes::paymentMethods('delivery'));

        foreach (['cash', 'card', 'mpesa'] as $method) {
            $this->assertTrue(PosOrderTypes::isImmediatePosPayment($method), $method);
            $this->assertTrue(PosOrderTypes::isPaidImmediately('delivery', $method), $method);
            $this->assertTrue(PosOrderTypes::isPaidImmediately('take_away', $method), $method);
            $this->assertTrue(PosOrderTypes::isPaidImmediately('dine_in', $method), $method);
            $this->assertSame($method, PosOrderTypes::resolvedPaymentMethod('delivery', $method));
        }

        $this->assertFalse(PosOrderTypes::isPaidImmediately('delivery', 'cash_on_delivery'));
        $this->assertSame(['glovo'], PosOrderTypes::paymentMethods('glovo'));
        $this->assertSame(['uber'], PosOrderTypes::paymentMethods('uber'));
        $this->assertSame(['bolt_food'], PosOrderTypes::paymentMethods('bolt_food'));
    }

    public function test_pos_client_offers_cash_card_and_mpesa_for_delivery(): void
    {
        $app = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $ticket = file_get_contents(public_path('assets/admin/js/munch-receipt-ticket.js'));
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $page = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));

        $methods = $this->functionBody($app, 'function paymentMethods');
        $this->assertStringContainsString("state.cart.orderType === 'delivery'", $methods);
        $this->assertStringContainsString("['cash', 'card', 'mpesa']", $methods);
        $this->assertStringContainsString("delivery.push('paystack')", $methods);
        $this->assertStringNotContainsString('cash_on_delivery', $methods);

        $this->assertStringContainsString('function immediatePaymentStatus', $app);
        $this->assertStringContainsString('payment_status: immediatePaymentStatus(pay)', $app);
        $this->assertStringContainsString("isPaidImmediately(\$orderType, \$paymentMethod) ? 'paid' : 'unpaid'", $controller);
        $this->assertStringContainsString('PosOrderTypes::isImmediatePosPayment($paymentMethod)', $controller);
        $this->assertStringContainsString('$orderChangeAmount->paid_amount = $order->order_amount;', $controller);
        $this->assertStringNotContainsString('$request->paid_amount', $controller);

        $this->assertStringNotContainsString('id="pos-paid"', $page);
        $this->assertStringNotContainsString('id="pos-paid-wrap"', $page);
        $this->assertStringNotContainsString('id="pos-change"', $page);
        $this->assertStringNotContainsString('hidesPaidAmount', $app);
        $this->assertStringContainsString('paid_amount: grandTotal()', $app);
        $this->assertStringNotContainsString('state.cart.paid || grandTotal()', $app);
        $this->assertStringNotContainsString("L('cashReceived', 'Paid Amount')", $app);
        $this->assertStringNotContainsString('Cash Received', $ticket);

        $this->assertStringContainsString('id="pos-delivery-modal"', $page);
        $this->assertStringContainsString('id="pos-del-name"', $page);
        $this->assertStringContainsString('id="pos-del-fee"', $page);
        $this->assertStringNotContainsString('id="pos-del-rider-name"', $page);
        $this->assertStringNotContainsString('Who will deliver this order?', $page);

        $payment = $this->functionBody($ticket, 'function paymentHtml');
        $this->assertStringContainsString("kind === 'kitchen') return ''", $payment);
        $this->assertStringContainsString('Payment Status', $payment);
        $this->assertStringContainsString("show(kind, template, 'payment', 'payment_status')", $payment);
        $this->assertStringContainsString('PAID', $this->functionBody($ticket, 'function paymentStatusLabel'));
        $this->assertStringNotContainsString('Amount Due', $payment);
        $this->assertStringNotContainsString('Pending Balance', $payment);
        $this->assertStringNotContainsString('Remaining Balance', $payment);
    }

    public function test_customer_receipt_marks_cash_card_and_mpesa_paid_and_kitchen_omits_payment(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required to render POS tickets');
        }

        $ticket = public_path('assets/admin/js/munch-receipt-ticket.js');
        $script = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const sandbox = { window: {}, console };
sandbox.window = sandbox;
vm.runInNewContext(fs.readFileSync(process.argv[2], 'utf8'), sandbox);
const T = sandbox.window.MunchReceiptTicket;
const out = {};
['cash', 'card', 'mpesa'].forEach(function (method) {
  const job = {
    number: '#M-2001',
    orderType: 'Delivery',
    salesChannel: 'delivery',
    isDelivery: true,
    items: [{ name: 'Burger', quantity: 1, options: [], unit_price: 500, line_total: 500 }],
    grand_total: 500,
    payment_method: method,
    payment_status: 'paid',
    cash_received: method === 'cash' ? 500 : 0,
    change: 0
  };
  out[method] = {
    receipt: T.renderDocument('customer', T.defaults('customer'), job),
    kitchen: T.renderDocument('kitchen', T.defaults('kitchen'), job)
  };
});
process.stdout.write(JSON.stringify(out));
JS;
        $tmp = tempnam(sys_get_temp_dir(), 'pos-pay-');
        file_put_contents($tmp, $script);
        $json = shell_exec(escapeshellarg($node).' '.escapeshellarg($tmp).' '.escapeshellarg($ticket).' 2>/dev/null');
        @unlink($tmp);
        $this->assertNotEmpty($json);
        $out = json_decode((string) $json, true);
        $this->assertIsArray($out);

        foreach (['cash' => 'Cash', 'card' => 'Card', 'mpesa' => 'M-PESA'] as $method => $label) {
            $receipt = $out[$method]['receipt'];
            $kitchen = $out[$method]['kitchen'];
            $this->assertStringContainsString('Payment Method', $receipt, $method);
            $this->assertStringContainsString($label, $receipt, $method);
            $this->assertStringContainsString('Payment Status', $receipt, $method);
            $this->assertStringContainsString('PAID', $receipt, $method);
            $this->assertStringNotContainsString('Cash Received', $receipt, $method);
            $this->assertStringNotContainsString('Balance', $receipt, $method);
            $this->assertStringNotContainsString('Amount Due', $receipt, $method);
            $this->assertStringNotContainsString('Pending Balance', $receipt, $method);
            $this->assertStringNotContainsString('Remaining Balance', $receipt, $method);
            $this->assertStringNotContainsString('UNPAID', $receipt, $method);
            $this->assertStringNotContainsString('Payment Method', $kitchen, $method);
            $this->assertStringNotContainsString('Payment Status', $kitchen, $method);
            $this->assertStringNotContainsString('PAID', $kitchen, $method);
        }
    }

    private function functionBody(string $source, string $needle): string
    {
        $start = strpos($source, $needle);
        $this->assertNotFalse($start, $needle.' not found');

        return substr($source, $start, 1800);
    }
}
