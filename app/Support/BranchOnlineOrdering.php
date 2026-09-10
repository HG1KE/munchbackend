<?php

namespace App\Support;

use App\Model\Branch;
use Illuminate\Support\Facades\Schema;

/**
 * Branch dashboard Online Orders UI/alerts. Does not affect customer checkout.
 */
class BranchOnlineOrdering
{
    public static function isEnabled(?Branch $branch): bool
    {
        if ($branch === null) {
            return true;
        }
        if (! Schema::hasColumn($branch->getTable(), 'online_orders_enabled')) {
            return true;
        }
        $value = $branch->getAttribute('online_orders_enabled');
        if ($value === null) {
            return true;
        }

        return (int) $value === 1;
    }

    /**
     * @return array{new_order: int}
     */
    public static function silencedAlertPayload(): array
    {
        return [
            'new_order' => 0,
        ];
    }
}
