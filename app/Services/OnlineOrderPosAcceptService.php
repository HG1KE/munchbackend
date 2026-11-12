<?php

namespace App\Services;

use App\CentralLogics\Helpers;
use App\Model\Branch;
use App\Model\Order;
use App\Services\Stock\StockDeductionService;
use App\Support\OnlineOrderStatus;
use App\Support\OrderPayableAmount;
use App\Support\PaymentMethodLabel;
use App\Support\PosOrderTypes;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Convert a branch online order into a Branch POS sale (same order row).
 *
 * Online rows stay outside POS stock/sold until accepted. Accept flips them into
 * the POS family (order_type=pos + sales_channel) without creating a second order.
 */
class OnlineOrderPosAcceptService
{
    public function __construct(
        private BranchPosTodayOrdersService $posTodayOrders,
        private DashboardOrderOperationsService $operations,
    ) {
    }

    /**
     * Alert payload for an open Branch POS session (branch-scoped only).
     *
     * @return array{new_order: int, order: ?array<string, mixed>}
     */
    public function pendingAlertPayload(int $branchId): array
    {
        $count = $this->operations->pendingQueueQuery($branchId)->count();
        if ($count < 1) {
            return [
                'new_order' => 0,
                'order' => null,
            ];
        }

        $order = $this->operations->pendingQueueQuery($branchId)
            ->with(['customer', 'guest', 'branch', 'order_area.area', 'details'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        return [
            'new_order' => $count,
            'order' => $order ? $this->previewPayload($order) : null,
        ];
    }

    /**
     * Kitchen-facing preview for the ringing ACCEPT modal.
     *
     * @return array<string, mixed>
     */
    public function previewPayload(Order $order): array
    {
        $card = $this->operations->formatOrderCard($order);
        $address = $this->resolveAddressText($order);
        // Selected delivery-charge area only (area-based checkout) — never lat/lng maps.
        $area = $this->selectedAreaName($order);
        $orderType = (string) ($order->order_type ?? 'delivery');
        $isPickup = $orderType === 'take_away' || $orderType === PosOrderTypes::TAKE_AWAY;
        $fulfillmentLabel = $isPickup ? translate('PICKUP') : translate('DELIVERY');
        $amounts = OrderPayableAmount::cardPayload($order);
        $items = $this->previewItems($order);

        return [
            'id' => (int) $order->id,
            'order_display_id' => Helpers::order_display_id($order),
            'customer_name' => (string) ($card['customer_name'] ?? translate('Customer')),
            'customer_phone' => $this->customerPhone($order),
            'address' => $address,
            'area' => $area,
            'address_line' => $isPickup ? $address : $this->addressLine($address, $area),
            'order_type' => $orderType,
            'is_pickup' => $isPickup,
            'is_delivery' => ! $isPickup,
            'fulfillment_label' => $fulfillmentLabel,
            'payment_status' => (string) ($order->payment_status ?? ''),
            'payment_method' => (string) ($order->payment_method ?? ''),
            'payment_method_label' => PaymentMethodLabel::operationalTitle($order->payment_method),
            'payment_status_label' => strtolower((string) ($order->payment_status ?? '')) === 'paid'
                ? translate('Paid')
                : translate('Unpaid'),
            'order_note' => trim((string) ($order->order_note ?? '')),
            'items' => $items,
            'item_lines' => array_map(
                static fn (array $item): string => $item['quantity'].'x '.$item['name']
                    .($item['options'] !== [] ? ' ('.implode(', ', $item['options']).')' : ''),
                $items
            ),
            'delivery_charge' => (float) ($amounts['delivery_charge'] ?? 0),
            'delivery_charge_formatted' => Helpers::set_symbol((float) ($amounts['delivery_charge'] ?? 0)),
            'order_amount' => (float) ($amounts['order_amount'] ?? 0),
            'order_amount_formatted' => (string) ($amounts['order_amount_formatted'] ?? ''),
            'grand_total' => (float) ($amounts['order_amount'] ?? 0),
            'grand_total_formatted' => Helpers::set_symbol((float) ($amounts['order_amount'] ?? 0)),
        ];
    }

    /**
     * Accept (or re-accept) an online order into Branch POS sales.
     *
     * @return array{
     *   success: bool,
     *   duplicate?: bool,
     *   idempotent?: bool,
     *   message?: string,
     *   code?: string,
     *   order?: Order,
     *   http?: int
     * }
     */
    public function accept(int $orderId, int $branchId): array
    {
        if ($orderId <= 0 || $branchId <= 0) {
            return [
                'success' => false,
                'message' => translate('Order not found'),
                'code' => 'not_found',
                'http' => 404,
            ];
        }

        try {
            return DB::transaction(function () use ($orderId, $branchId) {
                /** @var Order|null $order */
                $order = Order::query()
                    ->whereKey($orderId)
                    ->lockForUpdate()
                    ->first();

                if (! $order || (int) $order->branch_id !== $branchId) {
                    return [
                        'success' => false,
                        'message' => translate('Order not found'),
                        'code' => 'not_found',
                        'http' => 404,
                    ];
                }

                if (OnlineOrderStatus::isTerminal((string) $order->order_status)) {
                    return [
                        'success' => false,
                        'message' => translate('This order can no longer be accepted.'),
                        'code' => 'terminal',
                        'http' => 422,
                    ];
                }

                if (PosOrderTypes::isPosFamily($order->order_type, $order->sales_channel)) {
                    return [
                        'success' => true,
                        'duplicate' => true,
                        'idempotent' => true,
                        'message' => translate('order_placed_successfully'),
                        'order' => $order->fresh(['details', 'customer', 'customer_delivery_address', 'branch']) ?: $order,
                    ];
                }

                if (! PosOrderTypes::isOnlineOrder($order->order_type, $order->sales_channel)) {
                    return [
                        'success' => false,
                        'message' => translate('This order is not an online order.'),
                        'code' => 'not_online',
                        'http' => 422,
                    ];
                }

                $uiType = $this->uiTypeForOnlineOrder($order);
                $order->order_type = PosOrderTypes::databaseType($uiType);
                if (Schema::hasColumn($order->getTable(), 'sales_channel')) {
                    $order->sales_channel = PosOrderTypes::salesChannel($uiType);
                }
                $order->checked = 1;

                if (in_array((string) $order->order_status, OnlineOrderStatus::pendingQueueStatuses(), true)) {
                    $order->order_status = 'confirmed';
                }

                // Preserve payment_status / payment_method / amounts / details as-is.
                $order->save();

                $fresh = $order->fresh(['details', 'customer', 'customer_delivery_address', 'branch']) ?: $order;

                try {
                    app(StockDeductionService::class)->syncOrder($fresh);
                } catch (\Throwable) {
                    // Never block POS accept from stock ledger sync.
                }

                return [
                    'success' => true,
                    'duplicate' => false,
                    'idempotent' => false,
                    'message' => translate('order_placed_successfully'),
                    'order' => $fresh,
                ];
            });
        } catch (\Throwable $e) {
            info($e);

            // Race: another accept may have converted it already.
            $existing = Order::query()
                ->whereKey($orderId)
                ->where('branch_id', $branchId)
                ->first();
            if ($existing && PosOrderTypes::isPosFamily($existing->order_type, $existing->sales_channel)) {
                return [
                    'success' => true,
                    'duplicate' => true,
                    'idempotent' => true,
                    'message' => translate('order_placed_successfully'),
                    'order' => $existing->fresh(['details', 'customer', 'customer_delivery_address', 'branch']) ?: $existing,
                ];
            }

            return [
                'success' => false,
                'message' => translate('failed_to_place_order'),
                'code' => 'accept_failed',
                'http' => 500,
            ];
        }
    }

    /**
     * Build the same success JSON shape as Branch POS placeOrder.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function successPayload(Order $order, string $cashierName, array $extra = []): array
    {
        $fresh = $order->fresh(['details', 'customer', 'customer_delivery_address', 'branch']) ?: $order;

        return array_merge([
            'success' => 1,
            'order_id' => $fresh->id,
            'order_display_id' => Helpers::order_display_id($fresh),
            'message' => translate('order_placed_successfully'),
            'order' => $this->posTodayOrders->serializeOrder($fresh, $cashierName),
            'kitchen_printed' => $fresh->kitchen_printed_at !== null,
            'receipt_printed' => $fresh->receipt_printed_at !== null,
        ], $extra);
    }

    /**
     * Other active branches for the POS "send to different branch" picker.
     * Uses the same open/closed schedule logic as storefront availability.
     *
     * @return list<array{id: int, name: string, is_open: bool, status_label: string, selectable: bool}>
     */
    public function transferBranchOptions(int $currentBranchId): array
    {
        $now = Carbon::now();
        $dayOfWeek = (int) $now->dayOfWeek;
        $currentTime = $now->format('H:i:s');
        $restaurantSchedules = Helpers::restaurantSchedulesByDay();

        $branches = Branch::query()
            ->where('status', 1)
            ->when($currentBranchId > 0, fn ($q) => $q->where('id', '!=', $currentBranchId))
            ->with(['branch_time_schedules' => static function ($q) use ($dayOfWeek) {
                $q->where('day', $dayOfWeek)->select(['id', 'branch_id', 'day', 'opening_time', 'closing_time']);
            }])
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'status']);

        $options = [];
        foreach ($branches as $branch) {
            $isOpen = Helpers::isOpenForSchedules(
                Helpers::resolveTodaySchedulesForBranch($branch, $dayOfWeek, $restaurantSchedules),
                $currentTime
            );
            $options[] = [
                'id' => (int) $branch->id,
                'name' => (string) ($branch->name ?? ('Branch #'.$branch->id)),
                'is_open' => $isOpen,
                'status_label' => $isOpen ? translate('OPEN') : translate('CLOSED'),
                'selectable' => $isOpen,
            ];
        }

        return $options;
    }

    /**
     * Reassign a still-pending online order to another open branch (same order row).
     *
     * @return array{
     *   success: bool,
     *   duplicate?: bool,
     *   idempotent?: bool,
     *   message?: string,
     *   code?: string,
     *   order?: Order,
     *   branch_id?: int,
     *   branch_name?: string,
     *   http?: int
     * }
     */
    public function reassignToBranch(int $orderId, int $fromBranchId, int $toBranchId): array
    {
        if ($orderId <= 0 || $fromBranchId <= 0 || $toBranchId <= 0) {
            return [
                'success' => false,
                'message' => translate('Order not found'),
                'code' => 'not_found',
                'http' => 404,
            ];
        }

        if ($toBranchId === $fromBranchId) {
            return [
                'success' => false,
                'message' => translate('Choose a different branch.'),
                'code' => 'same_branch',
                'http' => 422,
            ];
        }

        $target = Branch::query()->whereKey($toBranchId)->first();
        if (! $target || (int) ($target->status ?? 0) !== 1) {
            return [
                'success' => false,
                'message' => translate('Branch not found'),
                'code' => 'branch_not_found',
                'http' => 404,
            ];
        }

        if (! Helpers::isBranchCurrentlyOpen((int) $target->id)) {
            return [
                'success' => false,
                'message' => translate('That branch is closed.'),
                'code' => 'branch_closed',
                'http' => 422,
            ];
        }

        $targetName = (string) ($target->name ?? ('Branch #'.$target->id));

        try {
            return DB::transaction(function () use ($orderId, $fromBranchId, $toBranchId, $targetName) {
                /** @var Order|null $order */
                $order = Order::query()
                    ->whereKey($orderId)
                    ->lockForUpdate()
                    ->first();

                if (! $order) {
                    return [
                        'success' => false,
                        'message' => translate('Order not found'),
                        'code' => 'not_found',
                        'http' => 404,
                    ];
                }

                // Already on the requested target and still pending online → idempotent OK.
                if ((int) $order->branch_id === $toBranchId
                    && $this->isPendingOnlineForTransfer($order)) {
                    return [
                        'success' => true,
                        'duplicate' => true,
                        'idempotent' => true,
                        'message' => translate('Order already sent to this branch.'),
                        'order' => $order,
                        'branch_id' => $toBranchId,
                        'branch_name' => $targetName,
                    ];
                }

                if ((int) $order->branch_id !== $fromBranchId) {
                    return [
                        'success' => false,
                        'message' => translate('This order is no longer at this branch.'),
                        'code' => 'wrong_branch',
                        'http' => 409,
                    ];
                }

                if (OnlineOrderStatus::isTerminal((string) $order->order_status)) {
                    return [
                        'success' => false,
                        'message' => translate('This order can no longer be moved.'),
                        'code' => 'terminal',
                        'http' => 422,
                    ];
                }

                if (PosOrderTypes::isPosFamily($order->order_type, $order->sales_channel)) {
                    return [
                        'success' => false,
                        'message' => translate('This order was already accepted into POS.'),
                        'code' => 'already_accepted',
                        'http' => 422,
                    ];
                }

                if (! PosOrderTypes::isOnlineOrder($order->order_type, $order->sales_channel)) {
                    return [
                        'success' => false,
                        'message' => translate('This order is not an online order.'),
                        'code' => 'not_online',
                        'http' => 422,
                    ];
                }

                if (! in_array((string) $order->order_status, OnlineOrderStatus::pendingQueueStatuses(), true)) {
                    return [
                        'success' => false,
                        'message' => translate('Only pending online orders can be sent to another branch.'),
                        'code' => 'not_pending',
                        'http' => 422,
                    ];
                }

                $order->branch_id = $toBranchId;
                $order->save();

                $fresh = $order->fresh(['branch']) ?: $order;

                return [
                    'success' => true,
                    'duplicate' => false,
                    'idempotent' => false,
                    'message' => translate('Order sent to').' '.$targetName,
                    'order' => $fresh,
                    'branch_id' => $toBranchId,
                    'branch_name' => (string) ($fresh->branch?->name ?? $targetName),
                ];
            });
        } catch (\Throwable $e) {
            info($e);

            $existing = Order::query()->whereKey($orderId)->first();
            if ($existing
                && (int) $existing->branch_id === $toBranchId
                && $this->isPendingOnlineForTransfer($existing)) {
                return [
                    'success' => true,
                    'duplicate' => true,
                    'idempotent' => true,
                    'message' => translate('Order already sent to this branch.'),
                    'order' => $existing,
                    'branch_id' => $toBranchId,
                    'branch_name' => $targetName,
                ];
            }

            return [
                'success' => false,
                'message' => translate('Could not send this order. Please try again.'),
                'code' => 'reassign_failed',
                'http' => 500,
            ];
        }
    }

    private function isPendingOnlineForTransfer(Order $order): bool
    {
        if (PosOrderTypes::isPosFamily($order->order_type, $order->sales_channel)) {
            return false;
        }
        if (! PosOrderTypes::isOnlineOrder($order->order_type, $order->sales_channel)) {
            return false;
        }

        return in_array((string) $order->order_status, OnlineOrderStatus::pendingQueueStatuses(), true);
    }

    private function uiTypeForOnlineOrder(Order $order): string
    {
        $type = (string) ($order->order_type ?? 'delivery');
        if ($type === 'take_away' || $type === PosOrderTypes::TAKE_AWAY) {
            return PosOrderTypes::TAKE_AWAY;
        }

        return PosOrderTypes::DELIVERY;
    }

    /**
     * @return list<array{name: string, quantity: int, options: list<string>, unit_price: float, line_total: float}>
     */
    private function previewItems(Order $order): array
    {
        if (! $order->relationLoaded('details')) {
            $order->load('details');
        }

        $items = [];
        foreach ($order->details as $detail) {
            $productDetails = $this->decodeArray($detail->product_details ?? null);
            $name = (string) ($productDetails['name'] ?? '');
            if ($name === '' && $detail->relationLoaded('product') && $detail->product) {
                $name = (string) ($detail->product->name ?? '');
            }
            if ($name === '') {
                $name = translate('Product unavailable');
            }

            $qty = max(1, (int) ($detail->quantity ?? 1));
            $unit = (float) ($detail->price ?? 0);
            $discount = (float) ($detail->discount_on_product ?? 0);
            $tax = (float) ($detail->tax_amount ?? 0);
            $lineTotal = ($unit - $discount + $tax) * $qty;

            $items[] = [
                'name' => $name,
                'quantity' => $qty,
                'options' => $this->optionLabels($detail, $productDetails),
                'unit_price' => $unit - $discount,
                'line_total' => $lineTotal,
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $productDetails
     * @return list<string>
     */
    private function optionLabels(mixed $detail, array $productDetails): array
    {
        $labels = [];
        $variation = $this->decodeArray($detail->variation ?? null);
        foreach ($variation as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (isset($row['values']) && is_array($row['values'])) {
                foreach ($row['values'] as $value) {
                    if (is_array($value) && ! empty($value['label'])) {
                        $labels[] = (string) $value['label'];
                    }
                }
            } elseif (! empty($row['type'])) {
                $labels[] = (string) $row['type'];
            }
        }

        $addonIds = $this->decodeList($detail->add_on_ids ?? null);
        $addonQtys = $this->decodeList($detail->add_on_qtys ?? null);
        foreach ($addonIds as $index => $rawId) {
            $addonId = (int) $rawId;
            if ($addonId <= 0) {
                continue;
            }
            $qty = max(1, (int) ($addonQtys[$index] ?? 1));
            $name = 'Addon #'.$addonId;
            foreach ($productDetails['add_ons'] ?? [] as $addon) {
                if (is_array($addon) && (int) ($addon['id'] ?? 0) === $addonId && ! empty($addon['name'])) {
                    $name = (string) $addon['name'];
                    break;
                }
            }
            $labels[] = $qty > 1 ? $name.' x'.$qty : $name;
        }

        return $labels;
    }

    private function customerPhone(Order $order): string
    {
        $address = $order->getAttributes()['delivery_address'] ?? null;
        if (is_string($address) && $address !== '') {
            $decoded = json_decode($address, true);
            $address = is_array($decoded) ? $decoded : null;
        }
        if (is_array($address)) {
            $phone = trim((string) ($address['contact_person_number'] ?? $address['phone'] ?? ''));
            if ($phone !== '') {
                return $phone;
            }
        }

        if ($order->customer && ! empty($order->customer->phone)) {
            return (string) $order->customer->phone;
        }

        return '';
    }

    private function resolveAddressText(Order $order): string
    {
        $raw = $order->getAttributes()['delivery_address'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return trim((string) ($decoded['address'] ?? $decoded['road'] ?? ''));
            }
        }
        if (is_array($raw)) {
            return trim((string) ($raw['address'] ?? $raw['road'] ?? ''));
        }

        return '';
    }

    /**
     * Delivery-charge area name from order_areas (selected at checkout). Empty when unset.
     */
    private function selectedAreaName(Order $order): string
    {
        if (! $order->relationLoaded('order_area')) {
            $order->load('order_area.area');
        } elseif ($order->order_area && ! $order->order_area->relationLoaded('area')) {
            $order->order_area->load('area');
        }

        $name = trim((string) ($order->order_area?->area?->area_name ?? ''));

        return $name;
    }

    private function addressLine(string $address, string $area): string
    {
        $address = trim($address);
        $area = trim($area);
        if ($address !== '' && $area !== '' && $area !== '—') {
            return $address.' · '.$area;
        }

        return $address !== '' ? $address : ($area !== '—' ? $area : '');
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decodeArray(mixed $value): array
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

    /**
     * @return list<mixed>
     */
    private function decodeList(mixed $value): array
    {
        $decoded = $this->decodeArray($value);

        return array_values($decoded);
    }
}
