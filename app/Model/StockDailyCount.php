<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockDailyCount extends Model
{
    protected $fillable = [
        'branch_id',
        'stock_item_id',
        'count_date',
        'opening_qty',
        'closing_qty',
        'stock_manager_id',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'stock_item_id' => 'integer',
        'stock_manager_id' => 'integer',
        'opening_qty' => 'float',
        'closing_qty' => 'float',
        'count_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class, 'stock_item_id');
    }

    public function stockManager(): BelongsTo
    {
        return $this->belongsTo(StockManager::class, 'stock_manager_id');
    }

    /**
     * Physical consumption for the day: opening − closing.
     */
    public function physicalSold(): ?float
    {
        if ($this->closing_qty === null) {
            return null;
        }

        return (float) $this->opening_qty - (float) $this->closing_qty;
    }
}
