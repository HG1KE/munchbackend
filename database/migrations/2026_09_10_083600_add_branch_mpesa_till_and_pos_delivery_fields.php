<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('branches') && ! Schema::hasColumn('branches', 'mpesa_till')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->string('mpesa_till', 20)->nullable()->after('phone');
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (! Schema::hasColumn('orders', 'rider_name')) {
                    $table->string('rider_name', 191)->nullable()->after('delivery_man_id');
                }
                if (! Schema::hasColumn('orders', 'rider_phone')) {
                    $table->string('rider_phone', 32)->nullable()->after('rider_name');
                }
                if (! Schema::hasColumn('orders', 'customer_pos_delivery_sms_sent_at')) {
                    $table->timestamp('customer_pos_delivery_sms_sent_at')->nullable()->after('customer_processing_sms_sent_at');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('branches') && Schema::hasColumn('branches', 'mpesa_till')) {
            Schema::table('branches', function (Blueprint $table) {
                $table->dropColumn('mpesa_till');
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                foreach (['rider_name', 'rider_phone', 'customer_pos_delivery_sms_sent_at'] as $column) {
                    if (Schema::hasColumn('orders', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
