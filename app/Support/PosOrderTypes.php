<?php

namespace App\Support;

/**
 * Branch POS order-type contract (UI values vs stored orders.order_type).
 *
 * Marketplace channels (Glovo / Uber / Bolt Food) are stored as `pos` so they
 * never appear on the Online Orders board. The cashier-facing channel is stored
 * in `sales_channel`, never in `order_note`.
 */
class PosOrderTypes
{
    public const TAKE_AWAY = 'take_away';

    public const DINE_IN = 'dine_in';

    public const DELIVERY = 'delivery';

    public const HOME_DELIVERY = 'home_delivery';

    public const GLOVO = 'glovo';

    public const UBER = 'uber';

    public const BOLT_FOOD = 'bolt_food';

    /**
     * @return list<string>
     */
    public static function uiTypes(): array
    {
        return [
            self::DELIVERY,
            self::TAKE_AWAY,
            self::DINE_IN,
            self::GLOVO,
            self::UBER,
            self::BOLT_FOOD,
        ];
    }

    public static function normalize(?string $type): string
    {
        $type = (string) $type;
        if ($type === self::HOME_DELIVERY) {
            return self::DELIVERY;
        }
        if (in_array($type, self::uiTypes(), true)) {
            return $type;
        }

        return self::TAKE_AWAY;
    }

    public static function isDelivery(?string $type): bool
    {
        $type = self::normalize($type);

        return $type === self::DELIVERY;
    }

    public static function isDineIn(?string $type): bool
    {
        return self::normalize($type) === self::DINE_IN;
    }

    public static function isMarketplace(?string $type): bool
    {
        return in_array(self::normalize($type), [self::GLOVO, self::UBER, self::BOLT_FOOD], true);
    }

    public static function allowsManualDiscount(?string $type): bool
    {
        return in_array(self::normalize($type), [self::DELIVERY, self::TAKE_AWAY, self::DINE_IN], true);
    }

    /**
     * Stored `orders.order_type`. Every Branch POS channel stays in the POS family
     * so Online Orders (`notPos()` / `notDineIn()`) never picks them up.
     *
     * POS Delivery is `pos` + `sales_channel=delivery`, never `order_type=delivery`.
     */
    public static function databaseType(?string $type): string
    {
        $type = self::normalize($type);

        return match ($type) {
            self::DINE_IN => 'dine_in',
            default => 'pos',
        };
    }

    public static function isPosSalesChannel(?string $channel): bool
    {
        return in_array((string) $channel, self::salesChannels(), true);
    }

    /**
     * Branch POS family: stored as `pos`, or tagged with a POS sales_channel
     * (covers leftover POS Delivery rows that were saved as order_type=delivery).
     */
    public static function isPosFamily(?string $orderType, ?string $salesChannel): bool
    {
        if ((string) $orderType === 'pos') {
            return true;
        }

        return self::isPosSalesChannel($salesChannel);
    }

    /**
     * POS Delivery only. Website/app delivery is order_type=delivery with no POS channel.
     */
    public static function isPosDeliveryOrder(?string $orderType, ?string $salesChannel): bool
    {
        return (string) $salesChannel === self::DELIVERY
            && self::isPosFamily($orderType, $salesChannel);
    }

    public static function isOnlineOrder(?string $orderType, ?string $salesChannel): bool
    {
        if (self::isPosFamily($orderType, $salesChannel)) {
            return false;
        }

        return (string) $orderType !== 'dine_in';
    }

    /**
     * Dedicated POS sales channel. Independent of `order_type` and `order_note`.
     *
     * @return 'pos'|'delivery'|'takeaway'|'dine_in'|'glovo'|'uber'|'bolt_food'
     */
    public static function salesChannel(?string $type): string
    {
        return match (self::normalize($type)) {
            self::DELIVERY => 'delivery',
            self::TAKE_AWAY => 'takeaway',
            self::DINE_IN => 'dine_in',
            self::GLOVO => 'glovo',
            self::UBER => 'uber',
            self::BOLT_FOOD => 'bolt_food',
            default => 'pos',
        };
    }

    /**
     * @return list<string>
     */
    public static function salesChannels(): array
    {
        return ['pos', 'delivery', 'takeaway', 'dine_in', 'glovo', 'uber', 'bolt_food'];
    }

    public static function channelLabel(?string $salesChannel, ?string $orderType = null): string
    {
        return match ($salesChannel ?: '') {
            'pos' => 'POS',
            'delivery' => 'Delivery',
            'takeaway' => 'Take Away',
            'dine_in' => 'Dine In',
            'glovo' => 'Glovo',
            'uber' => 'Uber',
            'bolt_food' => 'Bolt Food',
            default => match ($orderType ?: '') {
                'pos' => 'POS',
                'dine_in' => 'Dine In',
                'delivery' => 'Delivery',
                'take_away' => 'Take Away',
                default => $orderType ? (string) $orderType : 'POS',
            },
        };
    }

    /**
     * Cashier-facing POS payment methods for a UI order type.
     *
     * Marketplace channels are already paid on the platform. The cashier never
     * chooses Cash / Card / M-PESA; the stored value is the channel itself.
     *
     * @return list<string>
     */
    public static function paymentMethods(?string $type, bool $posMpesaEnabled = true): array
    {
        return match (self::normalize($type)) {
            self::DELIVERY, self::TAKE_AWAY, self::DINE_IN => $posMpesaEnabled
                ? ['cash', 'card', 'mpesa']
                : ['cash', 'card'],
            self::GLOVO => [self::GLOVO],
            self::UBER => [self::UBER],
            self::BOLT_FOOD => [self::BOLT_FOOD],
            default => ['cash', 'card'],
        };
    }

    public static function marketplacePaymentMethod(?string $type): ?string
    {
        $type = self::normalize($type);

        return self::isMarketplace($type) ? $type : null;
    }

    public static function resolvedPaymentMethod(?string $type, ?string $requested): string
    {
        $marketplace = self::marketplacePaymentMethod($type);
        if ($marketplace !== null) {
            return $marketplace;
        }

        return (string) $requested;
    }

    public static function isMarketplacePayment(?string $method): bool
    {
        return in_array((string) $method, [self::GLOVO, self::UBER, self::BOLT_FOOD], true);
    }

    public static function isMarketplaceChannel(?string $channel): bool
    {
        return in_array((string) $channel, [self::GLOVO, self::UBER, self::BOLT_FOOD], true);
    }

    /**
     * Channels cashiers may cancel from Branch POS View Orders.
     * Marketplace orders stay POS-family for reporting, but are not POS-owned.
     */
    public static function allowsPosCancellation(?string $salesChannel): bool
    {
        return in_array((string) $salesChannel, [self::DELIVERY, 'takeaway', self::DINE_IN, 'pos'], true);
    }

    public static function paymentDisplayLabel(?string $method): string
    {
        return match ((string) $method) {
            self::GLOVO => 'Glovo',
            self::UBER => 'Uber',
            self::BOLT_FOOD => 'Bolt Food',
            'cash' => translate('Cash'),
            'card' => translate('Card'),
            'mpesa' => translate('M-PESA'),
            'cash_on_delivery' => translate('Cash On Delivery'),
            default => ucwords(str_replace('_', ' ', (string) $method)),
        };
    }

    public static function paymentReceiptLabel(?string $method): string
    {
        return self::paymentDisplayLabel($method);
    }

    public static function channelBadgeClass(?string $channel): string
    {
        return match ((string) $channel) {
            self::GLOVO => 'munch-channel-badge munch-channel-badge--glovo',
            self::UBER => 'munch-channel-badge munch-channel-badge--uber',
            self::BOLT_FOOD => 'munch-channel-badge munch-channel-badge--bolt_food',
            default => 'munch-channel-badge',
        };
    }

    public static function phoneDigitsError(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return 'Customer Phone';
        }
        if (strlen($digits) < 9 || strlen($digits) > 12) {
            return 'Invalid phone number';
        }

        return null;
    }

    /**
     * Required POS Delivery fields. Other order types skip this validation.
     *
     * Rider phone is required only when a rider name is entered.
     *
     * @param  array{customer_name?: mixed, customer_phone?: mixed, address?: mixed, rider_name?: mixed, rider_phone?: mixed}  $fields
     */
    public static function posDeliveryFieldError(?string $type, array $fields): ?string
    {
        if (! self::isDelivery($type)) {
            return null;
        }

        if (trim((string) ($fields['customer_name'] ?? '')) === '') {
            return 'Customer Name';
        }
        $customerPhone = trim((string) ($fields['customer_phone'] ?? ''));
        if ($customerPhone === '') {
            return 'Customer Phone';
        }
        $phoneError = self::phoneDigitsError($customerPhone);
        if ($phoneError !== null) {
            return $phoneError;
        }
        if (trim((string) ($fields['address'] ?? '')) === '') {
            return 'Delivery Address';
        }

        $riderName = trim((string) ($fields['rider_name'] ?? ''));
        $riderPhone = trim((string) ($fields['rider_phone'] ?? ''));
        if ($riderName !== '' && $riderPhone === '') {
            return 'Rider Phone';
        }
        if ($riderPhone !== '') {
            $riderPhoneError = self::phoneDigitsError($riderPhone);
            if ($riderPhoneError !== null) {
                return 'Invalid phone number';
            }
        }

        return null;
    }

    public static function isImmediatePosPayment(?string $method): bool
    {
        return in_array((string) $method, ['cash', 'card', 'mpesa'], true);
    }

    public static function isPaidImmediately(?string $type, ?string $paymentMethod): bool
    {
        if (self::isImmediatePosPayment($paymentMethod)) {
            return true;
        }

        $type = self::normalize($type);
        if ($type === self::DELIVERY) {
            return false;
        }
        if ($type === self::DINE_IN && $paymentMethod === 'pay_after_eating') {
            return false;
        }

        return true;
    }

    public static function defaultStatus(?string $type): string
    {
        $type = self::normalize($type);
        if ($type === self::TAKE_AWAY || self::isMarketplace($type)) {
            return 'delivered';
        }

        return 'confirmed';
    }

    public static function showsDeliveryCharge(?string $type): bool
    {
        return self::isDelivery($type);
    }
}
