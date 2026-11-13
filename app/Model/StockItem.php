<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockItem extends Model
{
    protected $fillable = [
        'name',
        'status',
        'show_in_stock_pdf',
    ];

    protected $casts = [
        'status' => 'boolean',
        'show_in_stock_pdf' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ProductStockAllocation::class, 'stock_item_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_stock_allocations', 'stock_item_id', 'product_id')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    public function dailyCounts(): HasMany
    {
        return $this->hasMany(StockDailyCount::class, 'stock_item_id');
    }

    public function saleDeductions(): HasMany
    {
        return $this->hasMany(StockSaleDeduction::class, 'stock_item_id');
    }
}
