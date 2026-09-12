<?php

namespace Tests\Unit;

use App\Support\AdminSaleReportSummary;
use Tests\TestCase;

class AdminSaleReportSummaryTest extends TestCase
{
    public function test_net_sales_is_gross_minus_product_and_order_discounts(): void
    {
        $summary = AdminSaleReportSummary::fromParts([
            'gross' => 1000,
            'item_discount' => 80,
            'extra_discount' => 20,
            'coupon_discount' => 50,
            'referral_discount' => 10,
            'tax' => 40,
            'delivery_fees' => 150,
            'total_sales' => 920,
        ]);

        $this->assertSame(1000.0, $summary['gross_sales']);
        $this->assertSame(160.0, $summary['total_discounts']);
        $this->assertSame(840.0, $summary['net_sales']);
        $this->assertSame(40.0, $summary['tax']);
        $this->assertSame(150.0, $summary['delivery_fees']);
        $this->assertSame(920.0, $summary['total_sales']);
    }

    public function test_existing_total_sales_is_not_recalculated(): void
    {
        $summary = AdminSaleReportSummary::fromParts([
            'gross' => 500,
            'item_discount' => 100,
            'total_sales' => 1234.56,
        ]);

        $this->assertSame(1234.56, $summary['total_sales']);
        $this->assertSame(400.0, $summary['net_sales']);
    }

    public function test_munch_sales_is_cash_card_and_mpesa(): void
    {
        $groups = AdminSaleReportSummary::fromPaymentTotals([
            'cash' => 100,
            'card' => 200,
            'mpesa' => 50,
            'glovo' => 80,
            'uber' => 40,
            'bolt_food' => 30,
        ]);

        $this->assertSame(350.0, $groups['munch_sales']);
        $this->assertSame(150.0, $groups['marketplace_sales']);
    }

    public function test_paystack_is_not_included_in_munch_or_marketplace_sales(): void
    {
        $groups = AdminSaleReportSummary::fromPaymentTotals([
            'cash' => 100,
            'card' => 200,
            'mpesa' => 50,
            'paystack' => 5000,
            'glovo' => 80,
            'uber' => 40,
            'bolt_food' => 30,
        ]);

        $this->assertSame(350.0, $groups['munch_sales']);
        $this->assertSame(150.0, $groups['marketplace_sales']);
        $this->assertNotEquals(5350.0, $groups['munch_sales']);
    }

    public function test_payment_groups_do_not_change_total_sales(): void
    {
        $summary = AdminSaleReportSummary::fromParts([
            'gross' => 1000,
            'item_discount' => 0,
            'total_sales' => 777,
        ]);
        $groups = AdminSaleReportSummary::fromPaymentTotals([
            'cash' => 400,
            'card' => 100,
            'mpesa' => 50,
            'glovo' => 200,
            'uber' => 100,
            'bolt_food' => 50,
        ]);

        $this->assertSame(777.0, $summary['total_sales']);
        $this->assertSame(550.0, $groups['munch_sales']);
        $this->assertSame(350.0, $groups['marketplace_sales']);
        $this->assertNotSame($summary['total_sales'], $groups['munch_sales'] + $groups['marketplace_sales']);
    }

    public function test_sale_report_exposes_discount_summary_without_changing_payment_totals(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/ReportController.php'));
        $page = file_get_contents(resource_path('views/admin-views/report/sale-report.blade.php'));
        $table = file_get_contents(resource_path('views/admin-views/report/partials/_table.blade.php'));
        $pdf = file_get_contents(resource_path('views/admin-views/report/partials/_report.blade.php'));

        $this->assertStringContainsString('SUM(discount_on_product * quantity)', $controller);
        $this->assertStringContainsString('SUM(price * quantity)', $controller);
        $this->assertStringContainsString('SUM(extra_discount)', $controller);
        $this->assertStringContainsString('SUM(coupon_discount_amount)', $controller);
        $this->assertStringContainsString('SUM(referral_discount)', $controller);
        $this->assertStringContainsString("\$price = \$detail['price'] - \$detail['discount_on_product']", $controller);
        $this->assertStringContainsString("'order_sum' => Helpers::set_symbol(\$totalSold)", $controller);
        $this->assertStringContainsString("'cash' => Helpers::set_symbol(\$paymentTotals['cash'])", $controller);
        $this->assertStringContainsString("'paystack' => Helpers::set_symbol(\$paymentTotals['paystack'])", $controller);
        $this->assertStringContainsString("input('payment_method', 'all')", $controller);
        $this->assertStringContainsString('PosOrderTypes::constrainSaleReportChannel($query, $channel)', $controller);
        $this->assertStringNotContainsString("\$query->where('sales_channel', \$channel)", $controller);
        $this->assertStringContainsString('fromPaymentTotals($paymentTotals)', $controller);
        $this->assertStringContainsString("\$summaryDisplay['munch_sales']", $controller);
        $this->assertStringContainsString("\$summaryDisplay['marketplace_sales']", $controller);

        $this->assertStringContainsString('id="sum-total-discounts"', $page);
        $this->assertStringContainsString('id="total-discounts-highlight"', $page);
        $this->assertStringContainsString('id="sum-gross-sales"', $page);
        $this->assertStringContainsString('id="sum-net-sales"', $page);
        $this->assertStringContainsString('id="sum-munch-sales"', $page);
        $this->assertStringContainsString('id="sum-marketplace-sales"', $page);
        $this->assertStringContainsString('id="sum-tax"', $page);
        $this->assertStringContainsString('id="sum-delivery-fees"', $page);
        $this->assertStringContainsString('id="sum-total-sales"', $page);
        $this->assertStringContainsString('id="pay-cash"', $page);
        $this->assertStringContainsString('id="pay-paystack"', $page);
        $this->assertStringContainsString("data.payment_totals.paystack", $page);
        $this->assertStringContainsString('munch-sale-report__card', $page);
        $this->assertStringContainsString("data.summary.total_discounts", $page);
        $this->assertStringContainsString("data.summary.munch_sales", $page);
        $this->assertStringContainsString("data.summary.marketplace_sales", $page);
        $this->assertStringContainsString("data.order_sum", $page);
        $this->assertStringContainsString("data.payment_totals.cash", $page);

        $this->assertStringContainsString('Total Discounts', $table);
        $this->assertStringContainsString('Munch Sales', $table);
        $this->assertStringContainsString('Marketplace Sales', $table);
        $this->assertStringContainsString('footer: true', $table);
        $this->assertStringContainsString('Total Discounts', $pdf);
        $this->assertStringContainsString('Munch Sales', $pdf);
        $this->assertStringContainsString('Marketplace Sales', $pdf);
    }
}
