<?php

namespace App\Services;

use App\Model\Order;
use App\Support\PosOrderTypes;
use App\Support\TimezoneDisplay;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class BranchPosTodayOrdersService
{
    public const PER_PAGE = 50;

    /**
     * @return array<string, mixed>
     */
    public function forBranch(int $branchId, string $cashierName, ?string $search, ?string $filter, int $page): array
    {
        $tz = TimezoneDisplay::businessTimezone();
        $start = Carbon::now($tz)->startOfDay()->utc()->toDateTimeString();
        $end = Carbon::now($tz)->endOfDay()->utc()->toDateTimeString();

        $query = Order::query()
            ->with(['details', 'customer', 'customer_delivery_address', 'order_change_amount', 'branch', 'cancelledByBranch', 'cancelledByAdmin'])
            ->where('branch_id', $branchId)
            ->whereIn('sales_channel', PosOrderTypes::salesChannels())
            ->whereBetween('created_at', [$start, $end])
            ->latest('id');

        $this->applyFilter($query, $filter);
        $this->applySearch($query, $search);

        /** @var LengthAwarePaginator $pageData */
        $pageData = $query->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));

        return [
            'orders' => collect($pageData->items())->map(fn (Order $order) => $this->serialize($order, $cashierName))->values()->all(),
            'page' => $pageData->currentPage(),
            'last_page' => $pageData->lastPage(),
            'total' => $pageData->total(),
        ];
    }

    private function applyFilter($query, ?string $filter): void
    {
        $filter = (string) $filter;
        if ($filter === '' || $filter === 'all') {
            return;
        }
        if (in_array($filter, PosOrderTypes::salesChannels(), true)) {
            $query->where('sales_channel', $filter);

            return;
        }
        if ($filter === 'completed') {
            $query->whereIn('order_status', ['delivered']);

            return;
        }
        if ($filter === 'cancelled') {
            $query->whereIn('order_status', ['canceled', 'cancelled', 'failed', 'returned']);

            return;
        }
        if ($filter === 'active') {
            $query->whereNotIn('order_status', ['delivered', 'canceled', 'cancelled', 'failed', 'returned']);
        }
    }

    private function applySearch($query, ?string $search): void
    {
        $search = trim((string) $search);
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';
        $query->where(function ($inner) use ($search, $like) {
            $normalized = strtoupper(ltrim($search, '#'));
            $inner->where('id', 'like', $like)
                ->orWhere('readable_order_id', 'like', '%'.$normalized.'%')
                ->orWhereHas('customer', function ($customer) use ($like) {
                    $customer->where('f_name', 'like', $like)
                        ->orWhere('l_name', 'like', $like)
                        ->orWhere('phone', 'like', $like);
                })
                ->orWhereHas('delivery_address', function ($address) use ($like) {
                    $address->where('contact_person_name', 'like', $like)
                        ->orWhere('contact_person_number', 'like', $like);
                });
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeOrder(Order $order, string $cashierName): array
    {
        return $this->serialize($order, $cashierName);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Order $order, string $cashierName): array
    {
        $tz = TimezoneDisplay::businessTimezone();
        $created = TimezoneDisplay::parseStoredUtc($order->created_at)?->timezone($tz);
        $status = (string) $order->order_status;
        $completed = in_array($status, ['delivered', 'canceled', 'cancelled'], true)
            ? TimezoneDisplay::parseStoredUtc($order->updated_at)?->timezone($tz)
            : null;

        $deliveryCustomer = $this->deliveryCustomer($order);
        $customerName = $deliveryCustomer['name'];
        if ($customerName === '') {
            $customerName = trim((string) (($order->customer?->f_name.' '.$order->customer?->l_name) ?: ''));
        }
        if ($customerName === '' && (string) $order->sales_channel !== 'delivery') {
            $customerName = 'Walk-in';
        }
        $phone = $deliveryCustomer['phone'] !== ''
            ? $deliveryCustomer['phone']
            : (string) ($order->customer?->phone ?: '');

        $grand = (float) $order->order_amount;
        $items = [];
        $subtotal = 0.0;
        $itemDiscount = 0.0;
        foreach ($order->details as $detail) {
            $line = $this->serializeItem($detail);
            $items[] = $line;
            $subtotal += $line['line_total'];
            $itemDiscount += $line['discount'] * $line['quantity'];
        }

        $branchName = (string) ($order->branch?->name ?: '');
        if ($branchName === '') {
            $branchName = $cashierName !== '' ? $cashierName : 'POS';
        }

        return [
            'id' => (int) $order->id,
            'number' => \App\CentralLogics\Helpers::order_display_id($order),
            'date' => $created?->format('d M Y') ?: '',
            'time' => $created?->format('H:i') ?: '',
            'created_at' => $created?->format('d M Y H:i') ?: '',
            'completed_at' => $completed?->format('d M Y H:i'),
            'sales_channel' => (string) $order->sales_channel,
            'sales_channel_label' => PosOrderTypes::channelLabel($order->sales_channel, $order->order_type),
            'branch' => $branchName,
            'cashier' => $cashierName !== '' ? $cashierName : $branchName,
            'customer' => $customerName,
            'phone' => $phone,
            'address' => $order->sales_channel === 'delivery' ? $deliveryCustomer['address'] : '',
            'notes' => trim((string) ($order->order_note ?: '')),
            'delivery_fee' => (float) $order->delivery_charge,
            'discount' => (float) $order->extra_discount + $itemDiscount,
            'subtotal' => $subtotal,
            'grand_total' => $grand,
            'payment_method' => (string) $order->payment_method,
            'payment_status' => (string) $order->payment_status,
            'order_status' => $status,
            'order_status_label' => $this->statusLabel($status),
            'cash_received' => 0,
            'change' => 0,
            'mpesa_till' => trim((string) ($order->branch?->mpesa_till ?? '')),
            'rider_name' => trim((string) ($order->rider_name ?? '')),
            'rider_phone' => trim((string) ($order->rider_phone ?? '')),
            'kitchen_printed' => $order->kitchen_printed_at !== null,
            'receipt_printed' => $order->receipt_printed_at !== null,
            'cancellable' => \App\Services\PosOrderCancellationService::isCancellable($order),
            'cancellation_reason' => trim((string) ($order->cancellation_reason ?? '')),
            'cancelled_by' => \App\Services\PosOrderCancellationService::isCancelledStatus($status)
                ? \App\Services\PosOrderCancellationService::actorDisplayName($order)
                : '',
            'cancelled_at' => $this->cancelledAtLabel($order),
            'items' => $items,
            'items_summary' => array_map(fn ($item) => $item['quantity'].'x '.$item['name'], $items),
        ];
    }

    /**
     * @return array{name: string, phone: string, address: string}
     */
    public function deliveryCustomer(Order $order): array
    {
        return [
            'name' => $this->addressField($order, 'contact_person_name'),
            'phone' => $this->addressField($order, 'contact_person_number') ?: $this->addressField($order, 'phone'),
            'address' => $this->addressField($order, 'address'),
        ];
    }

    /**
     * `orders.delivery_address` is JSON (array cast) and also a relation name.
     * Never read it with object `->` — Delivery POS rows crash the All tab if you do.
     */
    private function addressField(Order $order, string $key): string
    {
        $rawColumn = $order->getRawOriginal('delivery_address');
        if (is_string($rawColumn) && $rawColumn !== '') {
            $decoded = json_decode($rawColumn, true);
            if (is_array($decoded)) {
                $value = trim((string) ($decoded[$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        $related = $order->relationLoaded('customer_delivery_address')
            ? $order->getRelation('customer_delivery_address')
            : null;
        if (is_object($related)) {
            $value = trim((string) ($related->{$key} ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $raw = $order->getAttributes()['delivery_address'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : null;
        }
        if (is_array($raw)) {
            return trim((string) ($raw[$key] ?? ''));
        }
        if (is_object($raw)) {
            return trim((string) ($raw->{$key} ?? ''));
        }

        return '';
    }

    /**
     * Kitchen-ticket option labels from stored order_details.variation JSON.
     *
     * @param  mixed  $variation
     * @return list<string>
     */
    public static function variationOptionLabels($variation): array
    {
        if (is_string($variation)) {
            $variation = json_decode($variation, true);
        }
        if (! is_array($variation)) {
            return [];
        }

        $labels = [];
        foreach ($variation as $group) {
            if (! is_array($group)) {
                continue;
            }
            $values = $group['values'] ?? null;
            if (is_array($values) && array_key_exists('label', $values)) {
                foreach ((array) $values['label'] as $label) {
                    $label = trim((string) $label);
                    if ($label !== '') {
                        $labels[] = $label;
                    }
                }
                continue;
            }
            if (! is_array($values)) {
                continue;
            }
            foreach ($values as $value) {
                if (is_array($value) && isset($value['label'])) {
                    $label = trim((string) $value['label']);
                    if ($label !== '') {
                        $labels[] = $label;
                    }
                } elseif (is_string($value) && trim($value) !== '') {
                    $labels[] = trim($value);
                }
            }
        }

        return $labels;
    }

    /**
     * @param  \App\Model\OrderDetail  $detail
     * @return array<string, mixed>
     */
    private function serializeItem($detail): array
    {
        $raw = $detail->product_details;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $name = is_array($raw) ? (string) ($raw['name'] ?? 'Item') : (string) ($detail->product?->name ?: 'Item');
        $qty = max(1, (int) $detail->quantity);
        $unit = (float) $detail->price;
        $discount = (float) $detail->discount_on_product;
        $addonTotal = 0.0;
        $addonPrices = json_decode((string) $detail->add_on_prices, true);
        $addonQtys = json_decode((string) $detail->add_on_qtys, true);
        if (is_array($addonPrices)) {
            foreach ($addonPrices as $i => $price) {
                $addonTotal += ((float) $price) * (int) ($addonQtys[$i] ?? 1);
            }
        }

        return [
            'name' => $name,
            'quantity' => $qty,
            'options' => self::variationOptionLabels($detail->variation),
            'unit_price' => $unit,
            'discount' => $discount,
            'line_total' => max(0, ($unit - $discount) * $qty + $addonTotal),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'delivered' => 'Completed',
            'canceled', 'cancelled' => 'Cancelled',
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'processing' => 'Preparing',
            'picked_up', 'out_for_delivery' => 'Out for delivery',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    private function cancelledAtLabel(Order $order): ?string
    {
        if (! \App\Services\PosOrderCancellationService::isCancelledStatus((string) $order->order_status)) {
            return null;
        }

        $tz = TimezoneDisplay::businessTimezone();
        $at = TimezoneDisplay::parseStoredUtc($order->cancelled_at ?? $order->updated_at)?->timezone($tz);

        return $at?->format('d M Y H:i');
    }
}
