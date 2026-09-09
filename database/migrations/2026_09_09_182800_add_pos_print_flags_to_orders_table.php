<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'kitchen_printed_at')) {
                $table->timestamp('kitchen_printed_at')->nullable();
            }
            if (! Schema::hasColumn('orders', 'receipt_printed_at')) {
                $table->timestamp('receipt_printed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'kitchen_printed_at')) {
                $table->dropColumn('kitchen_printed_at');
            }
            if (Schema::hasColumn('orders', 'receipt_printed_at')) {
                $table->dropColumn('receipt_printed_at');
            }
        });
    }
};
