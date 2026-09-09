<?php

namespace App\Support;

use App\CentralLogics\Helpers;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Display-layer timezone conversion only.
 *
 * Order instants (created_at, placed_at, dispatched_at) are stored as UTC.
 */
final class TimezoneDisplay
{
    public const FALLBACK = 'Africa/Nairobi';

    private static ?string $cachedBusinessTimezone = null;

    public static function businessTimezone(): string
    {
        if (self::$cachedBusinessTimezone !== null) {
            return self::$cachedBusinessTimezone;
        }

        try {
            $tz = Helpers::get_business_settings('time_zone');
            if (is_string($tz) && $tz !== '' && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                return self::$cachedBusinessTimezone = $tz;
            }
        } catch (\Throwable) {
            //
        }

        return self::$cachedBusinessTimezone = self::FALLBACK;
    }

    public static function resetCache(): void
    {
        self::$cachedBusinessTimezone = null;
    }

    public static function parseStoredUtc(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->copy()->utc();
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/[Zz]|[+-]\d{2}:?\d{2}$/', $raw)) {
            return Carbon::parse($raw)->utc();
        }

        return Carbon::parse($raw, 'UTC');
    }
}
