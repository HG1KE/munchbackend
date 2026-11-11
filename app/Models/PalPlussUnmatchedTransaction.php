<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PalPlussUnmatchedTransaction extends Model
{
    protected $table = 'palpluss_unmatched_transactions';

    protected $fillable = [
        'transaction_id',
        'external_reference',
        'phone',
        'amount',
        'currency',
        'status',
        'mpesa_receipt',
        'payload',
        'resolved_at',
        'resolved_attempt_id',
    ];

    protected $casts = [
        'amount' => 'float',
        'payload' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
