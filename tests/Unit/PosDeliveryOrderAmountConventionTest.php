<?php

namespace Tests\Unit;

use App\CentralLogics\PosDeliveryCustomerSms;
use App\Model\Branch;
use App\Model\Order;
use App\Support\OrderPayableAmount;
use Tests\TestCase;

/**
 * Branch POS must store merch-only order_amount (online/admin convention).
 * Delivery fee lives only in delivery_charge; payable = order_amount + fee once.
 */
class PosDeliveryOrderAmountConventionTest extends TestCase
{
    public function test_build_pos_cart_snapshot_excludes_delivery_from_order_amount(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));

        $this->assertStringContainsString(
            "'order_amount' => \$totalPrice + \$totalTaxAmount + \$totalAddonTax,",
            $controller
        );
        $this->assertStringNotContainsString(
            "'order_amount' => \$totalPrice + \$totalTaxAmount + \$deliveryCharge + \$totalAddonTax,",
            $controller
        );
        $this->assertStringContainsString('OrderPayableAmount::payable($order)', $controller);
        $this->assertStringContainsString(
            'OrderPayableAmount::payable($order)',
            file_get_contents(app_path('Services/PosOrderEditService.php'))
        );
    }

    public function test_payable_and_sms_match_cart_total_once(): void
    {
        // A25092-shaped: merch 390 + fee 50 = cart 440 (must not become 490).
        $order = new Order();
        $order->order_type = 'pos';
        $order->sales_channel = 'delivery';
        $order->order_amount = 390;
        $order->delivery_charge = 50;
        $order->payment_method = 'mpesa';
        $order->setRelation('details', collect());
        $order->setRelation('customer', null);
        $order->setRelation('customer_delivery_address', null);
        $order->setRelation('branch', tap(new Branch(), function (Branch $b) {
            $b->name = 'Mtwapa';
            $b->mpesa_till = '123456';
        }));

        $this->assertSame(390.0, OrderPayableAmount::orderAmount($order));
        $this->assertSame(50.0, OrderPayableAmount::deliveryCharge($order));
        $this->assertSame(440.0, OrderPayableAmount::payable($order));

        $vars = PosDeliveryCustomerSms::buildVariables($order);
        $this->assertMatchesRegularExpression('/390/', $vars['subtotal']);
        $this->assertMatchesRegularExpression('/50/', $vars['delivery_fee']);
        $this->assertMatchesRegularExpression('/440/', $vars['total']);
        $this->assertDoesNotMatchRegularExpression('/490/', $vars['total']);
    }

    public function test_admin_online_convention_unchanged_for_takeaway(): void
    {
        $order = [
            'order_type' => 'take_away',
            'order_amount' => 890,
            'delivery_charge' => 200,
        ];
        // Pickup zeros the fee even if a stale delivery_charge is stored.
        $this->assertSame(890.0, OrderPayableAmount::payable($order));
    }
}
