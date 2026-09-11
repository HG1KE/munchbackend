<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * In-process realtime bus for Admin Dashboard sales KPIs.
 * Production cache is Redis; tests use the array driver.
 */
class AdminDashboardSalesKpiBus
{
    public const VERSION_KEY = 'admin_dashboard_sales_kpis:version';

    public const EVENTS_KEY = 'admin_dashboard_sales_kpis:events';

    public const MAX_EVENTS = 100;

    /**
     * @param  array<string, mixed>  $sale
     * @return array<string, mixed>
     */
    public static function publish(array $sale): array
    {
        $version = (int) Cache::increment(self::VERSION_KEY);
        $event = $sale;
        $event['version'] = $version;

        $events = self::storedEvents();
        array_unshift($events, $event);
        $events = array_slice($events, 0, self::MAX_EVENTS);
        Cache::put(self::EVENTS_KEY, $events, now()->addDay());

        return $event;
    }

    public static function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function since(int $version): array
    {
        $newer = [];
        foreach (array_reverse(self::storedEvents()) as $event) {
            if ((int) ($event['version'] ?? 0) > $version) {
                $newer[] = $event;
            }
        }

        return $newer;
    }

    /**
     * @return array{version: int, events: list<array<string, mixed>>, channel: string, connected: bool}
     */
    public static function snapshot(int $since): array
    {
        return [
            'version' => self::version(),
            'events' => self::since($since),
            'channel' => \App\Events\AdminDashboardSaleRecorded::CHANNEL,
            'connected' => true,
        ];
    }

    /**
     * Long-poll until a newer sale event exists or the wait expires.
     *
     * @return array{version: int, events: list<array<string, mixed>>, channel: string, connected: bool}
     */
    public static function waitForEvents(int $since, float $waitSeconds = 20): array
    {
        $snapshot = self::snapshot($since);
        if ($snapshot['events'] !== [] || $waitSeconds <= 0) {
            return $snapshot;
        }

        $deadline = microtime(true) + $waitSeconds;
        while (microtime(true) < $deadline) {
            if (connection_aborted()) {
                break;
            }
            usleep(200000);
            $snapshot = self::snapshot($since);
            if ($snapshot['events'] !== []) {
                return $snapshot;
            }
        }

        return self::snapshot($since);
    }

    public static function flush(): void
    {
        Cache::forget(self::VERSION_KEY);
        Cache::forget(self::EVENTS_KEY);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function storedEvents(): array
    {
        $events = Cache::get(self::EVENTS_KEY, []);

        return is_array($events) ? $events : [];
    }
}
