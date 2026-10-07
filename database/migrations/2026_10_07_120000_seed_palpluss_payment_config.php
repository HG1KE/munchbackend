<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('addon_settings')) {
            return;
        }

        $exists = DB::table('addon_settings')
            ->where('key_name', 'palpluss')
            ->where('settings_type', 'payment_config')
            ->exists();

        if ($exists) {
            return;
        }

        $values = [
            'gateway' => 'palpluss',
            'mode' => 'live',
            'status' => 0,
            'api_key_encrypted' => '',
            'channel_id' => '',
            'channel_shortcode' => '',
            'channel_type' => '',
            'channel_name' => '',
        ];

        DB::table('addon_settings')->insert([
            'id' => (string) Str::uuid(),
            'key_name' => 'palpluss',
            'live_values' => json_encode($values),
            'test_values' => json_encode($values),
            'settings_type' => 'payment_config',
            'mode' => 'live',
            'is_active' => 0,
            'additional_data' => json_encode([
                'gateway_title' => 'M-PESA (PalPluss)',
                'gateway_image' => '',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('addon_settings')) {
            return;
        }

        DB::table('addon_settings')
            ->where('key_name', 'palpluss')
            ->where('settings_type', 'payment_config')
            ->delete();
    }
};
