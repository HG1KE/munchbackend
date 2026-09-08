<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

class OrderAutomationRun extends Model
{
    protected $table = 'order_automation_runs';

    protected $fillable = [
        'run_type',
        'dry_run',
        'admin_id',
        'eligible_count',
        'completed_count',
        'skipped_count',
        'failed_count',
        'settings_snapshot',
        'entries',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'dry_run' => 'boolean',
        'eligible_count' => 'integer',
        'completed_count' => 'integer',
        'skipped_count' => 'integer',
        'failed_count' => 'integer',
        'settings_snapshot' => 'array',
        'entries' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];
}
