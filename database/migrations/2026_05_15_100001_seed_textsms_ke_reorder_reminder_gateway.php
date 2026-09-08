<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $payload = [
            'gateway' => 'textsms_ke_reorder_reminder',
            'mode' => 'test',
            'status' => 0,
            'message_template' => 'Hi {customer_name}, we miss you at {branch_name}! Your last order was {favorite_item} on {last_order_date}. Order again: {recovery_url}',
            'delay_days' => '14',
            'minimum_completed_orders' => '2',
            'max_attempts' => '1',
            'cooldown_days' => '30',
            'quiet_hours_start' => '21:00',
            'quiet_hours_end' => '08:00',
            'recovery_url' => '',
            'branch_ids' => '',
            'test_phone' => '',
            'is_otp_gateway' => 0,
        ];

        DB::table('addon_settings')->updateOrInsert(
            ['key_name' => 'textsms_ke_reorder_reminder'],
            [
                'id' => (string) Str::uuid(),
                'settings_type' => 'sms_config',
                'live_values' => json_encode($payload),
                'test_values' => json_encode($payload),
                'mode' => 'test',
                'is_active' => 0,
            ]
        );
    }

    public function down(): void
    {
        DB::table('addon_settings')
            ->where('key_name', 'textsms_ke_reorder_reminder')
            ->delete();
    }
};
