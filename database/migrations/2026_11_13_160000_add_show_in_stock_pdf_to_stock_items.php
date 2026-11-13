<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_items')) {
            return;
        }

        if (! Schema::hasColumn('stock_items', 'show_in_stock_pdf')) {
            Schema::table('stock_items', function (Blueprint $table) {
                $table->boolean('show_in_stock_pdf')->default(true)->after('status');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_items')) {
            return;
        }

        if (Schema::hasColumn('stock_items', 'show_in_stock_pdf')) {
            Schema::table('stock_items', function (Blueprint $table) {
                $table->dropColumn('show_in_stock_pdf');
            });
        }
    }
};
