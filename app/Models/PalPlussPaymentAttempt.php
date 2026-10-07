<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PalPlussPaymentAttempt extends Model
{
    public const STATUS_INITIATED = 'initiated';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVERSED = 'reversed';

    protected $table = 'palpluss_payment_attempts';

    protected $fillable = [
        'payment_request_id',
        'transaction_id',
        'account_reference',
        'phone',
        'amount',
        'currency',
        'channel_id',
        'status',
        'provider_request_id',
        'provider_checkout_id',
        'mpesa_receipt',
        'result_code',
        'result_desc',
        'last_webhook_payload',
        'stk_initiated_at',
        'terminal_at',
        'fulfilled_at',
        'placed_order_id',
        'last_error',
    ];

    protected $casts = [
        'amount' => 'float',
        'last_webhook_payload' => 'array',
        'stk_initiated_at' => 'datetime',
        'terminal_at' => 'datetime',
        'fulfilled_at' => 'datetime',
    ];

    public function paymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'payment_request_id', 'id');
    }

    public function isTerminalFailure(): bool
    {
        return in_array($this->status, [
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_EXPIRED,
            self::STATUS_REVERSED,
        ], true);
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }
}
