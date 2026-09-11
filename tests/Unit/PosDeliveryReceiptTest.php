<?php

namespace Tests\Unit;

use App\CentralLogics\PosDeliveryCustomerSms;
use App\Model\Order;
use App\Services\BranchPosTodayOrdersService;
use App\Support\PosOrderTypes;
use App\Support\SmsTemplateCatalog;
use App\User;
use Tests\TestCase;

class PosDeliveryReceiptTest extends TestCase
{
    public function test_serialized_delivery_order_exposes_saved_customer_details(): void
    {
        $order = $this->deliveryOrder([
            'contact_person_name' => 'John Doe',
            'contact_person_number' => '0712345678',
            'address' => 'Nyali, Links Road',
            'phone' => '0712345678',
        ], 200);

        $user = new User();
        $user->f_name = 'Walk';
        $user->l_name = 'In';
        $user->phone = '0700000000';
        $order->setRelation('customer', $user);

        $payload = (new BranchPosTodayOrdersService())->serializeOrder($order, 'Nyali');

        $this->assertSame('John Doe', $payload['customer']);
        $this->assertSame('0712345678', $payload['phone']);
        $this->assertSame('Nyali, Links Road', $payload['address']);
        $this->assertSame(200.0, $payload['delivery_fee']);
        $this->assertSame('delivery', $payload['sales_channel']);
        $this->assertSame('pos', $order->order_type);
        $this->assertSame('delivery', PosOrderTypes::salesChannel('delivery'));
        $this->assertSame('pos', PosOrderTypes::databaseType('delivery'));
    }

    public function test_place_order_json_and_offline_sync_use_saved_order_for_receipts(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $app = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $page = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));

        $this->assertStringContainsString('function persistPosDeliveryAddressJson', $controller);
        $this->assertStringContainsString("DB::table('orders')->where('id', \$order->id)->update([", $controller);
        $this->assertStringContainsString("'delivery_address' => json_encode(\$payload)", $controller);
        $this->assertStringContainsString('function posPlacedOrderJson', $controller);
        $this->assertStringContainsString("'order' => \$this->posTodayOrders->serializeOrder(\$fresh, \$cashierName)", $controller);
        $this->assertStringContainsString('$this->persistPosDeliveryAddressJson($order, $orderType, $customerAddress)', $controller);

        $this->assertStringContainsString('if (body && body.order)', $app);
        $this->assertStringContainsString('return printJobFromOrder(body.order)', $app);
        $this->assertStringContainsString('openSuccessModal(snapshotPrintJob(body))', $app);
        $this->assertStringContainsString(', payload)', $this->functionBody($app, 'function finishQueuedOrder'));
        $this->assertStringContainsString('printJobFromOrder(order)', $app);
        $this->assertStringNotContainsString("rider_name: state.cart.orderType === 'delivery'", $app);
        $this->assertStringNotContainsString('pos-del-rider-name', $app);
        $this->assertStringNotContainsString('pos-del-rider-name', $page);
        $this->assertStringNotContainsString('Who will deliver this order?', $page);
        $this->assertStringContainsString('id="pos-del-name"', $page);
        $this->assertStringContainsString('id="pos-del-phone"', $page);
        $this->assertStringContainsString('id="pos-del-address"', $page);
        $this->assertStringContainsString('id="pos-del-fee"', $page);
    }

    public function test_pos_delivery_sms_has_no_rider_placeholder_and_keeps_mpesa_till(): void
    {
        $def = SmsTemplateCatalog::definition(SmsTemplateCatalog::POS_DELIVERY_CUSTOMER);
        $this->assertNotContains('{rider_name}', $def['placeholders']);
        $this->assertNotContains('{rider_phone}', $def['placeholders']);
        $this->assertNotContains('{rider_info}', $def['placeholders']);
        $this->assertStringNotContainsString('{rider_info}', $def['default_message']);
        $this->assertStringNotContainsString('delivered by', $def['default_message']);
        $this->assertContains('{mpesa_till}', $def['placeholders']);
        $this->assertContains('{mpesa_info}', $def['placeholders']);

        $order = new Order();
        $order->id = 100123;
        $order->readable_order_id = 'M-100123';
        $order->order_type = 'pos';
        $order->sales_channel = 'delivery';
        $order->order_amount = 890;
        $order->delivery_charge = 200;
        $order->rider_name = 'Jane Rider';
        $order->rider_phone = '0711111111';
        $order->payment_method = 'mpesa';
        $order->setRelation('details', collect());
        $order->setRelation('customer', null);
        $order->setRelation('customer_delivery_address', null);
        $order->setRawAttributes(array_merge($order->getAttributes(), [
            'delivery_address' => json_encode([
                'contact_person_name' => 'John Doe',
                'contact_person_number' => '0712345678',
                'address' => 'Nyali, Links Road',
            ]),
        ]), true);

        $branch = new \App\Model\Branch();
        $branch->name = 'Nyali';
        $branch->mpesa_till = '554433';
        $order->setRelation('branch', $branch);

        $vars = PosDeliveryCustomerSms::buildVariables($order);
        $this->assertSame('', $vars['rider_name']);
        $this->assertSame('', $vars['rider_phone']);
        $this->assertSame('', $vars['rider_info']);
        $this->assertSame('554433', $vars['mpesa_till']);
        $this->assertStringContainsString('554433', $vars['mpesa_info']);

        $sms = $this->renderSms($vars);
        $this->assertStringNotContainsString('Jane Rider', $sms);
        $this->assertStringNotContainsString('delivered by', $sms);
        $this->assertStringNotContainsString('{rider', $sms);
        $this->assertStringContainsString('M-PESA Till 554433', $sms);
        $this->assertStringContainsString('John Doe', $vars['customer_name']);
    }

    public function test_pos_delivery_classification_is_unchanged(): void
    {
        $this->assertSame('pos', PosOrderTypes::databaseType('delivery'));
        $this->assertSame('delivery', PosOrderTypes::salesChannel('delivery'));
        $this->assertTrue(PosOrderTypes::isPosDeliveryOrder('pos', 'delivery'));
        $this->assertFalse(PosOrderTypes::isOnlineOrder('pos', 'delivery'));
        $this->assertSame(['cash', 'card', 'mpesa'], PosOrderTypes::paymentMethods('delivery'));
    }

    public function test_node_delivery_receipt_scenarios(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for POS delivery receipt scenarios');
        }

        $script = base_path('tests/Js/pos-delivery-receipt.test.js');
        $output = [];
        $code = 0;
        exec(escapeshellcmd($node).' '.escapeshellarg($script).' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $joined = implode("\n", $output);
        $this->assertStringContainsString('delivery receipt prints saved customer name phone address and fee', $joined);
        $this->assertStringContainsString('reprinting a saved delivery order keeps customer details', $joined);
        $this->assertStringContainsString('offline snapshot keeps queued customer details', $joined);
        $this->assertStringContainsString('new delivery payloads omit rider fields', $joined);
        $this->assertStringContainsString('delivery modal no longer collects rider fields', $joined);
    }

    /**
     * @param  array<string, string>  $address
     */
    private function deliveryOrder(array $address, float $fee): Order
    {
        $order = new Order();
        $order->setRawAttributes([
            'id' => 9001,
            'branch_id' => 1,
            'order_amount' => 890,
            'delivery_charge' => $fee,
            'extra_discount' => 0,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'order_status' => 'confirmed',
            'order_type' => 'pos',
            'sales_channel' => 'delivery',
            'order_note' => null,
            'rider_name' => null,
            'rider_phone' => null,
            'kitchen_printed_at' => null,
            'receipt_printed_at' => null,
            'created_at' => now()->utc()->toDateTimeString(),
            'updated_at' => now()->utc()->toDateTimeString(),
            'delivery_address' => json_encode($address),
        ], true);
        $order->exists = true;
        $order->setRelation('details', collect());
        $order->setRelation('customer', null);
        $order->setRelation('customer_delivery_address', null);
        $order->setRelation('order_change_amount', null);
        $order->setRelation('branch', null);
        $order->setRelation('cancelledByBranch', null);
        $order->setRelation('cancelledByAdmin', null);

        return $order;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function renderSms(array $vars): string
    {
        $template = (string) (SmsTemplateCatalog::definition(SmsTemplateCatalog::POS_DELIVERY_CUSTOMER)['default_message'] ?? '');
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{'.$key.'}'] = $value;
        }

        return PosDeliveryCustomerSms::omitEmptySections(strtr($template, $replacements), $vars);
    }

    private function functionBody(string $source, string $needle): string
    {
        $start = strpos($source, $needle);
        $this->assertNotFalse($start, $needle.' not found');

        return substr($source, $start, 1600);
    }
}
