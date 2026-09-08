<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

class OrderAutomationSetting extends Model
{
    protected $table = 'order_automation_settings';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'is_enabled',
        'auto_complete_hours',
        'eligible_statuses',
        'excluded_statuses',
        'branch_ids',
        'dry_run',
        'require_delivery_man',
    ];

    protected $casts = [
        'id' => 'integer',
        'is_enabled' => 'boolean',
        'auto_complete_hours' => 'integer',
        'eligible_statuses' => 'array',
        'excluded_statuses' => 'array',
        'dry_run' => 'boolean',
        'require_delivery_man' => 'boolean',
    ];

    public static function current(): self
    {
        $settings = self::query()->find(1);
        if ($settings) {
            return $settings;
        }

        return self::query()->create([
            'id' => 1,
            'is_enabled' => false,
            'auto_complete_hours' => 24,
            'eligible_statuses' => config('order_automation.default_eligible_statuses'),
            'excluded_statuses' => config('order_automation.default_excluded_statuses'),
            'branch_ids' => null,
            'dry_run' => false,
            'require_delivery_man' => false,
        ]);
    }
}
