<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'sales_channel')) {
                $table->string('sales_channel', 32)->nullable()->after('order_type')->index();
            }
            if (! Schema::hasColumn('orders', 'client_uuid')) {
                $table->uuid('client_uuid')->nullable()->after('sales_channel');
            }
        });

        try {
            Schema::table('orders', function (Blueprint $table) {
                $table->unique(['branch_id', 'client_uuid'], 'orders_branch_client_uuid_unique');
            });
        } catch (\Throwable) {
            // Unique index already present.
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            try {
                $table->dropUnique('orders_branch_client_uuid_unique');
            } catch (\Throwable) {
                // Index may not exist.
            }
            if (Schema::hasColumn('orders', 'client_uuid')) {
                $table->dropColumn('client_uuid');
            }
            if (Schema::hasColumn('orders', 'sales_channel')) {
                $table->dropColumn('sales_channel');
            }
        });
    }
};
