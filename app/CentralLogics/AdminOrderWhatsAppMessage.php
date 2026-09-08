<?php

namespace App\CentralLogics;

use App\Model\AddOn;
use App\Model\Order;
use Throwable;

class AdminOrderWhatsAppMessage
{
    /** @var array<int, string> */
    private static array $addonNameCache = [];
    public static function shareUrl(string $message): string
    {
        return 'https://wa.me/?text=' . rawurlencode($message);
    }

    /**
     * Kitchen-friendly order summary for WhatsApp (plain text, *bold* via WhatsApp formatting).
     */
    public static function build(Order $order, mixed $address = null): string
    {
        self::$addonNameCache = [];
        self::preloadAddonNames($order);

        $lines = [];
        $lines[] = '*ORDER #' . Helpers::order_display_id($order) . '*';
        $lines[] = 'Status: ' . self::label($order->order_status ?? '');
        $lines[] = 'Branch: ' . (optional($order->branch)->name ?? 'N/A');

        $orderType = self::orderTypeLabel($order->order_type ?? '');
        $lines[] = 'Type: ' . $orderType;

        $placedAt = self::formatPlacedAt($order);
        if ($placedAt !== '') {
            $lines[] = 'Placed At: ' . $placedAt;
        }

        $lines[] = '';
        $lines[] = '*CUSTOMER*';
        $lines = array_merge($lines, self::customerLines($order, $address));

        $lines[] = '';
        $lines[] = '*ITEMS*';
        foreach ($order->details as $index => $detail) {
            if ($index > 0) {
                $lines[] = '';
            }
            $lines = array_merge($lines, self::formatDetailLines($detail));
        }

        $lines[] = '';
        $lines[] = '*TOTALS*';
        $lines = array_merge($lines, self::totalsLines($order));
        $lines[] = 'Payment: ' . self::paymentLabel($order);

        $notes = self::notesLines($order);
        if ($notes !== []) {
            $lines[] = '';
            $lines[] = '*NOTES*';
            $lines = array_merge($lines, $notes);
        }

        $fulfillment = self::fulfillmentLines($order, $address);
        if ($fulfillment !== []) {
            $lines[] = '';
            $lines[] = '*' . self::fulfillmentHeading($order->order_type ?? '') . '*';
            $lines = array_merge($lines, $fulfillment);
        }

        return trim(implode("\n", array_filter($lines, static fn ($line) => $line !== null)));
    }

    private static function formatDetailLines($detail): array
    {
        $qty = (int) ($detail->quantity ?? 1);
        $productDetails = self::decodeJsonArray($detail->product_details ?? '{}');
        $name = $productDetails['name'] ?? null;
        if (empty($name) && $detail->relationLoaded('product') && $detail->product) {
            $name = $detail->product->name ?? null;
        }
        $name = $name ?: translate('Product unavailable');

        $line = $qty . 'x ' . $name;

        $variation = self::decodeJsonArray($detail->variation ?? '[]');
        if ($variation !== []) {
            $variationText = self::summarizeVariations($variation);
            if ($variationText !== '') {
                $line .= ' (' . $variationText . ')';
            }
        }

        $lines = [$line];
        $addonLines = self::addonLines($detail, $productDetails);
        if ($addonLines !== []) {
            $lines = array_merge($lines, $addonLines);
        }

        return $lines;
    }

    private static function addonLines($detail, array $productDetails): array
    {
        $addonIds = self::decodeJsonList($detail->add_on_ids ?? '[]');
        if ($addonIds === []) {
            return self::legacyAddonLines($productDetails);
        }

        $qtys = self::decodeJsonList($detail->add_on_qtys ?? '[]');
        $prices = self::decodeJsonList($detail->add_on_prices ?? '[]');
        $lines = [];

        foreach ($addonIds as $index => $rawAddonId) {
            $addonId = (int) $rawAddonId;
            if ($addonId <= 0) {
                continue;
            }

            $addonQty = max(1, (int) ($qtys[$index] ?? 1));
            $unitPrice = (float) ($prices[$index] ?? 0);
            $name = self::resolveAddonName($addonId, $productDetails);
            $lineTotal = $unitPrice * $addonQty;
            $priceLabel = Helpers::set_symbol($addonQty > 1 ? $lineTotal : $unitPrice);

            $lines[] = '  + ' . $name . ' x' . $addonQty . ' (' . $priceLabel . ')';
        }

        return $lines;
    }

    private static function legacyAddonLines(array $productDetails): array
    {
        $addons = $productDetails['add_ons'] ?? [];
        if (! is_array($addons) || $addons === []) {
            return [];
        }

        $lines = [];
        foreach ($addons as $addon) {
            if (! is_array($addon)) {
                continue;
            }

            $name = trim((string) ($addon['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $addonQty = max(1, (int) ($addon['quantity'] ?? $addon['qty'] ?? 1));
            $unitPrice = (float) ($addon['price'] ?? 0);
            $lineTotal = $unitPrice * $addonQty;
            $priceLabel = Helpers::set_symbol($addonQty > 1 ? $lineTotal : $unitPrice);

            $lines[] = '  + ' . $name . ' x' . $addonQty . ' (' . $priceLabel . ')';
        }

        return $lines;
    }

    private static function resolveAddonName(int $addonId, array $productDetails): string
    {
        if ($addonId > 0 && isset(self::$addonNameCache[$addonId])) {
            return self::$addonNameCache[$addonId];
        }

        if ($addonId > 0) {
            foreach ($productDetails['add_ons'] ?? [] as $addon) {
                if (! is_array($addon)) {
                    continue;
                }
                if ((int) ($addon['id'] ?? 0) === $addonId && ! empty($addon['name'])) {
                    return (string) $addon['name'];
                }
            }

            $addon = AddOn::find($addonId);
            if ($addon && ! empty($addon->name)) {
                self::$addonNameCache[$addonId] = $addon->name;

                return $addon->name;
            }
        }

        return translate('addon deleted');
    }

    private static function preloadAddonNames(Order $order): void
    {
        $ids = [];
        foreach ($order->details as $detail) {
            foreach (self::decodeJsonList($detail->add_on_ids ?? '[]') as $addonId) {
                $addonId = (int) $addonId;
                if ($addonId > 0) {
                    $ids[] = $addonId;
                }
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return;
        }

        foreach (AddOn::whereIn('id', $ids)->get(['id', 'name']) as $addon) {
            self::$addonNameCache[(int) $addon->id] = (string) $addon->name;
        }
    }

    /**
     * @return array<int, mixed>
     */
    private static function decodeJsonList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function summarizeVariations(array $variations): string
    {
        $parts = [];
        foreach ($variations as $variation) {
            if (isset($variation['name'], $variation['values']) && is_array($variation['values'])) {
                foreach ($variation['values'] as $value) {
                    if (! empty($value['label'])) {
                        $parts[] = $value['label'];
                    }
                }
            }
        }

        return implode(', ', array_slice($parts, 0, 6));
    }

    private static function customerLines(Order $order, mixed $address): array
    {
        if ((int) ($order->is_guest ?? 0) === 1) {
            return self::addressContactLines($address, translate('Guest Customer'));
        }

        if ($order->customer) {
            $name = trim(($order->customer->f_name ?? '') . ' ' . ($order->customer->l_name ?? ''));

            return array_filter([
                'Name: ' . ($name !== '' ? $name : translate('Customer')),
                ! empty($order->customer->phone) ? 'Phone: ' . $order->customer->phone : null,
            ]);
        }

        if ($order->user_id === null) {
            return ['Name: ' . translate('walking_customer')];
        }

        return self::addressContactLines($address, translate('Customer_not_available'));
    }

    private static function addressContactLines(mixed $address, string $fallbackName): array
    {
        if (is_object($address) && method_exists($address, 'toArray')) {
            $address = $address->toArray();
        }

        if (! is_array($address)) {
            return ['Name: ' . $fallbackName];
        }

        $name = trim((string) ($address['contact_person_name'] ?? ''));
        $phone = trim((string) ($address['contact_person_number'] ?? ''));

        return array_filter([
            'Name: ' . ($name !== '' ? $name : $fallbackName),
            $phone !== '' ? 'Phone: ' . $phone : null,
        ]);
    }

    private static function paymentLabel(Order $order): string
    {
        $method = str_replace('_', ' ', (string) ($order->payment_method ?? ''));
        $status = str_replace('_', ' ', (string) ($order->payment_status ?? ''));

        return trim(ucwords($method) . ($status !== '' ? ' (' . ucwords($status) . ')' : ''));
    }

    private static function notesLines(Order $order): array
    {
        $lines = [];

        if (! empty($order->order_note)) {
            $lines[] = 'Order note: ' . $order->order_note;
        }

        if (! empty($order->bring_change_amount)) {
            $lines[] = 'Change for: ' . Helpers::set_symbol((float) $order->bring_change_amount);
        }

        if ((int) ($order->is_cutlery_required ?? 0) === 1) {
            $lines[] = 'Cutlery: Yes';
        }

        return $lines;
    }

    /**
     * Mirrors admin order-view.blade.php totals (items, discounts, addons, tax, subtotal, delivery, grand total).
     *
     * @return array{subtotal: float, delivery_fee: float, total: float, show_delivery_fee: bool}
     */
    public static function resolveAdminOrderTotals(Order $order): array
    {
        $itemsPrice = 0.0;
        $totalTax = 0.0;
        $totalDisOnPro = 0.0;
        $addOnsCost = 0.0;
        $addOnsTaxCost = 0.0;

        foreach ($order->details as $detail) {
            $quantity = (int) ($detail->quantity ?? 1);
            $itemsPrice += (float) ($detail->price ?? 0) * $quantity;
            $totalDisOnPro += (float) ($detail->discount_on_product ?? 0) * $quantity;
            $totalTax += (float) ($detail->tax_amount ?? 0) * $quantity;

            $addonIds = self::decodeJsonList($detail->add_on_ids ?? '[]');
            $addOnPrices = self::decodeJsonList($detail->add_on_prices ?? '[]');
            $addOnQtys = self::decodeJsonList($detail->add_on_qtys ?? '[]');
            $addOnTaxes = self::decodeJsonList($detail->add_on_taxes ?? '[]');

            foreach ($addonIds as $key => $addonId) {
                if ((int) $addonId <= 0) {
                    continue;
                }
                $addonQty = max(1, (int) ($addOnQtys[$key] ?? 1));
                $addOnsCost += (float) ($addOnPrices[$key] ?? 0) * $addonQty;
                $addOnsTaxCost += (float) ($addOnTaxes[$key] ?? 0) * $addonQty;
            }
        }

        $subtotal = $itemsPrice
            + $totalTax
            + $addOnsCost
            - $totalDisOnPro
            + $addOnsTaxCost
            - (float) ($order->coupon_discount_amount ?? 0)
            - (float) ($order->extra_discount ?? 0)
            - (float) ($order->referral_discount ?? 0);

        $deliveryFee = ($order->order_type ?? '') === 'take_away'
            ? 0.0
            : (float) ($order->delivery_charge ?? 0);

        $grandTotal = $subtotal + $deliveryFee;

        return [
            'subtotal' => max(0, $subtotal),
            'delivery_fee' => max(0, $deliveryFee),
            'total' => max(0, $grandTotal),
            'show_delivery_fee' => ($order->order_type ?? '') !== 'take_away',
        ];
    }

    private static function totalsLines(Order $order): array
    {
        $totals = self::resolveAdminOrderTotals($order);

        $lines = [
            'Subtotal: ' . Helpers::set_symbol($totals['subtotal']),
        ];

        if ($totals['show_delivery_fee'] && $totals['delivery_fee'] > 0) {
            $lines[] = 'Delivery Fee: ' . Helpers::set_symbol($totals['delivery_fee']);
        }

        $lines[] = 'Total: ' . Helpers::set_symbol($totals['total']);

        return $lines;
    }

    private static function formatPlacedAt(Order $order): string
    {
        $createdAt = $order->created_at;
        if ($createdAt === null) {
            return '';
        }

        try {
            return $createdAt->timezone(config('app.timezone', 'UTC'))->format('jS F Y, g:i A');
        } catch (Throwable) {
            return $createdAt->toDateTimeString();
        }
    }

    private static function fulfillmentLines(Order $order, mixed $address): array
    {
        $type = $order->order_type ?? '';
        $lines = [];

        if ($type === 'dine_in') {
            $tableNo = optional($order->table)->number ?? $order->table_id;
            if (! empty($tableNo)) {
                $lines[] = 'Table: ' . $tableNo;
            }
            if (! empty($order->number_of_people)) {
                $lines[] = 'Guests: ' . $order->number_of_people;
            }

            return $lines;
        }

        if ($type === 'take_away') {
            $lines[] = translate('take_away');

            return $lines;
        }

        if ($type === 'pos') {
            return $lines;
        }

        $lines = array_merge($lines, self::deliveryAddressLines($address, $order));

        return $lines;
    }

    private static function deliveryAddressLines(mixed $address, Order $order): array
    {
        if (is_object($address) && method_exists($address, 'toArray')) {
            $address = $address->toArray();
        }

        if (! is_array($address) || $address === []) {
            return [];
        }

        $parts = array_filter([
            $address['address'] ?? null,
            trim(implode(', ', array_filter([
                isset($address['house']) ? 'House ' . $address['house'] : null,
                isset($address['floor']) ? 'Floor ' . $address['floor'] : null,
                isset($address['road']) ? 'Road ' . $address['road'] : null,
            ]))),
        ]);

        if ($order->order_area && $order->order_area->area) {
            $parts[] = 'Area: ' . $order->order_area->area->area_name;
        }

        return $parts === [] ? [] : [implode("\n", $parts)];
    }

    private static function orderTypeLabel(string $type): string
    {
        return match ($type) {
            'take_away' => translate('take_away'),
            'dine_in' => translate('dine_in'),
            'pos' => 'POS',
            default => 'Delivery',
        };
    }

    private static function label(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }

    private static function fulfillmentHeading(string $type): string
    {
        return match ($type) {
            'take_away' => 'PICKUP',
            'dine_in' => 'DINE IN',
            'pos' => 'POS',
            default => 'DELIVERY',
        };
    }
}
