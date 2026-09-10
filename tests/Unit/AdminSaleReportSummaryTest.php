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
        $this->assertStringContainsString("input('payment_method', 'all')", $controller);

        $this->assertStringContainsString('id="sum-total-discounts"', $page);
        $this->assertStringContainsString('id="total-discounts-highlight"', $page);
        $this->assertStringContainsString('id="sum-gross-sales"', $page);
        $this->assertStringContainsString('id="sum-net-sales"', $page);
        $this->assertStringContainsString('id="sum-tax"', $page);
        $this->assertStringContainsString('id="sum-delivery-fees"', $page);
        $this->assertStringContainsString('id="sum-total-sales"', $page);
        $this->assertStringContainsString("data.summary.total_discounts", $page);
        $this->assertStringContainsString("data.order_sum", $page);
        $this->assertStringContainsString("data.payment_totals.cash", $page);

        $this->assertStringContainsString('Total Discounts', $table);
        $this->assertStringContainsString('footer: true', $table);
        $this->assertStringContainsString('Total Discounts', $pdf);
    }
}
