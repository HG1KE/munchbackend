<?php

namespace App\Model;

use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AbandonedCheckout extends Model
{
    protected $table = 'abandoned_checkouts';

    protected $fillable = [
        'user_id',
        'guest_id',
        'is_guest',
        'phone',
        'branch_id',
        'cart',
        'item_count',
        'expected_total',
        'currency_code',
        'locale',
        'source',
        'client_token',
        'converted_at',
        'converted_order_id',
        'sms_sent_at',
        'sms_queued_at',
        'sms_processed_at',
        'sms_attempts',
        'last_provider_status',
        'last_error',
        'last_skip_reason',
    ];

    protected $casts = [
        'cart' => 'array',
        'expected_total' => 'float',
        'item_count' => 'integer',
        'user_id' => 'integer',
        'guest_id' => 'integer',
        'branch_id' => 'integer',
        'is_guest' => 'integer',
        'converted_order_id' => 'integer',
        'sms_attempts' => 'integer',
        'converted_at' => 'datetime',
        'sms_sent_at' => 'datetime',
        'sms_queued_at' => 'datetime',
        'sms_processed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('converted_at')->whereNull('sms_sent_at');
    }
}
