<?php

namespace App\Observers;

use App\CentralLogics\AbandonedCheckoutService;
use App\Model\Order;
use App\Services\AdminDashboardSalesKpiPublisher;
use App\Services\Stock\StockDeductionService;

class OrderObserver
{
    public function created(Order $order): void
    {
        AbandonedCheckoutService::linkOrderConversion($order);
    }

    public function saved(Order $order): void
    {
        try {
            AdminDashboardSalesKpiPublisher::publish($order);
        } catch (\Throwable) {
            // Never block order persistence from dashboard realtime.
        }

        try {
            app(StockDeductionService::class)->syncOrder($order);
        } catch (\Throwable) {
            // Never block order persistence from stock ledger sync.
        }
    }

    public function deleted(Order $order): void
    {
        try {
            $order->order_status = 'canceled';
            app(StockDeductionService::class)->syncOrder($order);
        } catch (\Throwable) {
            // Never block order deletion from stock ledger sync.
        }
    }
}
