<?php

namespace App\Services\Paystack;

/**
 * Paystack transaction.status values as used by verify/webhook/reconcile.
 */
class PaystackGatewayStatus
{
    /**
     * Customer is still in checkout (or has not finished). Must not run failure hooks
     * that release loyalty holds — inline verify polls these while the popup is open.
     *
     * @return list<string>
     */
    public static function inProgressCheckoutStatuses(): array
    {
        return [
            'abandoned',
            'ongoing',
            'processing',
            'pending',
            'queued',
        ];
    }

    /**
     * @return list<string>
     */
    public static function terminalFailureStatuses(): array
    {
        return [
            'failed',
            'reversed',
        ];
    }

    public static function normalize(mixed $status): string
    {
        return strtolower(trim((string) $status));
    }

    public static function isInProgressCheckout(mixed $status): bool
    {
        return in_array(self::normalize($status), self::inProgressCheckoutStatuses(), true);
    }

    /**
     * Whether a failed verify/callback should invoke payment failure_hook (order_cancel).
     */
    public static function shouldInvokeFailureHook(string $resultStatus, mixed $gatewayStatus): bool
    {
        if ($resultStatus !== 'failed') {
            return false;
        }

        $gateway = self::normalize($gatewayStatus);
        if ($gateway === '') {
            return false;
        }

        if (self::isInProgressCheckout($gateway)) {
            return false;
        }

        return in_array($gateway, self::terminalFailureStatuses(), true);
    }
}
