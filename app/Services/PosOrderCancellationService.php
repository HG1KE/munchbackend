<?php

namespace App\Services;

use App\CentralLogics\PosOrderCancelledSms;
use App\Model\Order;
use App\Support\PosOrderTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PosOrderCancellationService
{
    public const STATUS = 'canceled';

    public const REASON_MIN = 5;

    public const REASON_MAX = 500;

    /**
     * @var list<string>
     */
    public const TERMINAL_STATUSES = [
        'canceled',
        'cancelled',
        'completed',
        'refunded',
        'failed',
        'returned',
    ];

    public function __construct(
        private OrderCancellationAuditLogger $auditLogger
    ) {}

    public static function normalizeReason(?string $reason): string
    {
        $reason = trim((string) $reason);
        $reason = preg_replace('/\s+/u', ' ', $reason) ?? $reason;

        return $reason;
    }

    public static function reasonError(?string $reason): ?string
    {
        $reason = self::normalizeReason($reason);
        $length = mb_strlen($reason);
        if ($length < self::REASON_MIN) {
            return 'Cancellation reason must be at least '.self::REASON_MIN.' characters.';
        }
        if ($length > self::REASON_MAX) {
            return 'Cancellation reason must be at most '.self::REASON_MAX.' characters.';
        }

        return null;
    }

    public static function isCancelledStatus(?string $status): bool
    {
        return in_array((string) $status, ['canceled', 'cancelled'], true);
    }

    public static function isCancellable(Order $order): bool
    {
        if (! $order->isPosFamily()) {
            return false;
        }

        if (! PosOrderTypes::allowsPosCancellation($order->sales_channel ?? null)) {
            return false;
        }

        $status = (string) $order->order_status;
        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            return false;
        }

        if ($status === 'delivered' && $order->isPosDeliveryOrder()) {
            return false;
        }

        return true;
    }

    public static function cancellableError(Order $order): ?string
    {
        if (! $order->isPosFamily()) {
            return 'Only Branch POS orders can be cancelled here.';
        }
        if (self::isCancelledStatus($order->order_status)) {
            return 'Order is already cancelled.';
        }
        if (! PosOrderTypes::allowsPosCancellation($order->sales_channel ?? null)) {
            return 'Marketplace orders cannot be cancelled from POS.';
        }
        if (! self::isCancellable($order)) {
            return 'This order can no longer be cancelled.';
        }

        return null;
    }

    /**
     * @return array{success: bool, duplicate: bool, message: string, order: Order, code?: string}
     */
    public function cancel(
        Order $order,
        string $reason,
        string $actorType,
        ?int $actorId,
        ?string $clientUuid = null,
        string $source = 'pos'
    ): array {
        $reason = self::normalizeReason($reason);
        $reasonError = self::reasonError($reason);
        if ($reasonError !== null) {
            return [
                'success' => false,
                'duplicate' => false,
                'message' => $reasonError,
                'order' => $order,
                'code' => 'reason',
            ];
        }

        $clientUuid = $clientUuid !== null ? trim($clientUuid) : '';
        $clientUuid = $clientUuid === '' ? null : $clientUuid;

        return DB::transaction(function () use ($order, $reason, $actorType, $actorId, $clientUuid, $source) {
            /** @var Order|null $locked */
            $locked = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return [
                    'success' => false,
                    'duplicate' => false,
                    'message' => 'Order not found',
                    'order' => $order,
                    'code' => 'not_found',
                ];
            }

            if (self::isCancelledStatus($locked->order_status)) {
                return [
                    'success' => true,
                    'duplicate' => true,
                    'message' => 'Order is already cancelled.',
                    'order' => $locked,
                ];
            }

            $cancellableError = self::cancellableError($locked);
            if ($cancellableError !== null) {
                return [
                    'success' => false,
                    'duplicate' => false,
                    'message' => $cancellableError,
                    'order' => $locked,
                    'code' => 'not_cancellable',
                ];
            }

            if ($clientUuid !== null
                && Schema::hasColumn('orders', 'pos_cancel_client_uuid')
                && (string) $locked->pos_cancel_client_uuid === $clientUuid) {
                return [
                    'success' => true,
                    'duplicate' => true,
                    'message' => 'Order is already cancelled.',
                    'order' => $locked,
                ];
            }

            $previousStatus = (string) $locked->order_status;
            $now = now();
            $locked->order_status = self::STATUS;
            if (Schema::hasColumn('orders', 'cancelled_by')) {
                $locked->cancelled_by = $actorId;
            }
            if (Schema::hasColumn('orders', 'cancelled_by_type')) {
                $locked->cancelled_by_type = $actorType !== '' ? $actorType : 'branch';
            }
            if (Schema::hasColumn('orders', 'cancelled_at')) {
                $locked->cancelled_at = $now;
            }
            if (Schema::hasColumn('orders', 'cancellation_reason')) {
                $locked->cancellation_reason = $reason;
            }
            if ($clientUuid !== null && Schema::hasColumn('orders', 'pos_cancel_client_uuid')) {
                $locked->pos_cancel_client_uuid = $clientUuid;
            }
            $locked->save();

            $this->auditLogger->record(
                $locked,
                $previousStatus,
                self::STATUS,
                $reason,
                $actorType !== '' ? $actorType : 'branch',
                $actorId,
                $source
            );

            return [
                'success' => true,
                'duplicate' => false,
                'message' => 'Order cancelled.',
                'order' => $locked->fresh() ?? $locked,
            ];
        });
    }

    public function dispatchSms(Order $order): void
    {
        PosOrderCancelledSms::dispatch($order);
    }

    public static function actorDisplayName(Order $order): string
    {
        $type = (string) ($order->cancelled_by_type ?: 'branch');
        if ($type === 'admin') {
            $admin = $order->cancelledByAdmin;
            $name = trim((string) (($admin->f_name ?? '').' '.($admin->l_name ?? '')));

            return $name !== '' ? $name : 'Master Admin';
        }

        $branch = $order->cancelledByBranch ?: $order->branch;
        $name = trim((string) ($branch->name ?? ''));

        return $name !== '' ? $name : 'Branch';
    }
}
