<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branches') || Schema::hasColumn('branches', 'online_orders_enabled')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('online_orders_enabled')->nullable()->default(true);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('branches') || ! Schema::hasColumn('branches', 'online_orders_enabled')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('online_orders_enabled');
        });
    }
};
