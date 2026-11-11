<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_daily_counts')) {
            return;
        }

        if (! Schema::hasColumn('stock_daily_counts', 'received_qty')) {
            Schema::table('stock_daily_counts', function (Blueprint $table) {
                $table->decimal('received_qty', 12, 3)->nullable()->after('opening_qty');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_daily_counts')) {
            return;
        }

        if (Schema::hasColumn('stock_daily_counts', 'received_qty')) {
            Schema::table('stock_daily_counts', function (Blueprint $table) {
                $table->dropColumn('received_qty');
            });
        }
    }
};
