<?php

namespace App\Observers;

use App\CentralLogics\AbandonedCheckoutService;
use App\Model\Order;
use App\Services\AdminDashboardSalesKpiPublisher;

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
    }
}
