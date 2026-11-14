<?php

namespace Tests\Unit;

use App\Support\AdminSaleReportExport;
use App\Support\AdminSaleReportSummary;
use App\Support\OrderPayableAmount;
use Tests\TestCase;

/**
 * Habib: Admin Sale Report Munch Sales = payable for every Munch Sales line
 * (cashier Branch POS delivery and online-accepted POS alike).
 */
class AdminSaleReportMunchSalesPayableTest extends TestCase
{
    public function test_kilimani_13_nov_online_accepted_munch_sales_is_payable_1930(): void
    {
        // docs/kilimani-pos-online-13-nov.md — merch 1650, payable 1930.
        $orders = [
            [
                'id' => 24734,
                'readable_order_id' => 'A24734',
                'order_type' => 'pos',
                'sales_channel' => 'delivery',
                'payment_method' => 'palpluss',
                'order_status' => 'confirmed',
                'order_amount' => 390,
                'delivery_charge' => 100,
                'placed_at' => '2026-11-13 08:00:00',
                'created_at' => '2026-11-13 11:00:00',
            ],
            [
                'id' => 24807,
                'readable_order_id' => 'A24807',
                'order_type' => 'pos',
                'sales_channel' => 'delivery',
                'payment_method' => 'palpluss',
                'order_status' => 'confirmed',
                'order_amount' => 400,
                'delivery_charge' => 180,
                'placed_at' => '2026-11-13 12:00:00',
                'created_at' => '2026-11-13 15:00:00',
            ],
            [
                'id' => 24922,
                'readable_order_id' => 'A24922',
                'order_type' => 'pos',
                'sales_channel' => 'takeaway',
                'payment_method' => 'palpluss',
                'order_status' => 'confirmed',
                'order_amount' => 860,
                'delivery_charge' => 0,
                'placed_at' => '2026-11-13 14:00:00',
                'created_at' => '2026-11-13 17:00:00',
            ],
        ];

        $merch = 0.0;
        $payable = 0.0;
        foreach ($orders as $order) {
            $merch += (float) $order['order_amount'];
            $payable += OrderPayableAmount::payable($order);
        }
        $this->assertSame(1650.0, $merch);
        $this->assertSame(1930.0, $payable);

        $report = AdminSaleReportExport::build($orders, [
            'branch_name' => 'Munch Kilimani',
            'from' => '2026-11-13',
            'to' => '2026-11-13',
        ]);

        $this->assertSame(1930.0, $report['totals']['munch_sales']);
        $this->assertSame(3, $report['order_counts']['munch_sales']);
        $this->assertSame(490.0, $report['sections']['munch_sales']['categories']['delivery']['orders'][0]['amount']);
        $this->assertSame(580.0, $report['sections']['munch_sales']['categories']['delivery']['orders'][1]['amount']);
        $this->assertSame(860.0, $report['sections']['munch_sales']['categories']['takeaway']['orders'][0]['amount']);
    }

    public function test_cashier_pos_delivery_and_online_accepted_both_use_payable_in_munch_sales(): void
    {
        $orders = [
            // Cashier-keyed Branch POS delivery (post-4ea2ffc merch-only order_amount).
            [
                'id' => 1,
                'readable_order_id' => 'A1',
                'order_type' => 'pos',
                'sales_channel' => 'delivery',
                'payment_method' => 'mpesa',
                'order_status' => 'delivered',
                'order_amount' => 390,
                'delivery_charge' => 50,
                'placed_at' => '2026-11-13 09:00:00',
                'created_at' => '2026-11-13 12:00:00',
            ],
            // Online accepted into POS delivery.
            [
                'id' => 2,
                'readable_order_id' => 'A2',
                'order_type' => 'pos',
                'sales_channel' => 'delivery',
                'payment_method' => 'paystack',
                'order_status' => 'confirmed',
                'order_amount' => 400,
                'delivery_charge' => 180,
                'placed_at' => '2026-11-13 10:00:00',
                'created_at' => '2026-11-13 13:00:00',
            ],
            // Dine-in (no fee).
            [
                'id' => 3,
                'readable_order_id' => 'A3',
                'order_type' => 'dine_in',
                'sales_channel' => 'dine_in',
                'payment_method' => 'cash',
                'order_status' => 'delivered',
                'order_amount' => 1000,
                'delivery_charge' => 0,
                'placed_at' => '2026-11-13 11:00:00',
                'created_at' => '2026-11-13 14:00:00',
            ],
        ];

        $report = AdminSaleReportExport::build($orders, [
            'branch_name' => 'Munch',
            'from' => '2026-11-13',
            'to' => '2026-11-13',
            'payment_totals' => [
                'cash' => OrderPayableAmount::payable($orders[2]),
                'card' => 0,
                'mpesa' => OrderPayableAmount::payable($orders[0]),
                'paystack' => OrderPayableAmount::payable($orders[1]),
                'glovo' => 0,
                'uber' => 0,
                'bolt_food' => 0,
            ],
        ]);

        // 440 + 580 + 1000
        $this->assertSame(2020.0, $report['totals']['munch_sales']);
        $this->assertSame(
            2020.0,
            AdminSaleReportSummary::fromPaymentTotals($report['payment_totals'])['munch_sales']
        );

        $listing = AdminSaleReportExport::listingRows($orders);
        $this->assertSame(440.0, $listing[0]['price']);
        $this->assertSame(580.0, $listing[1]['price']);
        $this->assertSame(1000.0, $listing[2]['price']);
    }

    public function test_sale_report_and_dashboard_wire_order_payable_amount(): void
    {
        $reportController = file_get_contents(app_path('Http/Controllers/Admin/ReportController.php'));
        $export = file_get_contents(app_path('Support/AdminSaleReportExport.php'));
        $kpiService = file_get_contents(app_path('Services/AdminDashboardSalesKpiService.php'));
        $payable = file_get_contents(app_path('Support/OrderPayableAmount.php'));

        $this->assertStringContainsString('OrderPayableAmount::payable($order)', $reportController);
        $this->assertStringContainsString("exportReport['totals']['munch_sales']", $reportController);
        $this->assertStringContainsString('OrderPayableAmount::payable(', $export);
        $this->assertStringContainsString('OrderPayableAmount::sqlExpression()', $kpiService);
        $this->assertStringContainsString('function sqlExpression', $payable);

        $this->assertSame(
            "COALESCE(order_amount, 0) + CASE WHEN order_type = 'take_away' THEN 0 ELSE COALESCE(delivery_charge, 0) END",
            OrderPayableAmount::sqlExpression()
        );
    }
}
