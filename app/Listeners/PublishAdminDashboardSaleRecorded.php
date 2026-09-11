<?php

namespace App\Listeners;

use App\Events\AdminDashboardSaleRecorded;
use App\Services\AdminDashboardSalesKpiBus;

class PublishAdminDashboardSaleRecorded
{
    public function handle(AdminDashboardSaleRecorded $event): void
    {
        AdminDashboardSalesKpiBus::publish($event->sale);
    }
}
