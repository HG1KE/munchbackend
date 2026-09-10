<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderCancellationAuditLog extends Model
{
    protected $table = 'order_cancellation_audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'branch_id',
        'actor_type',
        'actor_id',
        'previous_status',
        'new_status',
        'reason',
        'source',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'branch_id' => 'integer',
        'actor_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
