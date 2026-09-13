<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_change_amounts')) {
            return;
        }

        $duplicates = DB::table('order_change_amounts')
            ->select('order_id', DB::raw('COUNT(*) as c'))
            ->groupBy('order_id')
            ->having('c', '>', 1)
            ->limit(1)
            ->exists();

        if ($duplicates) {
            return;
        }

        try {
            Schema::table('order_change_amounts', function (Blueprint $table) {
                $table->unique('order_id', 'order_change_amounts_order_id_unique');
            });
        } catch (\Throwable) {
            // Unique index already present.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_change_amounts')) {
            return;
        }

        try {
            Schema::table('order_change_amounts', function (Blueprint $table) {
                $table->dropUnique('order_change_amounts_order_id_unique');
            });
        } catch (\Throwable) {
            //
        }
    }
};
