<?php

namespace Tests\Unit;

use App\CentralLogics\PosDeliveryCustomerSms;
use App\Model\Branch;
use App\Model\CustomerAddress;
use App\Model\Order;
use App\Model\OrderDetail;
use Tests\TestCase;

class PosDeliveryCustomerSmsTest extends TestCase
{
    public function test_only_pos_delivery_orders_qualify(): void
    {
        $delivery = new Order();
        $delivery->order_type = 'pos';
        $delivery->sales_channel = 'delivery';
        $this->assertTrue(PosDeliveryCustomerSms::isPosDeliveryOrder($delivery));

        $legacy = new Order();
        $legacy->order_type = 'delivery';
        $legacy->sales_channel = 'delivery';
        $this->assertTrue(PosDeliveryCustomerSms::isPosDeliveryOrder($legacy));

        $website = new Order();
        $website->order_type = 'delivery';
        $website->sales_channel = null;
        $this->assertFalse(PosDeliveryCustomerSms::isPosDeliveryOrder($website));

        foreach (['takeaway', 'dine_in', 'glovo', 'uber', 'bolt_food'] as $channel) {
            $other = new Order();
            $other->order_type = $channel === 'dine_in' ? 'dine_in' : 'pos';
            $other->sales_channel = $channel;
            $this->assertFalse(PosDeliveryCustomerSms::isPosDeliveryOrder($other), $channel);
        }
    }

    public function test_variables_include_branch_till_rider_and_items(): void
    {
        $branch = new Branch();
        $branch->name = 'Westlands';
        $branch->mpesa_till = '554433';

        $order = new Order();
        $order->id = 100123;
        $order->readable_order_id = 'M-100123';
        $order->order_type = 'pos';
        $order->sales_channel = 'delivery';
        $order->order_amount = 1350;
        $order->delivery_charge = 150;
        $order->rider_name = 'Jane Rider';
        $order->rider_phone = '0711111111';
        $order->payment_method = 'mpesa';

        $address = new CustomerAddress();
        $address->contact_person_name = 'John Customer';
        $address->contact_person_number = '0722222222';
        $address->address = 'Ngong Road';

        $burger = new OrderDetail();
        $burger->quantity = 2;
        $burger->product_details = json_encode(['name' => 'Chicken Burger']);
        $fries = new OrderDetail();
        $fries->quantity = 1;
        $fries->product_details = json_encode(['name' => 'Fries']);
        $soda = new OrderDetail();
        $soda->quantity = 1;
        $soda->product_details = json_encode(['name' => 'Soda']);

        $order->setRelation('branch', $branch);
        $order->setRelation('details', collect([$burger, $fries, $soda]));
        $order->setRelation('customer', null);
        $order->setRelation('customer_delivery_address', $address);

        $vars = PosDeliveryCustomerSms::buildVariables($order);

        $this->assertSame('Westlands', $vars['branch_name']);
        $this->assertSame('John Customer', $vars['customer_name']);
        $this->assertSame('0722222222', $vars['customer_phone']);
        $this->assertSame('Ngong Road', $vars['delivery_address']);
        $this->assertMatchesRegularExpression('/150/', $vars['delivery_fee']);
        $this->assertMatchesRegularExpression('/1200/', $vars['subtotal']);
        $this->assertMatchesRegularExpression('/1350/', $vars['total']);
        $this->assertStringContainsString('Jane Rider', $vars['rider_info']);
        $this->assertStringContainsString('554433', $vars['mpesa_info']);
        $this->assertSame('Jane Rider', $vars['rider_name']);
        $this->assertSame('0711111111', $vars['rider_phone']);
        $this->assertSame('554433', $vars['mpesa_till']);
        $this->assertSame("2 x Chicken Burger\n1 x Fries\n1 x Soda", $vars['items']);
        $this->assertArrayHasKey('order_id', $vars);

        $order->payment_method = 'cash';
        $cashVars = PosDeliveryCustomerSms::buildVariables($order);
        $this->assertSame('', $cashVars['mpesa_info']);
    }

    public function test_empty_till_and_rider_omit_those_sentences(): void
    {
        $message = "Your order #M-1 has been received at Westlands and will be delivered by  ().\n\nPlease pay to M-PESA Till  if you haven't already.\n\nThank you!";
        $cleaned = PosDeliveryCustomerSms::omitEmptySections($message, [
            'mpesa_till' => '',
            'rider_name' => '',
        ]);

        $this->assertStringNotContainsString('M-PESA Till', $cleaned);
        $this->assertStringNotContainsString('delivered by', $cleaned);
        $this->assertStringContainsString('Westlands', $cleaned);
    }

    public function test_sms_is_dispatched_only_after_server_order_create_and_never_from_the_pos_client(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));

        $transaction = strpos($controller, 'DB::transaction');
        $sms = strpos($controller, '$this->dispatchPosDeliveryCustomerSms($order)');
        $this->assertNotFalse($transaction);
        $this->assertNotFalse($sms);
        $this->assertGreaterThan($transaction, $sms);
        $this->assertStringContainsString('dispatchPosDeliveryCustomerSms($existing)', $controller);
        $this->assertStringContainsString('customer_pos_delivery_sms_sent_at', file_get_contents(app_path('CentralLogics/PosDeliveryCustomerSms.php')));

        $this->assertStringNotContainsString('SMS', $js);
        $this->assertStringNotContainsString('sms', $js);
        $this->assertStringContainsString('function enqueue(payload)', $js);
        $this->assertStringContainsString('if (state.cart.orderType === \'delivery\')', $js);
        $this->assertStringContainsString('validateDeliveryDetails()', $js);
    }
}
