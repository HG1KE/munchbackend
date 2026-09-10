<?php

namespace App\CentralLogics;

use App\Model\Order;
use App\Support\PosCancellationNotificationSettings;
use App\Support\SmsTemplateCatalog;
use Illuminate\Support\Facades\Schema;

/**
 * One SMS to the Master Admin notification number after a Branch POS cancellation.
 * Never sent to the customer, rider, or cashier.
 */
class PosOrderCancelledSms
{
    public static function dispatch(Order $order): void
    {
        try {
            if (! $order->isPosFamily()) {
                return;
            }

            if (Schema::hasColumn('orders', 'pos_cancelled_sms_sent_at')
                && $order->pos_cancelled_sms_sent_at !== null) {
                return;
            }

            if (! SMS_module::isTemplateSendable(SmsTemplateCatalog::POS_ORDER_CANCELLED)) {
                return;
            }

            $phone = self::recipient();
            if ($phone === null || $phone === '') {
                return;
            }

            $vars = self::buildVariables($order);
            $result = SMS_module::sendViaTemplate(
                SmsTemplateCatalog::POS_ORDER_CANCELLED,
                $phone,
                $vars,
                'pos_order_cancelled'
            );

            if ($result === 'success' && Schema::hasColumn('orders', 'pos_cancelled_sms_sent_at')) {
                Order::query()
                    ->whereKey($order->id)
                    ->whereNull('pos_cancelled_sms_sent_at')
                    ->update(['pos_cancelled_sms_sent_at' => now()]);
            }
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    public static function recipient(): ?string
    {
        $phone = PosCancellationNotificationSettings::phone();

        return $phone === '' ? null : $phone;
    }

    /**
     * @return array<string, string>
     */
    public static function buildVariables(Order $order): array
    {
        $order->loadMissing(['branch']);

        $reason = trim((string) ($order->cancellation_reason ?? ''));
        $number = Helpers::order_display_id($order);

        return [
            'order_number' => $number,
            'order_id' => $number,
            'total_amount' => self::formatMoney((float) $order->order_amount),
            'branch_name' => $order->branch ? (string) $order->branch->name : '',
            'cancellation_reason' => $reason,
        ];
    }

    private static function formatMoney(float $amount): string
    {
        try {
            return Helpers::set_symbol($amount);
        } catch (\Throwable) {
            return 'KES '.number_format($amount, 2, '.', '');
        }
    }
}
