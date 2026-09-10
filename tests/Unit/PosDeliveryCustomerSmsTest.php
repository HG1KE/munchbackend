<?php

namespace Tests\Unit;

use App\CentralLogics\PosDeliveryCustomerSms;
use App\Model\Branch;
use App\Model\CustomerAddress;
use App\Model\Order;
use App\Model\OrderDetail;
use App\Support\SmsTemplateCatalog;
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

    public function test_item_without_variation_shows_name_and_unit_price(): void
    {
        $vars = PosDeliveryCustomerSms::buildVariables($this->deliveryOrder([
            $this->detail('Double Burger', 1, 690),
        ]));

        $this->assertStringContainsString('1 x Double Burger', $vars['items']);
        $this->assertStringNotContainsString('(', $vars['items']);
        $this->assertMatchesRegularExpression('/690/', $vars['items']);
        $this->assertStringNotContainsString('each', $vars['items']);
        $this->assertStringNotContainsString('+', $vars['items']);
    }

    public function test_item_with_one_variation_includes_option_and_surcharge(): void
    {
        $vars = PosDeliveryCustomerSms::buildVariables($this->deliveryOrder([
            $this->detail('Double Burger', 1, 790, [
                ['name' => 'Size', 'values' => [['label' => 'Large', 'optionPrice' => 100]]],
            ]),
        ]));

        $this->assertStringContainsString('1 x Double Burger (Large)', $vars['items']);
        $this->assertMatchesRegularExpression('/690/', $vars['items']);
        $this->assertMatchesRegularExpression('/100/', $vars['items']);
        $this->assertMatchesRegularExpression('/790/', $vars['items']);
        $this->assertStringContainsString(' + ', $vars['items']);
        $this->assertStringContainsString(' = ', $vars['items']);
    }

    public function test_item_with_multiple_variation_options_lists_them_comma_separated(): void
    {
        $vars = PosDeliveryCustomerSms::buildVariables($this->deliveryOrder([
            $this->detail('Chicken Pizza', 1, 950, [
                ['name' => 'Crust', 'values' => [['label' => 'Thin Crust', 'optionPrice' => 50]]],
                ['name' => 'Topping', 'values' => [['label' => 'Extra Cheese', 'optionPrice' => 100]]],
            ]),
        ]));

        $this->assertStringContainsString('1 x Chicken Pizza (Thin Crust, Extra Cheese)', $vars['items']);
        $this->assertMatchesRegularExpression('/800/', $vars['items']);
        $this->assertMatchesRegularExpression('/150/', $vars['items']);
        $this->assertMatchesRegularExpression('/950/', $vars['items']);
    }

    public function test_addon_pricing_is_included_in_the_item_line(): void
    {
        $detail = $this->detail('Chicken Pizza', 1, 800);
        $detail->add_on_ids = json_encode([['id' => 1, 'name' => 'Extra Cheese']]);
        $detail->add_on_qtys = json_encode([1]);
        $detail->add_on_prices = json_encode([50]);

        $vars = PosDeliveryCustomerSms::buildVariables($this->deliveryOrder([$detail]));

        $this->assertStringContainsString('1 x Chicken Pizza (Extra Cheese)', $vars['items']);
        $this->assertMatchesRegularExpression('/800/', $vars['items']);
        $this->assertMatchesRegularExpression('/50/', $vars['items']);
        $this->assertMatchesRegularExpression('/850/', $vars['items']);
    }

    public function test_quantity_greater_than_one_uses_each_pricing(): void
    {
        $vars = PosDeliveryCustomerSms::buildVariables($this->deliveryOrder([
            $this->detail('Double Burger', 2, 790, [
                ['name' => 'Size', 'values' => [['label' => 'Large', 'optionPrice' => 100]]],
            ]),
        ]));

        $this->assertStringContainsString('2 x Double Burger (Large)', $vars['items']);
        $this->assertStringContainsString('each', $vars['items']);
        $this->assertMatchesRegularExpression('/790/', $vars['items']);
    }

    public function test_variables_include_branch_till_rider_delivery_fee_and_items(): void
    {
        $order = $this->deliveryOrder([
            $this->detail('Chicken Burger', 2, 350),
            $this->detail('Fries', 1, 200),
            $this->detail('Soda', 1, 150),
        ], [
            'order_amount' => 1350,
            'delivery_charge' => 150,
            'rider_name' => 'Jane Rider',
            'rider_phone' => '0711111111',
            'payment_method' => 'mpesa',
            'mpesa_till' => '554433',
        ]);

        $vars = PosDeliveryCustomerSms::buildVariables($order);

        $this->assertSame('Westlands', $vars['branch_name']);
        $this->assertSame('John Customer', $vars['customer_name']);
        $this->assertSame('0722222222', $vars['customer_phone']);
        $this->assertSame('Ngong Road', $vars['delivery_address']);
        $this->assertMatchesRegularExpression('/150/', $vars['delivery_fee']);
        $this->assertMatchesRegularExpression('/1200/', $vars['subtotal']);
        $this->assertMatchesRegularExpression('/1350/', $vars['total']);
        $this->assertStringContainsString('Jane Rider', $vars['rider_info']);
        $this->assertStringContainsString('0711111111', $vars['rider_info']);
        $this->assertStringContainsString('554433', $vars['mpesa_info']);
        $this->assertSame('Jane Rider', $vars['rider_name']);
        $this->assertSame('0711111111', $vars['rider_phone']);
        $this->assertSame('554433', $vars['mpesa_till']);
        $this->assertStringContainsString('2 x Chicken Burger', $vars['items']);
        $this->assertStringContainsString('1 x Fries', $vars['items']);
        $this->assertStringContainsString('1 x Soda', $vars['items']);
        $this->assertMatchesRegularExpression('/350/', $vars['items']);
        $this->assertMatchesRegularExpression('/200/', $vars['items']);
        $this->assertArrayHasKey('order_id', $vars);

        $sms = $this->renderDefaultSms($vars);
        $this->assertStringContainsString('Delivery Fee:', $sms);
        $this->assertStringContainsString('M-PESA Till 554433', $sms);
        $this->assertStringContainsString('Jane Rider', $sms);
        $this->assertMatchesRegularExpression('/1350/', $sms);
    }

    public function test_no_mpesa_till_omits_the_till_sentence(): void
    {
        $order = $this->deliveryOrder([
            $this->detail('Double Burger', 1, 690),
        ], [
            'payment_method' => 'mpesa',
            'mpesa_till' => '',
        ]);
        $vars = PosDeliveryCustomerSms::buildVariables($order);
        $this->assertSame('', $vars['mpesa_info']);

        $order->payment_method = 'cash';
        $order->setRelation('branch', tap(new Branch(), function (Branch $branch) {
            $branch->name = 'Westlands';
            $branch->mpesa_till = '554433';
        }));
        $cashVars = PosDeliveryCustomerSms::buildVariables($order);
        $this->assertSame('', $cashVars['mpesa_info']);

        $sms = $this->renderDefaultSms($cashVars);
        $this->assertStringNotContainsString('M-PESA Till', $sms);
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

    public function test_sample_sms_includes_plain_item_variation_prices_totals_rider_and_till(): void
    {
        $order = $this->deliveryOrder([
            $this->detail('Fries', 1, 200),
            $this->detail('Double Burger', 2, 790, [
                ['name' => 'Size', 'values' => [['label' => 'Large', 'optionPrice' => 100]]],
            ]),
        ], [
            'order_amount' => 1930,
            'delivery_charge' => 150,
            'rider_name' => 'Jane Rider',
            'rider_phone' => '0711111111',
            'payment_method' => 'mpesa',
            'mpesa_till' => '554433',
        ]);

        $sms = $this->renderDefaultSms(PosDeliveryCustomerSms::buildVariables($order));

        $this->assertStringContainsString('1 x Fries', $sms);
        $this->assertStringContainsString('2 x Double Burger (Large)', $sms);
        $this->assertStringContainsString('each', $sms);
        $this->assertStringContainsString('Jane Rider', $sms);
        $this->assertStringContainsString('0711111111', $sms);
        $this->assertStringContainsString('M-PESA Till 554433', $sms);
        $this->assertMatchesRegularExpression('/200/', $sms);
        $this->assertMatchesRegularExpression('/790/', $sms);
        $this->assertMatchesRegularExpression('/150/', $sms);
        $this->assertMatchesRegularExpression('/1930/', $sms);
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

    /**
     * @param  list<OrderDetail>  $details
     * @param  array<string, mixed>  $overrides
     */
    private function deliveryOrder(array $details, array $overrides = []): Order
    {
        $branch = new Branch();
        $branch->name = 'Westlands';
        $branch->mpesa_till = (string) ($overrides['mpesa_till'] ?? '554433');

        $order = new Order();
        $order->id = 100123;
        $order->readable_order_id = 'M-100123';
        $order->order_type = 'pos';
        $order->sales_channel = 'delivery';
        $order->order_amount = $overrides['order_amount'] ?? 1350;
        $order->delivery_charge = $overrides['delivery_charge'] ?? 150;
        $order->rider_name = $overrides['rider_name'] ?? 'Jane Rider';
        $order->rider_phone = $overrides['rider_phone'] ?? '0711111111';
        $order->payment_method = $overrides['payment_method'] ?? 'mpesa';

        $address = new CustomerAddress();
        $address->contact_person_name = 'John Customer';
        $address->contact_person_number = '0722222222';
        $address->address = 'Ngong Road';

        $order->setRelation('branch', $branch);
        $order->setRelation('details', collect($details));
        $order->setRelation('customer', null);
        $order->setRelation('customer_delivery_address', $address);

        return $order;
    }

    /**
     * @param  list<array<string, mixed>>  $variation
     */
    private function detail(string $name, int $qty, float $price, array $variation = []): OrderDetail
    {
        $detail = new OrderDetail();
        $detail->quantity = $qty;
        $detail->price = $price;
        $detail->product_details = json_encode(['name' => $name, 'price' => $price]);
        $detail->variation = json_encode($variation);
        $detail->add_on_ids = json_encode([]);
        $detail->add_on_qtys = json_encode([]);
        $detail->add_on_prices = json_encode([]);

        return $detail;
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function renderDefaultSms(array $vars): string
    {
        $template = (string) (SmsTemplateCatalog::definition(SmsTemplateCatalog::POS_DELIVERY_CUSTOMER)['default_message'] ?? '');
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{'.$key.'}'] = $value;
        }

        return PosDeliveryCustomerSms::omitEmptySections(strtr($template, $replacements), $vars);
    }
}
