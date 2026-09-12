<?php

namespace App\Services;

use App\Events\AdminDashboardSaleRecorded;
use App\Model\Order;
use App\Support\AdminDashboardSalesKpis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

class AdminDashboardSalesKpiPublisher
{
    public const FINGERPRINT_PREFIX = 'admin_dashboard_sales_kpis:fp:';

    /**
     * Publish at most once per KPI-relevant order snapshot.
     */
    public static function publish(Order $order): ?array
    {
        if (! self::shouldPublish($order)) {
            return null;
        }

        $fingerprint = AdminDashboardSalesKpis::fingerprint($order);
        $cacheKey = self::FINGERPRINT_PREFIX.$order->id;
        if (Cache::get($cacheKey) === $fingerprint) {
            return null;
        }

        $payload = AdminDashboardSalesKpis::realtimePayload($order);
        Cache::put($cacheKey, $fingerprint, now()->addDays(7));

        Event::dispatch(new AdminDashboardSaleRecorded($payload));

        return $payload;
    }

    public static function shouldPublish(Order $order): bool
    {
        $qualifies = AdminDashboardSalesKpis::qualifies($order);
        $qualifiedBefore = AdminDashboardSalesKpis::qualifiesOriginal($order);

        if ($order->wasRecentlyCreated) {
            return $qualifies;
        }

        $kpiFields = [
            'order_status',
            'payment_status',
            'order_amount',
            'branch_id',
            'payment_method',
            'sales_channel',
            'order_type',
            'created_at',
            'cancelled_at',
        ];
        $changed = $order->wasChanged($kpiFields) || $order->isDirty($kpiFields);
        if (! $changed) {
            return false;
        }

        return $qualifies || $qualifiedBefore;
    }
}
