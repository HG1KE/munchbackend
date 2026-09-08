<?php

namespace App\CentralLogics;

use App\Model\LoyaltyDeliverySmsLog;
use App\Model\Order;
use App\Model\PointTransitions;
use App\User;
use Illuminate\Support\Facades\Log;

/**
 * Transactional SMS after loyalty points are credited on delivered orders.
 */
class LoyaltyDeliverySmsService
{
    public const GATEWAY_KEY = 'textsms_ke_loyalty_delivery';

    /**
     * Entry point after an order is saved as delivered. Logs the full chain for production diagnosis.
     *
     * @param  int|false|null  $loyaltyResult  Return value from create_loyalty_point_transaction()
     */
    public static function attemptAfterDeliveredTransition(Order $order, int|false|null $loyaltyResult, string $handlerPath): void
    {
        $runtime = SMS_module::describeLoyaltyDeliveryRuntimeConfig();

        Log::info('loyalty_delivery_sms.delivered_transition', [
            'handler' => $handlerPath,
            'order_id' => $order->id,
            'order_status' => $order->order_status,
            'is_guest' => (int) $order->is_guest,
            'user_id' => $order->user_id,
            'loyalty_result' => $loyaltyResult,
            'runtime' => $runtime,
        ]);

        if ((int) $order->is_guest !== 0) {
            self::logSkipped($order, 0, 0, 'guest_checkout', $handlerPath);

            return;
        }

        if (! $order->user_id) {
            self::logSkipped($order, 0, 0, 'missing_user_id', $handlerPath);

            return;
        }

        if ($loyaltyResult === null) {
            self::logSkipped($order, 0, 0, 'loyalty_not_attempted', $handlerPath);

            return;
        }

        if ($loyaltyResult === false) {
            self::logSkipped($order, 0, 0, 'loyalty_credit_failed', $handlerPath);

            return;
        }

        if ((int) $loyaltyResult <= 0) {
            self::logSkipped($order, 0, 0, 'zero_points_credited', $handlerPath);

            return;
        }

        if (! in_array($order->order_status, ['delivered', 'completed'], true)) {
            self::logSkipped($order, (int) $loyaltyResult, 0, 'order_not_delivered_yet', $handlerPath);

            return;
        }

        self::dispatchForDeliveredOrder($order, (int) $loyaltyResult, $handlerPath);
    }

    /**
     * Send transactional loyalty SMS (inline). Idempotent per order_id.
     */
    public static function dispatchForDeliveredOrder(Order $order, int $earnedPoints, string $handlerPath = 'unknown'): void
    {
        try {
            if ($earnedPoints <= 0) {
                if (self::shouldInstrument()) {
                    Log::info('loyalty_delivery_sms.skipped', [
                        'handler' => $handlerPath,
                        'order_id' => $order->id,
                        'reason' => 'zero_points',
                    ]);
                }

                return;
            }

            if ((int) $order->is_guest !== 0 || ! $order->user_id) {
                self::logSkipped($order, $earnedPoints, 0, (int) $order->is_guest !== 0 ? 'guest_checkout' : 'missing_user_id', $handlerPath);

                return;
            }

            if (! in_array($order->order_status, ['delivered', 'completed'], true)) {
                self::logSkipped($order, $earnedPoints, 0, 'invalid_order_status', $handlerPath);

                return;
            }

            $runtime = SMS_module::describeLoyaltyDeliveryRuntimeConfig();
            $config = SMS_module::getLoyaltyDeliveryRuntimeConfig();

            if (! is_array($config) || (int) ($config['status'] ?? 0) !== 1) {
                $reason = is_array($runtime) ? ($runtime['inactive_reason'] ?? 'campaign_inactive') : 'campaign_inactive';
                self::logSkipped($order, $earnedPoints, 0, $reason, $handlerPath);

                if (self::shouldInstrument()) {
                    Log::info('loyalty_delivery_sms.skipped', [
                        'handler' => $handlerPath,
                        'order_id' => $order->id,
                        'reason' => $reason,
                        'runtime' => $runtime,
                    ]);
                }

                return;
            }

            if (LoyaltyDeliverySmsLog::query()->where('order_id', $order->id)->exists()) {
                if (self::shouldInstrument()) {
                    Log::info('loyalty_delivery_sms.skipped', [
                        'handler' => $handlerPath,
                        'order_id' => $order->id,
                        'reason' => 'duplicate_order_log',
                    ]);
                }

                return;
            }

            $order->loadMissing(['customer', 'branch']);

            $phone = self::resolveCustomerPhone($order);
            if ($phone === null || $phone === '') {
                self::logSkipped($order, $earnedPoints, 0, 'missing_phone', $handlerPath);

                return;
            }

            $user = User::query()->find($order->user_id);
            if (! $user) {
                self::logSkipped($order, $earnedPoints, 0, 'user_not_found', $handlerPath);

                return;
            }

            $currentPoints = (int) $user->point;
            $customerName = self::resolveCustomerName($order);

            $log = LoyaltyDeliverySmsLog::query()->create([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'phone' => $phone,
                'customer_name' => $customerName,
                'earned_points' => $earnedPoints,
                'points_balance' => $currentPoints,
                'status' => 'pending',
            ]);

            $vars = [
                'customer_name' => $customerName,
                'earned_points' => (string) $earnedPoints,
                'current_points' => (string) $currentPoints,
                'order_id' => (string) $order->id,
            ];

            $renderedBody = SMS_module::renderLoyaltyDeliveryMessage($config, $vars);

            $log->message_body = $renderedBody;
            $log->save();

            if (self::shouldInstrument()) {
                Log::info('loyalty_delivery_sms.sending', [
                    'handler' => $handlerPath,
                    'order_id' => $order->id,
                    'phone_suffix' => self::phoneSuffix($phone),
                    'earned_points' => $earnedPoints,
                    'points_balance' => $currentPoints,
                ]);
            }

            $result = SMS_module::textsms_ke_loyalty_delivery($phone, $vars);

            if ($result === 'success') {
                $log->update([
                    'status' => 'sent',
                    'sms_sent_at' => now(),
                    'last_provider_status' => 'success',
                    'last_error' => null,
                ]);

                Log::info('loyalty_delivery_sms.sent', [
                    'handler' => $handlerPath,
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'earned_points' => $earnedPoints,
                    'points_balance' => $currentPoints,
                ]);

                return;
            }

            $log->update([
                'status' => 'failed',
                'last_provider_status' => $result,
                'last_error' => 'provider_returned_'.$result,
            ]);

            Log::warning('loyalty_delivery_sms.failed', [
                'handler' => $handlerPath,
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'provider_status' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('loyalty_delivery_sms.exception', [
                'handler' => $handlerPath,
                'order_id' => $order->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function sendTestSms(string $phone): array
    {
        $phone = trim($phone);
        if ($phone === '') {
            return ['ok' => false, 'message' => 'Please enter a valid phone number'];
        }

        $config = SMS_module::getLoyaltyDeliveryTestRuntimeConfig();
        if (! is_array($config)) {
            return [
                'ok' => false,
                'message' => 'Transactional SMS is not configured. Enable Customer order confirmation SMS (textsms_ke_customer_confirm) first.',
            ];
        }

        $template = trim((string) ($config['message_template'] ?? ''));
        if ($template === '') {
            return ['ok' => false, 'message' => 'Message template is empty'];
        }

        $vars = [
            'customer_name' => 'Test Customer',
            'earned_points' => '25',
            'current_points' => '150',
            'order_id' => '0',
        ];

        $message = SMS_module::renderLoyaltyDeliveryMessage($config, $vars);
        $result = SMS_module::textsms_ke_send_loyalty_delivery_test($phone, $message);

        if ($result === 'success') {
            return ['ok' => true, 'message' => 'Test SMS sent successfully'];
        }

        return ['ok' => false, 'message' => 'Test SMS could not be sent ('.$result.')'];
    }

    private static function shouldInstrument(): bool
    {
        return filter_var(env('LOYALTY_DELIVERY_SMS_INSTRUMENT', true), FILTER_VALIDATE_BOOLEAN);
    }

    private static function phoneSuffix(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) > 4 ? substr($digits, -4) : $digits;
    }

    private static function logSkipped(Order $order, int $earnedPoints, int $balance, string $reason, string $handlerPath = 'unknown'): void
    {
        if (LoyaltyDeliverySmsLog::query()->where('order_id', $order->id)->exists()) {
            return;
        }

        LoyaltyDeliverySmsLog::query()->create([
            'order_id' => $order->id,
            'user_id' => (int) ($order->user_id ?? 0),
            'phone' => self::resolveCustomerPhone($order) ?? '',
            'customer_name' => self::resolveCustomerName($order),
            'earned_points' => $earnedPoints,
            'points_balance' => $balance,
            'status' => 'skipped',
            'skip_reason' => $reason,
        ]);

        Log::info('loyalty_delivery_sms.skipped', [
            'handler' => $handlerPath,
            'order_id' => $order->id,
            'reason' => $reason,
            'earned_points' => $earnedPoints,
        ]);
    }

    private static function resolveCustomerName(Order $order): string
    {
        if ((int) $order->is_guest === 0 && $order->customer) {
            return trim(($order->customer->f_name ?? '').' '.($order->customer->l_name ?? ''));
        }

        $addr = $order->delivery_address;
        if (is_array($addr)) {
            $name = trim((string) ($addr['contact_person_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return 'Customer';
    }

    private static function resolveCustomerPhone(Order $order): ?string
    {
        $addr = $order->delivery_address;
        if (! is_array($addr)) {
            $addr = [];
        }

        $fromAddr = $addr['contact_person_number'] ?? $addr['phone'] ?? null;
        if ($fromAddr !== null && trim((string) $fromAddr) !== '') {
            return (string) $fromAddr;
        }

        if ((int) $order->is_guest === 0 && $order->customer && ! empty($order->customer->phone)) {
            return (string) $order->customer->phone;
        }

        return null;
    }
}
