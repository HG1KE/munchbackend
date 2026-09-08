<?php

namespace App\Observers;

use App\CentralLogics\AbandonedCheckoutService;
use App\Model\Order;

class OrderObserver
{
    public function created(Order $order): void
    {
        AbandonedCheckoutService::linkOrderConversion($order);
    }
}
