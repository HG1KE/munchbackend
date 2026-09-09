<?php

namespace App\Support;

/**
 * Known transactional SMS templates. Extra catalog entries can be configured
 * before a send trigger exists; only wired keys are sent today.
 */
class SmsTemplateCatalog
{
    public const CUSTOMER_OTP = 'customer_otp';

    public const LOGIN_OTP = 'login_otp';

    public const BRANCH_NEW_ORDER = 'branch_new_order';

    public const ORDER_PLACED = 'order_placed';

    public const PROCESSING = 'processing';

    public const ORDER_CONFIRMED = 'order_confirmed';

    public const OUT_FOR_DELIVERY = 'out_for_delivery';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    public const REFUND = 'refund';

    public const WALLET_CREDIT = 'wallet_credit';

    /**
     * Config field on the old customer-confirm gateway → catalog key.
     *
     * @var array<string, string>
     */
    public const LEGACY_CUSTOMER_CONFIRM_FIELDS = [
        'order_placed_template' => self::ORDER_PLACED,
        'processing_template' => self::PROCESSING,
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * Templates that already have send call sites.
     *
     * @return list<string>
     */
    public static function wiredKeys(): array
    {
        return [
            self::CUSTOMER_OTP,
            self::BRANCH_NEW_ORDER,
            self::ORDER_PLACED,
            self::PROCESSING,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            self::CUSTOMER_OTP => [
                'label' => 'Customer OTP',
                'help' => 'Also used for login OTP. Uses the TextSMS OTP endpoint.',
                'placeholders' => ['#OTP#'],
                'channel' => 'otp',
                'default_message' => 'Your OTP is #OTP#.',
                'default_status' => 0,
                'wired' => true,
            ],
            self::LOGIN_OTP => [
                'label' => 'Login OTP',
                'help' => 'Login currently uses the Customer OTP template and send path. Stored here so the gateway can be chosen independently later.',
                'placeholders' => ['#OTP#'],
                'channel' => 'otp',
                'default_message' => 'Your login OTP is #OTP#.',
                'default_status' => 0,
                'wired' => false,
            ],
            self::BRANCH_NEW_ORDER => [
                'label' => 'Branch new order',
                'help' => 'Sent to the branch phone when a new order is placed.',
                'placeholders' => ['{order_id}', '{title}', '{description}', '{customer_name}', '{order_amount}'],
                'channel' => 'sendsms',
                'default_message' => 'New order #{order_id} from {customer_name}. Amount: {order_amount}',
                'default_status' => 0,
                'wired' => true,
            ],
            self::ORDER_PLACED => [
                'label' => 'Order Placed',
                'help' => 'Customer SMS when an order is placed.',
                'placeholders' => ['{order_id}', '{customer_name}', '{order_amount}', '{branch_name}', '{branch_phone}', '{order_status}'],
                'channel' => 'sendsms',
                'default_message' => 'Thank you {customer_name}! Order #{order_id} placed at {branch_name}. Total {order_amount}. Status: {order_status}.',
                'default_status' => 0,
                'wired' => true,
            ],
            self::PROCESSING => [
                'label' => 'Processing',
                'help' => 'Customer SMS when an order moves to processing.',
                'placeholders' => ['{order_id}', '{customer_name}', '{order_amount}', '{branch_name}', '{branch_phone}', '{order_status}'],
                'channel' => 'sendsms',
                'default_message' => '{branch_name} is processing your order #{order_id}.',
                'default_status' => 0,
                'wired' => true,
            ],
            self::ORDER_CONFIRMED => [
                'label' => 'Order Confirmed',
                'help' => 'Ready for routing. No send trigger is attached yet.',
                'placeholders' => ['{order_id}', '{customer_name}', '{order_amount}', '{branch_name}', '{order_status}'],
                'channel' => 'sendsms',
                'default_message' => 'Hi {customer_name}, your order #{order_id} at {branch_name} is confirmed.',
                'default_status' => 0,
                'wired' => false,
            ],
            self::OUT_FOR_DELIVERY => [
                'label' => 'Out For Delivery',
                'help' => 'Ready for routing. No send trigger is attached yet.',
                'placeholders' => ['{order_id}', '{customer_name}', '{branch_name}'],
                'channel' => 'sendsms',
                'default_message' => 'Hi {customer_name}, order #{order_id} is out for delivery.',
                'default_status' => 0,
                'wired' => false,
            ],
            self::DELIVERED => [
                'label' => 'Delivered',
                'help' => 'Ready for routing. No send trigger is attached yet.',
                'placeholders' => ['{order_id}', '{customer_name}', '{order_amount}', '{branch_name}'],
                'channel' => 'sendsms',
                'default_message' => 'Hi {customer_name}, order #{order_id} has been delivered. Amount: {order_amount}',
                'default_status' => 0,
                'wired' => false,
            ],
            self::CANCELLED => [
                'label' => 'Cancelled',
                'help' => 'Ready for routing. No send trigger is attached yet.',
                'placeholders' => ['{order_id}', '{customer_name}', '{branch_name}'],
                'channel' => 'sendsms',
                'default_message' => 'Hi {customer_name}, order #{order_id} has been cancelled.',
                'default_status' => 0,
                'wired' => false,
            ],
            self::REFUND => [
                'label' => 'Refund',
                'help' => 'Ready for routing. No send trigger is attached yet.',
                'placeholders' => ['{order_id}', '{customer_name}', '{order_amount}'],
                'channel' => 'sendsms',
                'default_message' => 'Hi {customer_name}, a refund for order #{order_id} has been processed.',
                'default_status' => 0,
                'wired' => false,
            ],
            self::WALLET_CREDIT => [
                'label' => 'Wallet Credit',
                'help' => 'Ready for routing. Loyalty delivery SMS stays on the Marketing campaign page.',
                'placeholders' => ['{customer_name}', '{order_id}'],
                'channel' => 'sendsms',
                'default_message' => 'Hi {customer_name}, your wallet has been credited.',
                'default_status' => 0,
                'wired' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function definition(string $key): array
    {
        return self::definitions()[$key] ?? [];
    }

    public static function isKnown(string $key): bool
    {
        return isset(self::definitions()[$key]);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function normalizeTemplate(string $key, array $stored = []): array
    {
        $def = self::definition($key);
        $gateway = (string) ($stored['gateway'] ?? SmsGatewayKeys::ASSIGNMENT_TRANSACTIONAL);
        if (! SmsGatewayKeys::isAssignment($gateway)) {
            $gateway = SmsGatewayKeys::ASSIGNMENT_TRANSACTIONAL;
        }

        $message = isset($stored['message']) ? (string) $stored['message'] : '';
        if ($message === '' && isset($def['default_message'])) {
            $message = (string) $def['default_message'];
        }

        return [
            'key' => $key,
            'status' => (int) ($stored['status'] ?? ($def['default_status'] ?? 0)) === 1 ? 1 : 0,
            'message' => $message,
            'gateway' => $gateway,
            'channel' => (string) ($def['channel'] ?? 'sendsms'),
            'label' => (string) ($def['label'] ?? $key),
            'help' => (string) ($def['help'] ?? ''),
            'placeholders' => $def['placeholders'] ?? [],
            'wired' => (bool) ($def['wired'] ?? false),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $storedTemplates
     * @return array<string, array<string, mixed>>
     */
    public static function hydrateAll(array $storedTemplates): array
    {
        $out = [];
        foreach (self::keys() as $key) {
            $stored = isset($storedTemplates[$key]) && is_array($storedTemplates[$key])
                ? $storedTemplates[$key]
                : [];
            $out[$key] = self::normalizeTemplate($key, $stored);
        }

        return $out;
    }
}
