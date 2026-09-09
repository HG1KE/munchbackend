<?php

namespace App\Support;

use App\Model\Order;

class OrderViewBootstrap
{
    /**
     * @return array{timer_frozen: bool, elapsed_seconds: int, elapsed_display: ?string, timer_state: string, show_timer: bool}
     */
    public static function expressTimerContext(Order $order): array
    {
        $defaults = [
            'timer_frozen' => false,
            'elapsed_seconds' => 0,
            'elapsed_display' => null,
            'timer_state' => 'live',
            'show_timer' => true,
        ];

        try {
            if (in_array((string) $order->order_type, ['pos', 'dine_in'], true)) {
                return array_merge($defaults, ['timer_state' => 'hidden', 'show_timer' => false]);
            }

            $status = (string) $order->order_status;

            if ($status === OnlineOrderStatus::OUT_FOR_DELIVERY) {
                $payload = OrderPlacementTime::expressFrozenDispatchTimerPayload($order);

                return [
                    'timer_frozen' => true,
                    'elapsed_seconds' => (int) ($payload['elapsed_seconds'] ?? 0),
                    'elapsed_display' => $payload['elapsed_display'] ?? null,
                    'timer_state' => 'dispatched',
                    'show_timer' => true,
                ];
            }

            if ($status === OnlineOrderStatus::DELIVERED || $status === 'completed') {
                $deliveredUnix = OrderPlacementTime::unixFromRaw($order->getRawOriginal('updated_at'));
                if ($deliveredUnix === null) {
                    return array_merge($defaults, ['timer_state' => 'hidden', 'show_timer' => false]);
                }

                $duration = max(0, $deliveredUnix - OrderPlacementTime::operationalUnix($order));

                return [
                    'timer_frozen' => true,
                    'elapsed_seconds' => $duration,
                    'elapsed_display' => OrderDispatchedTime::formatElapsedDisplay($duration),
                    'timer_state' => 'completed',
                    'show_timer' => true,
                ];
            }

            if (OnlineOrderStatus::isTerminal($status)) {
                return array_merge($defaults, ['timer_state' => 'hidden', 'show_timer' => false]);
            }

            $elapsed = OrderPlacementTime::operationalElapsedSeconds($order);

            return [
                'timer_frozen' => false,
                'elapsed_seconds' => $elapsed,
                'elapsed_display' => null,
                'timer_state' => 'live',
                'show_timer' => true,
            ];
        } catch (\Throwable) {
            return $defaults;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function expressTimerViewVars(Order $order): array
    {
        $ctx = self::expressTimerContext($order);

        return [
            'orderExpressTimerFrozen' => $ctx['timer_frozen'],
            'orderExpressElapsedSeconds' => $ctx['elapsed_seconds'],
            'orderExpressElapsedDisplay' => $ctx['elapsed_display'],
            'orderExpressTimerState' => $ctx['timer_state'],
            'orderExpressTimerVisible' => $ctx['show_timer'],
            'orderPlacedAtUnix' => OrderPlacementTime::unix($order),
        ];
    }
}
