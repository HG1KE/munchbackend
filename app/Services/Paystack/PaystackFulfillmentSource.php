<?php

namespace App\Services\Paystack;

/**
 * Identifies which entry point triggered Paystack post-payment fulfillment.
 */
final class PaystackFulfillmentSource
{
    public const BROWSER_VERIFY = 'browser_verify';

    public const WEBHOOK = 'webhook';

    public const RECONCILIATION = 'reconciliation';

    public const GATEWAY_CALLBACK = 'gateway_callback';

    /** One-tap charge of a previously saved reusable authorization. */
    public const SAVED_CARD_CHARGE = 'saved_card_charge';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::BROWSER_VERIFY,
            self::WEBHOOK,
            self::RECONCILIATION,
            self::GATEWAY_CALLBACK,
            self::SAVED_CARD_CHARGE,
        ];
    }
}
