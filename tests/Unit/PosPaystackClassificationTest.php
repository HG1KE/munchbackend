<?php

namespace Tests\Unit;

use App\Support\AdminSaleReportSummary;
use App\Support\PosOrderTypes;
use Tests\TestCase;

class PosPaystackClassificationTest extends TestCase
{
    public function test_paystack_is_delivery_only_and_not_a_marketplace_or_online_order(): void
    {
        $this->assertSame(['cash', 'paystack', 'mpesa'], PosOrderTypes::paymentMethods('delivery'));
        $this->assertCount(3, PosOrderTypes::paymentMethods('delivery'));
        $this->assertNotContains('card', PosOrderTypes::paymentMethods('delivery'));
        $this->assertNotContains('paystack', PosOrderTypes::paymentMethods('take_away'));
        $this->assertNotContains('paystack', PosOrderTypes::paymentMethods('dine_in'));
        $this->assertContains('card', PosOrderTypes::paymentMethods('take_away'));
        $this->assertContains('card', PosOrderTypes::paymentMethods('dine_in'));
        $this->assertNotContains('paystack', PosOrderTypes::paymentMethods('glovo'));
        $this->assertNotContains('paystack', PosOrderTypes::paymentMethods('uber'));
        $this->assertNotContains('paystack', PosOrderTypes::paymentMethods('bolt_food'));

        $this->assertSame('paystack', PosOrderTypes::resolvedPaymentMethod('delivery', 'paystack'));
        $this->assertSame('paystack', PosOrderTypes::resolvedPaymentMethod('delivery', 'card'));
        $this->assertSame('card', PosOrderTypes::resolvedPaymentMethod('take_away', 'paystack'));
        $this->assertSame('card', PosOrderTypes::resolvedPaymentMethod('dine_in', 'paystack'));
        $this->assertSame('card', PosOrderTypes::resolvedPaymentMethod('take_away', 'card'));
        $this->assertSame('glovo', PosOrderTypes::resolvedPaymentMethod('glovo', 'paystack'));

        $this->assertTrue(PosOrderTypes::isImmediatePosPayment('paystack'));
        $this->assertTrue(PosOrderTypes::isPaidImmediately('delivery', 'paystack'));
        $this->assertSame('confirmed', PosOrderTypes::defaultStatus('delivery'));
        $this->assertSame('pos', PosOrderTypes::databaseType('delivery'));
        $this->assertSame('delivery', PosOrderTypes::salesChannel('delivery'));
        $this->assertTrue(PosOrderTypes::isPosDeliveryOrder('pos', 'delivery'));
        $this->assertFalse(PosOrderTypes::isOnlineOrder('pos', 'delivery'));
        $this->assertFalse(PosOrderTypes::isMarketplacePayment('paystack'));
        $this->assertSame('Paystack', PosOrderTypes::paymentDisplayLabel('paystack'));
        $this->assertSame('Paystack', PosOrderTypes::paymentReceiptLabel('paystack'));
    }

    public function test_sale_report_and_pos_files_classify_paystack_without_a_gateway(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/ReportController.php'));
        $pos = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $app = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $ticket = file_get_contents(public_path('assets/admin/js/munch-receipt-ticket.js'));
        $page = file_get_contents(resource_path('views/admin-views/report/sale-report.blade.php'));
        $posPage = file_get_contents(resource_path('views/branch-views/pos/index.blade.php'));

        $this->assertStringContainsString("'paystack' => 0.0", $controller);
        $this->assertStringContainsString("when(\$request['branch_id'] !== 'all'", $controller);
        $this->assertStringContainsString('id="pay-paystack"', $page);
        $this->assertStringContainsString('paystack: @json(translate(\'Paystack\'))', $posPage);
        $this->assertStringContainsString("['cash', 'paystack', 'mpesa']", $app);
        $this->assertStringContainsString('function remapPaymentForOrderType', $app);
        $this->assertStringNotContainsString("delivery.push('paystack')", $app);
        $this->assertStringContainsString("if (method === 'paystack') return L('paystack', 'Paystack')", $app);
        $this->assertStringContainsString("type: state.cart.payment", $app);
        $this->assertStringNotContainsString('PaystackPop', $app);
        $this->assertStringNotContainsString('paystack.com', $app);
        $this->assertStringNotContainsString('initializeTransaction', $app);
        $this->assertStringNotContainsString('inline_checkout', $app);
        $this->assertStringNotContainsString('PaystackController', $pos);
        $this->assertStringNotContainsString('paystack.com', $pos);
        $this->assertStringNotContainsString('initializeTransaction', $pos);
        $this->assertStringContainsString("if (key === 'paystack') return 'Paystack'", $ticket);
        $this->assertStringNotContainsString('PaystackPop', $ticket);

        $groups = AdminSaleReportSummary::fromPaymentTotals([
            'cash' => 10,
            'card' => 20,
            'mpesa' => 30,
            'paystack' => 400,
            'glovo' => 5,
            'uber' => 6,
            'bolt_food' => 7,
        ]);
        $this->assertSame(60.0, $groups['munch_sales']);
        $this->assertSame(18.0, $groups['marketplace_sales']);
    }
}
