<?php

namespace App\CentralLogics;

use App\Model\Order;
use App\Support\PosOrderTypes;
use App\Support\SmsTemplateCatalog;

/**
 * Customer-facing TextSMS: order placed + processing templates (gateway chosen per template).
 */
class CustomerOrderStatusSms
{
    /**
     * Order placement SMS — once per order when initial status is pending or confirmed (API/POS/table).
     * Does not run on pending→confirmed (guarded by customer_placement_sms_sent_at).
     */
    public static function dispatchPlacement(Order $order): void
    {
        try {
            if (PosOrderTypes::isPosFamily($order->order_type ?? null, $order->sales_channel ?? null)) {
                return;
            }

            if (! in_array($order->order_status, ['pending', 'confirmed'], true)) {
                return;
            }

            if ($order->customer_placement_sms_sent_at !== null) {
                return;
            }

            if (! SMS_module::isTemplateSendable(SmsTemplateCatalog::ORDER_PLACED)) {
                return;
            }

            $order->loadMissing(['customer', 'branch']);

            $phone = self::resolveCustomerPhone($order);
            if ($phone === null || $phone === '') {
                return;
            }

            $smsData = self::buildTemplateData($order);

            $result = SMS_module::textsms_ke_customer_status_sms($phone, $smsData, 'order_placed_template');

            if ($result === 'success') {
                Order::query()->whereKey($order->id)->whereNull('customer_placement_sms_sent_at')->update([
                    'customer_placement_sms_sent_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    /**
     * Processing SMS — once when status transitions into processing.
     */
    public static function dispatchProcessing(Order $order, ?string $previousStatus): void
    {
        try {
            if (PosOrderTypes::isPosFamily($order->order_type ?? null, $order->sales_channel ?? null)) {
                return;
            }

            if ($order->order_status !== 'processing') {
                return;
            }

            if ($previousStatus !== null && $previousStatus === 'processing') {
                return;
            }

            self::sendProcessingSms($order);
        } catch (\Throwable $e) {
            //
        }
    }

    /**
     * Processing SMS when Branch POS accepts an online order into sales.
     *
     * Accept converts the row to POS family with status confirmed (not processing),
     * so the normal status-transition gates do not apply. Still once-per-order via
     * customer_processing_sms_sent_at and SmsTemplateCatalog::PROCESSING.
     * Placeholders use the order's current branch (post-transfer when reassigned).
     */
    public static function dispatchProcessingOnPosAccept(Order $order): void
    {
        try {
            self::sendProcessingSms($order);
        } catch (\Throwable $e) {
            // Non-fatal
        }
    }

    /**
     * Shared PROCESSING template send + once-flag stamp.
     */
    private static function sendProcessingSms(Order $order): void
    {
        if ($order->customer_processing_sms_sent_at !== null) {
            return;
        }

        if (! SMS_module::isTemplateSendable(SmsTemplateCatalog::PROCESSING)) {
            return;
        }

        // Always resolve branch from current branch_id (covers transfer-then-accept).
        $order->unsetRelation('branch');
        $order->load(['customer', 'branch']);

        $phone = self::resolveCustomerPhone($order);
        if ($phone === null || $phone === '') {
            return;
        }

        $smsData = self::buildTemplateData($order);

        $result = SMS_module::textsms_ke_customer_status_sms($phone, $smsData, 'processing_template');

        if ($result === 'success') {
            Order::query()->whereKey($order->id)->whereNull('customer_processing_sms_sent_at')->update([
                'customer_processing_sms_sent_at' => now(),
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    public static function buildTemplateData(Order $order): array
    {
        $order->loadMissing(['customer', 'branch']);

        $customerName = self::resolveCustomerName($order);
        $totalAmount = number_format((float) $order->order_amount + (float) $order->delivery_charge, 2);
        $statusLabel = ucwords(str_replace('_', ' ', (string) $order->order_status));

        return [
            'order_id' => Helpers::order_display_id($order),
            'title' => '',
            'description' => '',
            'customer_name' => $customerName,
            'order_amount' => $totalAmount,
            'branch_name' => $order->branch ? (string) $order->branch->name : '',
            'branch_phone' => $order->branch ? (string) ($order->branch->phone ?? '') : '',
            'order_status' => $statusLabel,
        ];
    }

    private static function resolveCustomerName(Order $order): string
    {
        if ($order->is_guest == 0 && $order->customer) {
            return trim(($order->customer->f_name ?? '').' '.($order->customer->l_name ?? ''));
        }
        $addr = $order->delivery_address;
        if (is_array($addr)) {
            $name = trim((string) ($addr['contact_person_name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return 'Guest';
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

        if ($order->is_guest == 0 && $order->customer && ! empty($order->customer->phone)) {
            return (string) $order->customer->phone;
        }

        return null;
    }
}
