<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $defaultTemplate = "Your Munch order was delivered successfully 😋\n\n+{earned_points} loyalty points added.\n\nYou now have {current_points} points.";

        $payload = [
            'gateway' => 'textsms_ke_loyalty_delivery',
            'mode' => 'test',
            'status' => 0,
            'message_template' => $defaultTemplate,
            'test_phone' => '',
            'is_otp_gateway' => 0,
        ];

        DB::table('addon_settings')->updateOrInsert(
            ['key_name' => 'textsms_ke_loyalty_delivery'],
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
            ->where('key_name', 'textsms_ke_loyalty_delivery')
            ->delete();
    }
};
