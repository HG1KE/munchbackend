<?php

namespace App\Services;

use App\Model\Order;
use App\Model\WalletTransaction;
use App\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Restores the wallet portion applied to an order when that order is cancelled.
 *
 * Amount source of truth: net wallet ledger for the order reference
 * (sum of order_place debits − sum of order_refund credits). Partial
 * wallet + other tender only refunds the wallet debit portion.
 */
class OrderWalletCancelRefundService
{
    public const TRANSACTION_TYPE_PLACE = 'order_place';

    public const TRANSACTION_TYPE_REFUND = 'order_refund';

    /**
     * @return array{
     *     refunded: bool,
     *     amount: float,
     *     replayed: bool,
     *     skipped: bool,
     *     reason?: string,
     *     transaction?: WalletTransaction|null
     * }
     */
    public function refundIfNeeded(Order $order): array
    {
        if (! Schema::hasTable('wallet_transactions')) {
            return [
                'refunded' => false,
                'amount' => 0.0,
                'replayed' => false,
                'skipped' => true,
                'reason' => 'no_wallet_table',
            ];
        }

        $userId = (int) ($order->user_id ?? 0);
        if ($userId <= 0 || (int) ($order->is_guest ?? 0) === 1) {
            return [
                'refunded' => false,
                'amount' => 0.0,
                'replayed' => false,
                'skipped' => true,
                'reason' => 'no_customer',
            ];
        }

        $orderId = (int) $order->id;
        if ($orderId <= 0) {
            return [
                'refunded' => false,
                'amount' => 0.0,
                'replayed' => false,
                'skipped' => true,
                'reason' => 'invalid_order',
            ];
        }

        try {
            return DB::transaction(function () use ($userId, $orderId) {
                $user = User::query()->whereKey($userId)->lockForUpdate()->first();
                if (! $user) {
                    return [
                        'refunded' => false,
                        'amount' => 0.0,
                        'replayed' => false,
                        'skipped' => true,
                        'reason' => 'customer_missing',
                    ];
                }

                // Serialize concurrent cancel refunds on this order's ledger rows.
                WalletTransaction::query()
                    ->where('user_id', $userId)
                    ->where('reference', (string) $orderId)
                    ->whereIn('transaction_type', [self::TRANSACTION_TYPE_PLACE, self::TRANSACTION_TYPE_REFUND])
                    ->lockForUpdate()
                    ->get();

                $placed = $this->placedWalletAmount($userId, $orderId);
                $refunded = $this->refundedWalletAmount($userId, $orderId);
                $amount = round(max(0, $placed - $refunded), 3);
                if ($amount <= 0) {
                    $hadWallet = $placed > 0 || $refunded > 0;

                    return [
                        'refunded' => false,
                        'amount' => 0.0,
                        'replayed' => $hadWallet,
                        'skipped' => ! $hadWallet,
                        'reason' => $hadWallet ? 'already_refunded' : 'no_wallet_debit',
                        'transaction' => null,
                    ];
                }

                $currentBalance = round((float) $user->wallet_balance, 3);
                $newBalance = round($currentBalance + $amount, 3);

                $walletTransaction = new WalletTransaction();
                $walletTransaction->user_id = $user->id;
                $walletTransaction->transaction_id = 'ORDCXL_'.str_replace('-', '', (string) Str::uuid());
                $walletTransaction->reference = (string) $orderId;
                $walletTransaction->transaction_type = self::TRANSACTION_TYPE_REFUND;
                $walletTransaction->credit = $amount;
                $walletTransaction->debit = 0;
                $walletTransaction->balance = $newBalance;
                $walletTransaction->admin_bonus = 0;
                if (Schema::hasColumn('wallet_transactions', 'idempotency_key')) {
                    $walletTransaction->idempotency_key = $this->cancelRefundIdempotencyKey($orderId);
                }
                $walletTransaction->created_at = now();
                $walletTransaction->updated_at = now();

                $user->wallet_balance = $newBalance;
                $user->save();
                $walletTransaction->save();

                return [
                    'refunded' => true,
                    'amount' => $amount,
                    'replayed' => false,
                    'skipped' => false,
                    'transaction' => $walletTransaction,
                ];
            });
        } catch (\Throwable $exception) {
            // Unique idempotency_key race: treat as already refunded.
            if ($this->isUniqueConstraintViolation($exception)) {
                $existing = WalletTransaction::query()
                    ->where('idempotency_key', $this->cancelRefundIdempotencyKey($orderId))
                    ->first();

                if ($existing) {
                    return [
                        'refunded' => false,
                        'amount' => (float) $existing->credit,
                        'replayed' => true,
                        'skipped' => false,
                        'reason' => 'idempotent_replay',
                        'transaction' => $existing,
                    ];
                }
            }

            Log::error('order_wallet_cancel_refund_failed', [
                'order_id' => $orderId,
                'user_id' => $userId,
                'message' => $exception->getMessage(),
            ]);

            return [
                'refunded' => false,
                'amount' => 0.0,
                'replayed' => false,
                'skipped' => true,
                'reason' => 'exception',
            ];
        }
    }

    /**
     * Wallet amount still owed back to the customer for this order.
     */
    public function refundableWalletAmount(int $userId, int $orderId): float
    {
        return round(max(0, $this->placedWalletAmount($userId, $orderId) - $this->refundedWalletAmount($userId, $orderId)), 3);
    }

    public function placedWalletAmount(int $userId, int $orderId): float
    {
        return (float) WalletTransaction::query()
            ->where('user_id', $userId)
            ->where('reference', (string) $orderId)
            ->where('transaction_type', self::TRANSACTION_TYPE_PLACE)
            ->sum('debit');
    }

    public function refundedWalletAmount(int $userId, int $orderId): float
    {
        return (float) WalletTransaction::query()
            ->where('user_id', $userId)
            ->where('reference', (string) $orderId)
            ->where('transaction_type', self::TRANSACTION_TYPE_REFUND)
            ->sum('credit');
    }

    public function cancelRefundIdempotencyKey(int $orderId): string
    {
        return 'order_cancel_wallet_refund:'.$orderId;
    }

    private function isUniqueConstraintViolation(\Throwable $exception): bool
    {
        $message = $exception->getMessage();
        if (str_contains($message, 'UNIQUE') || str_contains($message, 'Duplicate')) {
            return true;
        }

        if ($exception instanceof \Illuminate\Database\QueryException) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

            return $sqlState === '23000' || $sqlState === '23505';
        }

        return false;
    }
}
