<?php

namespace App\Support;

/**
 * Operational queue mapping for Online Orders (Meatco Express board).
 *
 * Munch workflow: pending → processing → out_for_delivery → delivered
 * Board sections: Pending / Preparing (`processing`) / Dispatched
 * confirmed is legacy/offline only and stays in Pending.
 */
class OnlineOrderStatus
{
    public const PENDING = 'pending';

    public const PACKING = 'processing';

    public const OUT_FOR_DELIVERY = 'out_for_delivery';

    public const DELIVERED = 'delivered';

    public const CANCELED = 'canceled';

    public const RETURNED = 'returned';

    public const FAILED = 'failed';

    /**
     * @return list<string>
     */
    public static function pendingQueueStatuses(): array
    {
        return ['pending', 'confirmed'];
    }

    /**
     * @return list<string>
     */
    public static function packingQueueStatuses(): array
    {
        return ['processing', 'packing'];
    }

    /**
     * @return list<string>
     */
    public static function dispatchedQueueStatuses(): array
    {
        return [self::OUT_FOR_DELIVERY];
    }

    /**
     * @return list<string>
     */
    public static function terminalStatuses(): array
    {
        return [
            self::DELIVERED,
            self::CANCELED,
            self::RETURNED,
            self::FAILED,
            'completed',
        ];
    }

    public static function isTerminal(?string $status): bool
    {
        return in_array((string) $status, self::terminalStatuses(), true);
    }

    public static function labelKey(?string $status): string
    {
        return match ((string) $status) {
            'pending' => 'pending',
            'confirmed' => 'confirmed',
            'processing', 'packing' => 'processing',
            'out_for_delivery' => 'out_for_delivery',
            'delivered' => 'delivered',
            'canceled' => 'canceled',
            'returned' => 'returned',
            'failed' => 'failed_to_deliver',
            default => (string) $status,
        };
    }
}
