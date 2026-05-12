<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && !Schema::hasColumn('orders', 'customer_confirmed_sms_sent_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->timestamp('customer_confirmed_sms_sent_at')->nullable();
            });
        }

        $defaults = [
            'gateway' => 'textsms_ke_customer_confirm',
            'mode' => 'test',
            'status' => 0,
            'api_key' => '',
            'partner_id' => '',
            'sender_id' => '',
            'notification_template' => 'Hi {customer_name}, order #{order_id} at {branch_name} is {order_status}. Amount: {order_amount}',
            'is_otp_gateway' => 0,
        ];
        $json = json_encode($defaults);

        DB::table('addon_settings')->updateOrInsert(
            ['key_name' => 'textsms_ke_customer_confirm', 'settings_type' => 'sms_config'],
            [
                'key_name' => 'textsms_ke_customer_confirm',
                'live_values' => $json,
                'test_values' => $json,
                'settings_type' => 'sms_config',
                'mode' => 'test',
                'is_active' => 0,
            ]
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'customer_confirmed_sms_sent_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('customer_confirmed_sms_sent_at');
            });
        }

        DB::table('addon_settings')
            ->where('key_name', 'textsms_ke_customer_confirm')
            ->where('settings_type', 'sms_config')
            ->delete();
    }
};
