<?php

namespace App\Support;

use App\CentralLogics\Helpers;

/**
 * Global business setting: sole on/off for whether Branch POS may poll/ring/ACCEPT online orders.
 *
 * Default off (missing key → disabled) so Habib can enable after deploy.
 * Branch POS receive does not consult {@see BranchOnlineOrdering}; that switch only gates
 * non-POS branch dashboard online-order notifications.
 */
class PosOnlineOrderReceiveSettings
{
    public const KEY = 'pos_online_order_receive_status';

    public static function isEnabled(): bool
    {
        return (int) (Helpers::get_business_settings(self::KEY) ?? 0) === 1;
    }
}
