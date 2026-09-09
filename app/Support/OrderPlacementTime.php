<?php

namespace App\Support;

use App\Model\Order;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Online Orders SLA placement instant — stored and compared as UTC unix only.
 * Copied from Meatco OrderPlacementTime; scheduled-express activation omitted
 * because Munch has no customer-app scheduled express contract.
 */
class OrderPlacementTime
{
    public const SLA_DELAY_SECONDS = 1800;

    public const SLA_ESCALATED_SECONDS = 2700;

    public const SLA_CRITICAL_SECONDS = 3600;

    private static ?bool $hasPlacedAtColumn = null;

    public static function displayTimezone(): string
    {
        return TimezoneDisplay::businessTimezone();
    }

    public static function hasPlacedAtColumn(string $table = 'orders'): bool
    {
        if (self::$hasPlacedAtColumn !== null) {
            return self::$hasPlacedAtColumn;
        }

        try {
            self::$hasPlacedAtColumn = Schema::hasColumn($table, 'placed_at');
        } catch (\Throwable $e) {
            Log::warning('order_placed_at_column_check_failed', ['message' => $e->getMessage()]);
            self::$hasPlacedAtColumn = false;
        }

        return self::$hasPlacedAtColumn;
    }

    public static function resetColumnCache(): void
    {
        self::$hasPlacedAtColumn = null;
    }

    public static function utcNow(): CarbonInterface
    {
        return Carbon::now('UTC');
    }

    public static function unixFromRaw(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return (int) Carbon::parse(trim($raw), 'UTC')->getTimestamp();
    }

    /**
     * @return array<string, string>
     */
    public static function insertAttributes(?CarbonInterface $placedAt = null, string $table = 'orders'): array
    {
        try {
            if (! self::hasPlacedAtColumn($table)) {
                return [];
            }

            $placedAt = ($placedAt ?? self::utcNow())->utc();

            return [
                'placed_at' => $placedAt->format('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            Log::error('order_placed_at_insert_attributes_failed', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public static function applyToOrder(Order $order, ?CarbonInterface $placedAt = null): void
    {
        try {
            if (! self::hasPlacedAtColumn($order->getTable())) {
                return;
            }

            $rawPlaced = $order->getRawOriginal('placed_at');
            if ($rawPlaced !== null && trim((string) $rawPlaced) !== '') {
                return;
            }

            $utcString = ($placedAt ?? self::utcNow())->utc()->format('Y-m-d H:i:s');
            $order->setRawAttributes(
                array_merge($order->getAttributes(), ['placed_at' => $utcString]),
                true
            );
        } catch (\Throwable $e) {
            Log::error('order_placed_at_apply_failed', [
                'order_id' => $order->id ?? null,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public static function operationalUnix(Order $order): int
    {
        return self::unix($order);
    }

    public static function operationalElapsedSeconds(Order $order, ?int $nowUnix = null): int
    {
        $nowUnix = $nowUnix ?? time();

        return max(0, $nowUnix - self::operationalUnix($order));
    }

    public static function unix(Order $order): int
    {
        try {
            if (self::hasPlacedAtColumn($order->getTable())) {
                $placedUnix = self::unixFromRaw($order->getRawOriginal('placed_at'));
                if ($placedUnix !== null) {
                    return $placedUnix;
                }
            }

            $createdUnix = self::unixFromRaw($order->getRawOriginal('created_at'));
            if ($createdUnix !== null) {
                return $createdUnix;
            }

            return time();
        } catch (\Throwable $e) {
            Log::warning('order_placed_at_unix_failed', [
                'order_id' => $order->id ?? null,
                'message' => $e->getMessage(),
            ]);

            return time();
        }
    }

    public static function iso8601ForDisplay(Order $order): string
    {
        return Carbon::createFromTimestamp(self::unix($order), 'UTC')
            ->timezone(self::displayTimezone())
            ->toIso8601String();
    }

    public static function elapsedSeconds(Order $order, ?int $nowUnix = null): int
    {
        $nowUnix = $nowUnix ?? time();

        return max(0, $nowUnix - self::unix($order));
    }

    public static function slaTier(int $elapsedSeconds): string
    {
        if ($elapsedSeconds >= self::SLA_CRITICAL_SECONDS) {
            return 'critical';
        }
        if ($elapsedSeconds >= self::SLA_ESCALATED_SECONDS) {
            return 'escalated';
        }
        if ($elapsedSeconds >= self::SLA_DELAY_SECONDS) {
            return 'delay';
        }

        return 'normal';
    }

    public static function slaCardClass(int $elapsedSeconds): string
    {
        return match (self::slaTier($elapsedSeconds)) {
            'critical' => 'meatco-express-card--sla-critical',
            'escalated' => 'meatco-express-card--sla-escalated',
            'delay' => 'meatco-express-card--sla-delay',
            default => '',
        };
    }

    public static function slaTimerClass(int $elapsedSeconds): string
    {
        return match (self::slaTier($elapsedSeconds)) {
            'critical' => 'meatco-express-card__timer--sla-critical',
            'escalated' => 'meatco-express-card__timer--sla-escalated',
            'delay' => 'meatco-express-card__timer--sla-delay',
            default => '',
        };
    }

    /**
     * @return array{placed_at_unix: int, placed_at_iso: string, created_at_iso: string, elapsed_seconds: int, sla_tier: string, dispatched_at_unix: ?int, timer_frozen: bool, elapsed_display: string}
     */
    public static function expressFrozenDispatchTimerPayload(Order $order): array
    {
        $placedAtUnix = self::operationalUnix($order);
        $dispatchedAtUnix = OrderDispatchedTime::unix($order) ?? time();
        $elapsed = max(0, $dispatchedAtUnix - $placedAtUnix);
        $displayIso = Carbon::createFromTimestamp($placedAtUnix, 'UTC')
            ->timezone(self::displayTimezone())
            ->toIso8601String();

        return [
            'placed_at_unix' => $placedAtUnix,
            'placed_at_iso' => $displayIso,
            'created_at_iso' => $displayIso,
            'elapsed_seconds' => $elapsed,
            'sla_tier' => self::slaTier($elapsed),
            'dispatched_at_unix' => $dispatchedAtUnix,
            'timer_frozen' => true,
            'elapsed_display' => OrderDispatchedTime::formatElapsedDisplay($elapsed),
        ];
    }

    /**
     * @return array{placed_at_unix: int, placed_at_iso: string, created_at_iso: string, elapsed_seconds: int, sla_tier: string, timer_frozen: bool, elapsed_display: ?string, dispatched_at_unix: ?int}
     */
    public static function expressTimerPayload(Order $order): array
    {
        try {
            $placedAtUnix = self::operationalUnix($order);
            $elapsed = self::operationalElapsedSeconds($order);
            $displayIso = Carbon::createFromTimestamp($placedAtUnix, 'UTC')
                ->timezone(self::displayTimezone())
                ->toIso8601String();

            return [
                'placed_at_unix' => $placedAtUnix,
                'placed_at_iso' => $displayIso,
                'created_at_iso' => $displayIso,
                'elapsed_seconds' => $elapsed,
                'sla_tier' => self::slaTier($elapsed),
                'timer_frozen' => false,
                'elapsed_display' => null,
                'dispatched_at_unix' => null,
            ];
        } catch (\Throwable $e) {
            Log::warning('order_express_timer_payload_failed', [
                'order_id' => $order->id ?? null,
                'message' => $e->getMessage(),
            ]);

            $fallbackUnix = self::unixFromRaw($order->getRawOriginal('created_at')) ?? time();

            return [
                'placed_at_unix' => $fallbackUnix,
                'placed_at_iso' => Carbon::createFromTimestamp($fallbackUnix, 'UTC')
                    ->timezone(self::displayTimezone())
                    ->toIso8601String(),
                'created_at_iso' => Carbon::createFromTimestamp($fallbackUnix, 'UTC')
                    ->timezone(self::displayTimezone())
                    ->toIso8601String(),
                'elapsed_seconds' => 0,
                'sla_tier' => 'normal',
                'timer_frozen' => false,
                'elapsed_display' => null,
                'dispatched_at_unix' => null,
            ];
        }
    }
}
