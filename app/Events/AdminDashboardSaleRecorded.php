<?php

namespace App\Events;

/**
 * Fired when an order reaches (or leaves) the Admin Dashboard KPI earning set.
 * Transport is the admin long-poll channel, not Pusher/Reverb.
 */
class AdminDashboardSaleRecorded
{
    public const NAME = 'admin.dashboard.sale-recorded';

    public const CHANNEL = 'admin.dashboard.sales-kpis';

    /**
     * @param  array<string, mixed>  $sale
     */
    public function __construct(public array $sale)
    {
    }
}
