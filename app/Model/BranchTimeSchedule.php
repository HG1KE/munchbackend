<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchTimeSchedule extends Model
{
    protected $fillable = [
        'branch_id',
        'day',
        'opening_time',
        'closing_time'
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'day' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}