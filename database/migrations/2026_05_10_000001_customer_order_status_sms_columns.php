<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            if (! Schema::hasColumn('orders', 'customer_placement_sms_sent_at')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->timestamp('customer_placement_sms_sent_at')->nullable();
                });
            }
            if (! Schema::hasColumn('orders', 'customer_processing_sms_sent_at')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->timestamp('customer_processing_sms_sent_at')->nullable();
                });
            }
        }

        $rows = DB::table('addon_settings')
            ->where('key_name', 'textsms_ke_customer_confirm')
            ->where('settings_type', 'sms_config')
            ->get();

        foreach ($rows as $row) {
            foreach (['live_values', 'test_values'] as $col) {
                $raw = $row->{$col};
                if ($raw === null) {
                    continue;
                }
                $decoded = json_decode($raw, true);
                if (! is_array($decoded)) {
                    continue;
                }

                if (! isset($decoded['order_placed_template']) || $decoded['order_placed_template'] === '') {
                    if (! empty($decoded['notification_template'])) {
                        $decoded['order_placed_template'] = $decoded['notification_template'];
                    } else {
                        $decoded['order_placed_template'] = 'Thank you {customer_name}! Order #{order_id} placed at {branch_name}. Total {order_amount}. Status: {order_status}.';
                    }
                }

                if (! isset($decoded['processing_template']) || $decoded['processing_template'] === '') {
                    $decoded['processing_template'] = '{branch_name} is processing your order #{order_id}.';
                }

                DB::table('addon_settings')->where('id', $row->id)->update([
                    $col => json_encode($decoded),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            if (Schema::hasColumn('orders', 'customer_placement_sms_sent_at')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->dropColumn('customer_placement_sms_sent_at');
                });
            }
            if (Schema::hasColumn('orders', 'customer_processing_sms_sent_at')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->dropColumn('customer_processing_sms_sent_at');
                });
            }
        }
    }
};
