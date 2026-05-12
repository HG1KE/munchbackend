<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AddTextSmsKeNotGateway extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Check if the entry already exists
        $exists = DB::table('addon_settings')
            ->where('key_name', 'textsms_ke_not')
            ->where('settings_type', 'sms_config')
            ->exists();

        if (!$exists) {
            // Insert the new SMS gateway configuration
            DB::table('addon_settings')->insert([
                'id' => Str::uuid()->toString(),
                'key_name' => 'textsms_ke_not',
                'live_values' => json_encode([
                    'gateway' => 'textsms_ke_not',
                    'mode' => 'live',
                    'status' => 0,
                    'api_key' => null,
                    'partner_id' => null,
                    'sender_id' => null,
                    'notification_template' => null,
                    'is_otp_gateway' => 0 // This is the key difference - not an OTP gateway
                ]),
                'test_values' => json_encode([
                    'gateway' => 'textsms_ke_not',
                    'mode' => 'live',
                    'status' => 0,
                    'api_key' => null,
                    'partner_id' => null,
                    'sender_id' => null,
                    'notification_template' => null,
                    'is_otp_gateway' => 0
                ]),
                'settings_type' => 'sms_config',
                'mode' => 'live',
                'is_active' => 0,
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Remove the SMS gateway configuration
        DB::table('addon_settings')
            ->where('key_name', 'textsms_ke_not')
            ->where('settings_type', 'sms_config')
            ->delete();
    }
}