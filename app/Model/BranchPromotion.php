<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BranchPromotion extends Model
{
    protected $casts = [
        'branch_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'id');
    }

    public function getPromotionNameFullPathAttribute(): string
    {
        $image = $this->promotion_name ?? null;

        if (! empty($image)) {
            return asset('storage/app/public/promotion/' . $image);
        }

        return asset('public/assets/admin/img/160x160/img2.jpg');
    }

}
