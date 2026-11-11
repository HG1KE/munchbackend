<?php

namespace App\Services;

use App\Model\Order;
use App\Model\OrderDetail;
use App\Models\OrderChangeAmount;
use App\Services\Stock\StockDeductionService;
use App\Support\PosOrderTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PosOrderEditService
{
    public const PRINTED_MESSAGE = 'Order cannot be edited because a kitchen order or receipt has already been printed.';

    public const CANCELLED_MESSAGE = 'Cancelled orders cannot be edited.';

    public const MARKETPLACE_MESSAGE = 'Marketplace orders cannot be edited.';

    public static function isPrinted(Order $order): bool
    {
        return $order->kitchen_printed_at !== null || $order->receipt_printed_at !== null;
    }

    public static function isEditable(Order $order): bool
    {
        return self::editableError($order) === null;
    }

    public static function editableError(Order $order): ?string
    {
        if (PosOrderCancellationService::isCancelledStatus($order->order_status)
            || $order->cancelled_at !== null) {
            return self::CANCELLED_MESSAGE;
        }

        if (! $order->isPosFamily()) {
            return 'Only Branch POS orders can be edited.';
        }

        if (PosOrderTypes::isMarketplaceChannel($order->sales_channel ?? null)) {
            return self::MARKETPLACE_MESSAGE;
        }

        if (self::isPrinted($order)) {
            return self::PRINTED_MESSAGE;
        }

        $status = (string) $order->order_status;
        if (in_array($status, PosOrderCancellationService::TERMINAL_STATUSES, true)) {
            return 'This order can no longer be edited.';
        }

        return null;
    }

    public static function printBlockedMessage(Order $order, string $ticket): ?string
    {
        if (! PosOrderCancellationService::isCancelledStatus($order->order_status)) {
            return null;
        }

        if ($ticket === 'kitchen') {
            return 'Cancelled orders cannot print kitchen tickets';
        }

        if ($ticket === 'receipt') {
            return 'Cancelled orders cannot print a customer receipt.';
        }

        return null;
    }

    /**
     * Replace lines and totals on an existing POS order. Identity fields stay put
     * (id, readable_order_id, client_uuid, placed_at). Sales channel / order type
     * may change when the cashier corrects the UI type during edit.
     *
     * @param  array{details: list<array<string, mixed>>, extra_discount: float, total_tax_amount: float, delivery_charge: float, order_amount: float, order_note?: ?string}  $parts
     * @return array{success: bool, message: string, order: Order, code?: string}
     */
    public function apply(Order $order, array $parts, string $paymentMethod, ?string $orderType = null): array
    {
        return DB::transaction(function () use ($order, $parts, $paymentMethod, $orderType) {
            /** @var Order|null $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! $locked) {
                return [
                    'success' => false,
                    'message' => 'Order not found',
                    'order' => $order,
                    'code' => 'not_found',
                ];
            }

            $error = self::editableError($locked);
            if ($error !== null) {
                return [
                    'success' => false,
                    'message' => $error,
                    'order' => $locked,
                    'code' => 'not_editable',
                ];
            }

            $previousUiType = PosOrderTypes::uiTypeFromSalesChannel($locked->sales_channel);
            $uiType = $orderType !== null && $orderType !== ''
                ? PosOrderTypes::normalize($orderType)
                : $previousUiType;
            $previousChannel = (string) $locked->sales_channel;
            $newChannel = PosOrderTypes::salesChannel($uiType);

            $locked->order_type = PosOrderTypes::databaseType($uiType);
            $locked->sales_channel = $newChannel;

            // Match place defaults (takeaway→delivered, dine_in→confirmed, etc.) when
            // the channel changes on an unprinted editable order, but only if status is
            // still the previous channel's place default — do not clobber intentional
            // kitchen progress beyond that early state.
            if ($previousChannel !== $newChannel
                && (string) $locked->order_status === PosOrderTypes::defaultStatus($previousUiType)) {
                $locked->order_status = PosOrderTypes::defaultStatus($uiType);
            }

            $locked->extra_discount = PosOrderTypes::cashierExtraDiscount($parts['extra_discount'] ?? 0);
            $locked->total_tax_amount = $parts['total_tax_amount'];
            $locked->delivery_charge = $parts['delivery_charge'];
            $locked->order_amount = $parts['order_amount'];
            $locked->coupon_discount_amount = 0.00;
            $locked->payment_method = $paymentMethod;
            $locked->payment_status = PosOrderTypes::isPaidImmediately($uiType, $paymentMethod) ? 'paid' : 'unpaid';
            if (array_key_exists('order_note', $parts)) {
                $locked->order_note = $parts['order_note'];
            }
            $locked->save();

            OrderDetail::query()->where('order_id', $locked->id)->delete();
            $details = [];
            foreach ($parts['details'] as $row) {
                $row['order_id'] = $locked->id;
                $details[] = $row;
            }
            if ($details !== []) {
                OrderDetail::insert($details);
            }

            $this->syncPaymentRecord($locked, $paymentMethod);

            $fresh = $locked->fresh(['details']) ?? $locked;
            try {
                app(StockDeductionService::class)->syncOrder($fresh);
            } catch (\Throwable) {
                // Never block POS edit from stock ledger sync.
            }

            return [
                'success' => true,
                'message' => 'Order updated.',
                'order' => $fresh,
            ];
        });
    }

    private function syncPaymentRecord(Order $order, string $paymentMethod): void
    {
        if (! Schema::hasTable('order_change_amounts')) {
            return;
        }

        $existing = OrderChangeAmount::query()->where('order_id', $order->id)->first();
        if (! PosOrderTypes::isImmediatePosPayment($paymentMethod)) {
            if ($existing) {
                $existing->order_amount = $order->order_amount;
                $existing->save();
            }

            return;
        }

        try {
            OrderChangeAmount::query()->updateOrInsert(
                ['order_id' => $order->id],
                [
                    'order_amount' => $order->order_amount,
                    'paid_amount' => $order->order_amount,
                ]
            );
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            OrderChangeAmount::query()->where('order_id', $order->id)->update([
                'order_amount' => $order->order_amount,
                'paid_amount' => $order->order_amount,
            ]);
        }
    }
}
