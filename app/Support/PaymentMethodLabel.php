<?php

namespace App\Support;

class PaymentMethodLabel
{
    public const CASH_ON_DELIVERY = 'cash_on_delivery';

    public static function isCashOnDelivery(?string $method): bool
    {
        $normalized = strtolower(trim((string) $method));

        return $normalized === self::CASH_ON_DELIVERY || $normalized === 'cash';
    }

    public static function operationalTitle(?string $method): string
    {
        if ($method === null || trim($method) === '') {
            return '';
        }

        if (PosOrderTypes::isMarketplacePayment($method)) {
            return PosOrderTypes::paymentReceiptLabel($method);
        }

        if (self::isCashOnDelivery($method)) {
            return translate('Payment On Delivery');
        }

        $slug = strtolower(trim($method));
        $byKey = translate($slug);
        if ($byKey !== $slug) {
            return $byKey;
        }

        return ucwords(str_replace('_', ' ', $slug));
    }
}
