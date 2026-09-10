<?php

namespace App\Support;

use App\CentralLogics\Helpers;
use Illuminate\Support\Facades\DB;

/**
 * Master-admin phone that receives POS order cancellation SMS.
 * Not customer-facing.
 */
class PosCancellationNotificationSettings
{
    public const KEY = 'pos_cancellation_notification_phone';

    public static function phone(): string
    {
        $value = Helpers::get_business_settings(self::KEY);
        if (is_array($value)) {
            return trim((string) ($value['phone'] ?? $value['value'] ?? ''));
        }

        return trim((string) ($value ?? ''));
    }

    public static function save(string $phone): void
    {
        $phone = trim($phone);
        DB::table('business_settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => json_encode($phone)]
        );
        Helpers::forgetBusinessSettingsRuntimeCache();
    }

    public static function validationError(?string $phone, bool $required): ?string
    {
        $phone = trim((string) $phone);
        if ($phone === '') {
            return $required
                ? 'POS Cancellation Notification Number is required when the template is enabled.'
                : null;
        }

        return PosOrderTypes::phoneDigitsError($phone);
    }
}
