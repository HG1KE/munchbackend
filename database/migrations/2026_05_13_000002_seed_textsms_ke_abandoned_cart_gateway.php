<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $payload = [
            'gateway' => 'textsms_ke_abandoned_cart',
            'mode' => 'test',
            'status' => 0,
            'api_key' => '',
            'partner_id' => '',
            'sender_id' => '',
            'message_template' => 'Hi {customer_name}, you left items in your cart at {branch_name}. Total {currency} {order_amount}. Complete your order: {recovery_url}',
            'delay_minutes' => '30',
            'max_attempts' => '1',
            'cooldown_hours' => '24',
            'quiet_hours_start' => '21:00',
            'quiet_hours_end' => '08:00',
            'recovery_url' => '',
            'is_otp_gateway' => 0,
        ];

        DB::table('addon_settings')->updateOrInsert(
            [
                'key_name' => 'textsms_ke_abandoned_cart',
            ],
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
            ->where('key_name', 'textsms_ke_abandoned_cart')
            ->delete();
    }
};