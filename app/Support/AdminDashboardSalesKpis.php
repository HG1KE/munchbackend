<?php

namespace App\Support;

use App\CentralLogics\Helpers;
use App\Events\AdminDashboardSaleRecorded;
use App\Model\Order;
use App\Services\PosOrderCancellationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Admin Dashboard executive sales KPIs.
 *
 * Classification is the POS-family contract in {@see PosOrderTypes}:
 * Dine In / Take Away / POS Delivery → Munch Sales.
 * Glovo / Uber / Bolt Food → marketplace KPIs.
 */
class AdminDashboardSalesKpis
{
    public const MUNCH_METHODS = ['cash', 'card', 'mpesa'];

    public const MUNCH_PAYMENT_METHODS = ['cash', 'card', 'mpesa', PosOrderTypes::PAYSTACK];

    public const MARKETPLACE_CHANNELS = ['glovo', 'uber', 'bolt_food'];

    /**
     * Voided / invalid statuses. Confirmed paid POS Dine In and POS Delivery
     * remain qualifying sales.
     *
     * @var list<string>
     */
    public const EXCLUDED_STATUSES = ['canceled', 'cancelled', 'failed', 'returned', 'refunded'];

    /**
     * @return array{from: Carbon, to: Carbon}
     */
    public static function period(string $timeframe, ?string $from = null, ?string $to = null, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now())->copy();

        return match ($timeframe) {
            'yesterday' => [
                'from' => $now->copy()->subDay()->startOfDay(),
                'to' => $now->copy()->subDay()->endOfDay(),
            ],
            'last_7_days' => [
                'from' => $now->copy()->subDays(6)->startOfDay(),
                'to' => $now->copy()->endOfDay(),
            ],
            'this_week' => [
                'from' => $now->copy()->startOfWeek()->startOfDay(),
                'to' => $now->copy()->endOfWeek()->endOfDay(),
            ],
            'last_week' => [
                'from' => $now->copy()->subWeek()->startOfWeek()->startOfDay(),
                'to' => $now->copy()->subWeek()->endOfWeek()->endOfDay(),
            ],
            'this_month' => [
                'from' => $now->copy()->startOfMonth()->startOfDay(),
                'to' => $now->copy()->endOfMonth()->endOfDay(),
            ],
            'last_month' => [
                'from' => $now->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay(),
                'to' => $now->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay(),
            ],
            'custom' => self::customPeriod($from, $to, $now),
            default => [
                'from' => $now->copy()->startOfDay(),
                'to' => $now->copy()->endOfDay(),
            ],
        };
    }

    /**
     * @param  Builder<\App\Model\Order>  $query
     * @return Builder<\App\Model\Order>
     */
    public static function constrainQualifying(Builder $query): Builder
    {
        $query->pos()
            ->where('payment_status', 'paid')
            ->whereNotIn('order_status', self::EXCLUDED_STATUSES);

        if (Schema::hasColumn((new Order())->getTable(), 'cancelled_at')) {
            $query->whereNull('cancelled_at');
        }

        return $query;
    }

    /**
     * @param  list<array{payment_method?: mixed, sales_channel?: mixed, order_type?: mixed, total?: mixed}>  $rows
     * @return array{
     *     cash: float,
     *     card: float,
     *     mpesa: float,
     *     paystack: float,
     *     munch_sales: float,
     *     glovo: float,
     *     uber: float,
     *     bolt_food: float
     * }
     */
    public static function fromGroupedRows(array $rows): array
    {
        $totals = [
            'cash' => 0.0,
            'card' => 0.0,
            'mpesa' => 0.0,
            'paystack' => 0.0,
            'munch_sales' => 0.0,
            'glovo' => 0.0,
            'uber' => 0.0,
            'bolt_food' => 0.0,
        ];

        foreach ($rows as $row) {
            $amount = round((float) ($row['total'] ?? 0), 2);
            if ($amount == 0.0) {
                continue;
            }

            $bucket = self::category(
                $row['payment_method'] ?? null,
                $row['sales_channel'] ?? null,
                $row['order_type'] ?? null
            );
            if ($bucket === 'other') {
                continue;
            }

            if ($bucket !== 'munch') {
                $totals[$bucket] += $amount;
                continue;
            }

            $totals['munch_sales'] += $amount;
            $method = (string) ($row['payment_method'] ?? '');
            if (in_array($method, self::MUNCH_METHODS, true) || $method === PosOrderTypes::PAYSTACK) {
                $totals[$method] += $amount;
            }
        }

        return $totals;
    }

    /**
     * @param  array{
     *     cash: float,
     *     card: float,
     *     mpesa: float,
     *     munch_sales: float,
     *     glovo: float,
     *     uber: float,
     *     bolt_food: float
     * }  $totals
     * @param  array{from: Carbon, to: Carbon}  $period
     * @return array<string, mixed>
     */
    public static function present(array $totals, array $period, string $timeframe = 'today', int|string|null $branchId = null): array
    {
        $formatted = [];
        foreach (['munch_sales', 'cash', 'card', 'mpesa', 'glovo', 'uber', 'bolt_food'] as $key) {
            $formatted[$key] = Helpers::set_symbol((float) ($totals[$key] ?? 0));
        }

        $formatted['timeframe'] = $timeframe === '' ? 'today' : $timeframe;
        $formatted['branch_id'] = $branchId === null || $branchId === '' ? 'all' : $branchId;
        $formatted['from'] = $period['from']->toDateString();
        $formatted['to'] = $period['to']->toDateString();

        return $formatted;
    }

    /**
     * @return array{from: Carbon, to: Carbon}
     */
    private static function customPeriod(?string $from, ?string $to, Carbon $now): array
    {
        $start = $from ? Carbon::parse($from)->startOfDay() : $now->copy()->startOfDay();
        $end = $to ? Carbon::parse($to)->endOfDay() : $now->copy()->endOfDay();
        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return ['from' => $start, 'to' => $end];
    }

    /**
     * Status is not cancelled / failed / returned / refunded.
     * Confirmed and delivered both qualify.
     */
    public static function qualifiesStatus(mixed $status): bool
    {
        $status = (string) $status;

        return $status !== '' && ! in_array($status, self::EXCLUDED_STATUSES, true);
    }

    public static function qualifies(Order $order): bool
    {
        return self::qualifiesAttributes(
            $order->order_type ?? null,
            $order->sales_channel ?? null,
            $order->order_status ?? null,
            $order->payment_status ?? null,
            $order->payment_method ?? null,
            $order->cancelled_at ?? null
        );
    }

    public static function qualifiesOriginal(Order $order): bool
    {
        return self::qualifiesAttributes(
            $order->getOriginal('order_type') ?? $order->order_type ?? null,
            $order->getOriginal('sales_channel') ?? $order->sales_channel ?? null,
            $order->getOriginal('order_status'),
            $order->getOriginal('payment_status') ?? $order->payment_status ?? null,
            $order->getOriginal('payment_method') ?? $order->payment_method ?? null,
            $order->getOriginal('cancelled_at')
        );
    }

    public static function qualifiesAttributes(
        mixed $orderType,
        mixed $salesChannel,
        mixed $status,
        mixed $paymentStatus,
        mixed $paymentMethod,
        mixed $cancelledAt = null
    ): bool {
        if (! PosOrderTypes::isPosFamily(
            $orderType !== null ? (string) $orderType : null,
            $salesChannel !== null ? (string) $salesChannel : null
        )) {
            return false;
        }

        if (self::category($paymentMethod, $salesChannel, $orderType) === 'other') {
            return false;
        }

        if (! self::qualifiesStatus($status) || PosOrderCancellationService::isCancelledStatus($status)) {
            return false;
        }

        if ($cancelledAt !== null && $cancelledAt !== '') {
            return false;
        }

        return (string) $paymentStatus === 'paid';
    }

    public static function category(?string $method, ?string $channel, ?string $orderType = null): string
    {
        $saleCategory = PosOrderTypes::saleReportCategory($orderType, $channel);
        if (in_array((string) $saleCategory, self::MARKETPLACE_CHANNELS, true)) {
            return (string) $saleCategory;
        }

        if (in_array((string) $saleCategory, AdminSaleReportExport::MUNCH_CATEGORIES, true)) {
            return 'munch';
        }

        return 'other';
    }

    public static function fingerprint(Order $order): string
    {
        $created = $order->created_at;
        $createdStamp = $created instanceof Carbon ? $created->timestamp : (string) $created;

        return implode('|', [
            (string) $order->id,
            (string) $order->order_status,
            (string) $order->payment_status,
            (string) $order->branch_id,
            number_format((float) $order->order_amount, 2, '.', ''),
            (string) $order->payment_method,
            (string) $order->sales_channel,
            (string) $order->order_type,
            (string) $createdStamp,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function realtimePayload(Order $order): array
    {
        $qualifies = self::qualifies($order);
        $created = $order->created_at instanceof Carbon
            ? $order->created_at
            : Carbon::parse($order->created_at ?? Carbon::now());

        return [
            'event' => AdminDashboardSaleRecorded::NAME,
            'channel' => AdminDashboardSaleRecorded::CHANNEL,
            'order_id' => (int) $order->id,
            'branch_id' => (int) $order->branch_id,
            'created_at' => $created->format('Y-m-d H:i:s'),
            'date' => $created->toDateString(),
            'order_status' => (string) $order->order_status,
            'payment_method' => (string) $order->payment_method,
            'payment_status' => (string) $order->payment_status,
            'sales_channel' => (string) $order->sales_channel,
            'order_type' => (string) $order->order_type,
            'category' => $qualifies
                ? self::category($order->payment_method, $order->sales_channel, $order->order_type)
                : 'removed',
            'qualifies' => $qualifies,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function eventMatchesFilters(
        array $event,
        int|string|null $branchId,
        string $timeframe,
        ?string $from = null,
        ?string $to = null,
        ?Carbon $now = null
    ): bool {
        $selectedBranch = ($branchId === null || $branchId === '' || $branchId === 'all') ? null : (int) $branchId;
        if ($selectedBranch !== null && (int) ($event['branch_id'] ?? 0) !== $selectedBranch) {
            return false;
        }

        if (empty($event['created_at'])) {
            return true;
        }

        $created = Carbon::parse($event['created_at']);
        $period = self::period($timeframe !== '' ? $timeframe : 'today', $from, $to, $now);

        return $created->betweenIncluded($period['from'], $period['to']);
    }
}
