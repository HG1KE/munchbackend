<?php

namespace App\CentralLogics;

use App\Model\Order;
use App\Support\SmsTemplateCatalog;
use Illuminate\Support\Facades\Schema;

/**
 * Customer SMS after a successful Branch POS Delivery order only.
 */
class PosDeliveryCustomerSms
{
    public static function dispatch(Order $order): void
    {
        try {
            if (! self::isPosDeliveryOrder($order)) {
                return;
            }

            if (Schema::hasColumn('orders', 'customer_pos_delivery_sms_sent_at')
                && $order->customer_pos_delivery_sms_sent_at !== null) {
                return;
            }

            if (! SMS_module::isTemplateSendable(SmsTemplateCatalog::POS_DELIVERY_CUSTOMER)) {
                return;
            }

            $order->loadMissing(['customer', 'branch', 'details', 'customer_delivery_address']);

            $phone = self::resolveCustomerPhone($order);
            if ($phone === null || $phone === '') {
                return;
            }

            $vars = self::buildVariables($order);
            $result = SMS_module::sendViaTemplate(
                SmsTemplateCatalog::POS_DELIVERY_CUSTOMER,
                $phone,
                $vars,
                'pos_delivery_customer',
                fn (string $message) => self::omitEmptySections($message, $vars)
            );

            if ($result === 'success' && Schema::hasColumn('orders', 'customer_pos_delivery_sms_sent_at')) {
                Order::query()
                    ->whereKey($order->id)
                    ->whereNull('customer_pos_delivery_sms_sent_at')
                    ->update(['customer_pos_delivery_sms_sent_at' => now()]);
            }
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    public static function isPosDeliveryOrder(Order $order): bool
    {
        return \App\Support\PosOrderTypes::isPosDeliveryOrder(
            $order->order_type ?? null,
            $order->sales_channel ?? null
        );
    }

    /**
     * @return array<string, string>
     */
    public static function buildVariables(Order $order): array
    {
        $order->loadMissing(['customer', 'branch', 'details', 'customer_delivery_address']);

        $customerPhone = (string) (self::resolveCustomerPhone($order) ?? '');
        $riderName = trim((string) ($order->rider_name ?? ''));
        $riderPhone = trim((string) ($order->rider_phone ?? ''));
        $till = $order->branch ? trim((string) ($order->branch->mpesa_till ?? '')) : '';
        $deliveryFee = self::formatMoney((float) $order->delivery_charge);
        $total = self::formatMoney((float) $order->order_amount);
        $subtotal = self::formatMoney(max(0, (float) $order->order_amount - (float) $order->delivery_charge));

        $riderInfo = '';
        if ($riderName !== '') {
            $riderInfo = ' and will be delivered by '.$riderName;
            if ($riderPhone !== '') {
                $riderInfo .= ' ('.$riderPhone.')';
            }
        }

        $mpesaInfo = $till !== '' && ($order->payment_method ?? '') === 'mpesa'
            ? "\n\nPlease pay to M-PESA Till ".$till." if you haven't already."
            : '';

        return [
            'order_id' => Helpers::order_display_id($order),
            'branch_name' => $order->branch ? (string) $order->branch->name : '',
            'customer_name' => self::resolveCustomerName($order),
            'customer_phone' => $customerPhone,
            'delivery_address' => self::resolveDeliveryAddressText($order),
            'delivery_fee' => $deliveryFee,
            'subtotal' => $subtotal,
            'total' => $total,
            'rider_name' => $riderName,
            'rider_phone' => $riderPhone,
            'rider_info' => $riderInfo,
            'mpesa_till' => $till,
            'mpesa_info' => $mpesaInfo,
            'items' => self::formatItems($order),
        ];
    }

    public static function omitEmptySections(string $message, array $vars): string
    {
        if (trim((string) ($vars['mpesa_info'] ?? $vars['mpesa_till'] ?? '')) === '') {
            $message = preg_replace('/\R*Please pay to M-PESA Till[^\n]*/i', '', $message) ?? $message;
            $message = str_replace('{mpesa_info}', '', $message);
        }
        if (trim((string) ($vars['rider_name'] ?? '')) === '') {
            $message = preg_replace('/\s+and will be delivered by[^.]*\./i', '.', $message) ?? $message;
            $message = preg_replace('/\s+will be delivered by\s*(?:\([^)]*\))?/i', '', $message) ?? $message;
            $message = str_replace('{rider_info}', '', $message);
        }

        $message = preg_replace("/\n{3,}/", "\n\n", $message) ?? $message;

        return trim($message);
    }

    private static function formatMoney(float $amount): string
    {
        try {
            return Helpers::set_symbol($amount);
        } catch (\Throwable) {
            return 'KES '.number_format($amount, 2, '.', '');
        }
    }

    private static function resolveCustomerName(Order $order): string
    {
        $name = trim((string) (self::addressArray($order)['contact_person_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $related = $order->customer_delivery_address;
        if ($related) {
            $fromRelated = trim((string) ($related->contact_person_name ?? ''));
            if ($fromRelated !== '') {
                return $fromRelated;
            }
        }

        if ($order->customer) {
            $fromCustomer = trim(($order->customer->f_name ?? '').' '.($order->customer->l_name ?? ''));
            if ($fromCustomer !== '') {
                return $fromCustomer;
            }
        }

        return 'Guest';
    }

    private static function resolveCustomerPhone(Order $order): ?string
    {
        $addr = self::addressArray($order);
        foreach (['contact_person_number', 'phone'] as $key) {
            $value = trim((string) ($addr[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $related = $order->customer_delivery_address;
        if ($related) {
            $fromRelated = trim((string) ($related->contact_person_number ?? ''));
            if ($fromRelated !== '') {
                return $fromRelated;
            }
        }

        if ($order->customer && ! empty($order->customer->phone)) {
            return (string) $order->customer->phone;
        }

        return null;
    }

    private static function resolveDeliveryAddressText(Order $order): string
    {
        $line = trim((string) (self::addressArray($order)['address'] ?? ''));
        if ($line !== '') {
            return $line;
        }

        $related = $order->customer_delivery_address;

        return $related ? trim((string) ($related->address ?? '')) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function addressArray(Order $order): array
    {
        $raw = $order->getRawOriginal('delivery_address');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private static function formatItems(Order $order): string
    {
        $lines = [];
        foreach ($order->details ?? [] as $detail) {
            $name = self::detailName($detail);
            $qty = (int) ($detail->quantity ?? 0);
            if ($name === '' || $qty < 1) {
                continue;
            }
            $lines[] = $qty.' x '.$name;
        }

        return implode("\n", $lines);
    }

    private static function detailName(mixed $detail): string
    {
        $product = $detail->product_details ?? null;
        if (is_string($product) && $product !== '') {
            $product = json_decode($product, true);
        }
        if (is_object($product)) {
            $product = json_decode(json_encode($product), true);
        }
        if (is_array($product)) {
            $name = trim((string) ($product['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return trim((string) (data_get($detail, 'product.name') ?? ''));
    }
}
