<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * New marketplace POS rows get a unique dedupe key. Historical rows stay
     * NULL (including the existing Glovo #797 pair) so this migration is safe.
     */
    public function up(): void
    {
        if (! Schema::hasTable('orders') || Schema::hasColumn('orders', 'marketplace_dedupe_key')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('marketplace_dedupe_key', 128)->nullable()->after('platform_order_number');
        });

        try {
            Schema::table('orders', function (Blueprint $table) {
                $table->unique('marketplace_dedupe_key', 'orders_marketplace_dedupe_key_unique');
            });
        } catch (\Throwable) {
            // Unique index already present.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'marketplace_dedupe_key')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            try {
                $table->dropUnique('orders_marketplace_dedupe_key_unique');
            } catch (\Throwable) {
                //
            }
            $table->dropColumn('marketplace_dedupe_key');
        });
    }
};
