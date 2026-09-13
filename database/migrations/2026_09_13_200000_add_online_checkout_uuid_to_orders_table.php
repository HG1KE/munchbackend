<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Online checkout attempt key. Separate from POS client_uuid so
     * orders_branch_client_uuid_unique is unchanged. Historical online
     * rows stay NULL (MySQL/SQLite unique allows multiple NULLs).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'online_checkout_uuid')) {
                $table->string('online_checkout_uuid', 64)->nullable()->after('client_uuid');
            }
        });

        try {
            Schema::table('orders', function (Blueprint $table) {
                $table->unique('online_checkout_uuid', 'orders_online_checkout_uuid_unique');
            });
        } catch (\Throwable) {
            // Unique index already present.
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            try {
                $table->dropUnique('orders_online_checkout_uuid_unique');
            } catch (\Throwable) {
                // Index may not exist.
            }
            if (Schema::hasColumn('orders', 'online_checkout_uuid')) {
                $table->dropColumn('online_checkout_uuid');
            }
        });
    }
};
