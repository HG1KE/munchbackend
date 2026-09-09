<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentRequest extends Model
{
    use HasFactory;
    use HasUuid;

    protected $table = 'payment_requests';

    public const PLACEMENT_PENDING = 'pending';

    public const PLACEMENT_PLACED = 'placed';

    public const PLACEMENT_FAILED = 'failed';

    public const PLACEMENT_FAILED_TERMINAL = 'failed_terminal';

    public const PLACEMENT_RECONCILED = 'reconciled';

    public const PLACEMENT_CANCELLED = 'cancelled';

    public const PLACEMENT_GATEWAY_NOT_PAID = 'gateway_not_paid';

    protected $casts = [
        'place_order_draft' => 'array',
        'is_paid' => 'integer',
    ];

    /**
     * @return list<string>
     */
    public static function recoverablePlacementStatuses(): array
    {
        return [
            self::PLACEMENT_PENDING,
            self::PLACEMENT_FAILED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function excludedRecoveryPlacementStatuses(): array
    {
        return [
            self::PLACEMENT_PLACED,
            self::PLACEMENT_FAILED_TERMINAL,
            self::PLACEMENT_RECONCILED,
            self::PLACEMENT_CANCELLED,
            self::PLACEMENT_GATEWAY_NOT_PAID,
        ];
    }

    public function hasPlacedOrder(): bool
    {
        return $this->placed_order_id !== null && (int) $this->placed_order_id > 0;
    }

    public function isEligibleForRecovery(): bool
    {
        if ((int) ($this->is_paid ?? 0) !== 1) {
            return false;
        }

        if ($this->hasPlacedOrder()) {
            return false;
        }

        if ($this->isInconsistentPlacedState()) {
            return false;
        }

        $status = (string) ($this->placement_status ?? '');

        if ($status === self::PLACEMENT_PLACED) {
            return false;
        }

        if (in_array($status, self::excludedRecoveryPlacementStatuses(), true)) {
            return false;
        }

        return in_array($status, self::recoverablePlacementStatuses(), true);
    }

    public function isInconsistentPlacedState(): bool
    {
        return (string) ($this->placement_status ?? '') === self::PLACEMENT_PLACED
            && ! $this->hasPlacedOrder();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<PaymentRequest>  $query
     * @return \Illuminate\Database\Eloquent\Builder<PaymentRequest>
     */
    public function scopePaystackRecoveryCandidates($query, int $minAgeMinutes = 2)
    {
        $cutoff = now()->subMinutes(max(1, $minAgeMinutes));

        return $query
            ->where('attribute', 'order')
            ->where('payment_method', 'paystack')
            ->where('is_paid', 1)
            ->whereNull('placed_order_id')
            ->whereIn('placement_status', self::recoverablePlacementStatuses())
            ->where('placement_status', '!=', self::PLACEMENT_PLACED)
            ->where('created_at', '<=', $cutoff)
            ->whereNotNull('transaction_id')
            ->where('transaction_id', '!=', '');
    }
}
