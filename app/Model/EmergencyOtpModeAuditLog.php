<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyOtpModeAuditLog extends Model
{
    protected $fillable = [
        'admin_id',
        'action',
        'enabled',
        'ip_address',
        'reason',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'admin_id' => 'integer',
        'enabled' => 'boolean',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
