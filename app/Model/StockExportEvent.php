<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockExportEvent extends Model
{
    protected $fillable = [
        'branch_id',
        'count_date',
        'stock_manager_id',
        'exporter_role',
        'admin_id',
        'payload',
        'payload_hash',
        'pdf_hash',
        'omitted',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'stock_manager_id' => 'integer',
        'admin_id' => 'integer',
        'count_date' => 'date',
        'payload' => 'array',
        'omitted' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function stockManager(): BelongsTo
    {
        return $this->belongsTo(StockManager::class, 'stock_manager_id');
    }
}
