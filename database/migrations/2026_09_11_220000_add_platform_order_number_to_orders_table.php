<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || Schema::hasColumn('orders', 'platform_order_number')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('platform_order_number', 64)->nullable()->after('sales_channel')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'platform_order_number')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('platform_order_number');
        });
    }
};
