<?php

namespace App\CentralLogics;

use App\Model\AddOn;
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
        $till = $order->branch ? trim((string) ($order->branch->mpesa_till ?? '')) : '';
        $deliveryFee = self::formatMoney((float) $order->delivery_charge);
        $total = self::formatMoney((float) $order->order_amount);
        $subtotal = self::formatMoney(max(0, (float) $order->order_amount - (float) $order->delivery_charge));

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
            'rider_name' => '',
            'rider_phone' => '',
            'rider_info' => '',
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
            $block = self::formatItemBlock($detail);
            if ($block !== '') {
                $lines[] = $block;
            }
        }

        return implode("\n", $lines);
    }

    private static function formatItemBlock(mixed $detail): string
    {
        $name = self::detailName($detail);
        $qty = (int) ($detail->quantity ?? 0);
        if ($name === '' || $qty < 1) {
            return '';
        }

        $options = self::detailOptions($detail);
        $addons = self::detailAddons($detail);
        $labels = array_values(array_filter(array_merge(
            array_column($options, 'label'),
            array_column($addons, 'label'),
        ), fn ($label) => trim((string) $label) !== ''));

        $title = $qty.' x '.$name;
        if ($labels !== []) {
            $title .= ' ('.implode(', ', $labels).')';
        }

        $unitPrice = round((float) ($detail->price ?? 0), 2);
        $variationSurcharge = round(array_sum(array_column($options, 'price')), 2);
        $addonTotal = round(array_sum(array_column($addons, 'price')), 2);
        $addonPerUnit = $qty > 0 ? round($addonTotal / $qty, 2) : $addonTotal;
        $surcharge = round($variationSurcharge + $addonPerUnit, 2);
        $base = self::detailBasePrice($unitPrice, $variationSurcharge);
        $finalUnit = round($unitPrice + $addonPerUnit, 2);

        if ($finalUnit <= 0 && $surcharge <= 0) {
            return $title;
        }

        $priceLine = self::formatMoney($finalUnit);
        if ($base > 0 && $surcharge > 0) {
            $priceLine = self::formatMoney($base).' + '.self::formatMoney($surcharge).' = '.self::formatMoney($finalUnit);
        }
        if ($qty > 1) {
            $priceLine .= ' each';
        }

        return $title."\n".$priceLine;
    }

    /**
     * @return list<array{label: string, price: float}>
     */
    private static function detailOptions(mixed $detail): array
    {
        $raw = self::decodeList($detail->variation ?? $detail->variations ?? []);
        $out = [];

        foreach ($raw as $group) {
            if (! is_array($group)) {
                if (is_string($group) && trim($group) !== '') {
                    $out[] = ['label' => trim($group), 'price' => 0.0];
                }
                continue;
            }

            if (isset($group['values'])) {
                $values = $group['values'];
                if (isset($values['label']) && is_array($values['label'])) {
                    foreach ($values['label'] as $label) {
                        $text = trim((string) $label);
                        if ($text !== '') {
                            $out[] = ['label' => $text, 'price' => 0.0];
                        }
                    }
                    continue;
                }

                if (is_array($values)) {
                    foreach ($values as $value) {
                        if (is_string($value) && trim($value) !== '') {
                            $out[] = ['label' => trim($value), 'price' => 0.0];
                            continue;
                        }
                        if (! is_array($value)) {
                            continue;
                        }
                        $text = trim((string) ($value['label'] ?? $value['name'] ?? ''));
                        if ($text === '') {
                            continue;
                        }
                        $out[] = [
                            'label' => $text,
                            'price' => (float) ($value['optionPrice'] ?? $value['price'] ?? 0),
                        ];
                    }
                }
                continue;
            }

            if (isset($group['type'])) {
                $text = trim((string) $group['type']);
                if ($text !== '') {
                    $out[] = ['label' => $text, 'price' => (float) ($group['price'] ?? 0)];
                }
                continue;
            }

            foreach ($group as $key => $value) {
                if (in_array($key, ['name', 'required', 'min', 'max'], true)) {
                    continue;
                }
                if (is_string($value) && trim($value) !== '') {
                    $out[] = ['label' => trim($value), 'price' => 0.0];
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array{label: string, price: float}>
     */
    private static function detailAddons(mixed $detail): array
    {
        $ids = self::decodeList($detail->add_on_ids ?? []);
        $qtys = self::decodeList($detail->add_on_qtys ?? []);
        $prices = self::decodeList($detail->add_on_prices ?? []);
        $out = [];

        foreach ($ids as $index => $id) {
            $name = '';
            $unitPrice = (float) ($prices[$index] ?? 0);
            $addonQty = (int) ($qtys[$index] ?? 1);

            if (is_array($id)) {
                $name = trim((string) ($id['name'] ?? ''));
                if (isset($id['price'])) {
                    $unitPrice = (float) $id['price'];
                }
                $id = $id['id'] ?? null;
            }

            if ($name === '' && $id) {
                try {
                    $addon = AddOn::query()->find($id);
                    $name = $addon ? trim((string) $addon->name) : '';
                } catch (\Throwable) {
                    $name = '';
                }
            }

            if ($name === '') {
                $name = 'Add-on';
            }
            if ($addonQty < 1) {
                $addonQty = 1;
            }

            $out[] = [
                'label' => $name,
                'price' => round($unitPrice * $addonQty, 2),
            ];
        }

        return $out;
    }

    private static function detailBasePrice(float $unitPrice, float $variationSurcharge): float
    {
        if ($variationSurcharge > 0 && $unitPrice >= $variationSurcharge) {
            return round($unitPrice - $variationSurcharge, 2);
        }

        return round($unitPrice, 2);
    }

    /**
     * @return array<int, mixed>
     */
    private static function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            $encoded = json_decode(json_encode($value), true);

            return is_array($encoded) ? $encoded : [];
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
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
