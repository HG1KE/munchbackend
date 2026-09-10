<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceAuditLog extends Model
{
    protected $table = 'product_price_audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'admin_id',
        'actor_type',
        'actor_id',
        'product_id',
        'branch_id',
        'source_branch_id',
        'channel',
        'field',
        'old_value',
        'new_value',
        'source',
        'ip_address',
        'created_at',
    ];

    protected $casts = [
        'admin_id' => 'integer',
        'actor_id' => 'integer',
        'product_id' => 'integer',
        'branch_id' => 'integer',
        'source_branch_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
