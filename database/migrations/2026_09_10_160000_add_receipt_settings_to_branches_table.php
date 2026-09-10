<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branches') || Schema::hasColumn('branches', 'receipt_settings')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->longText('receipt_settings')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('branches') || ! Schema::hasColumn('branches', 'receipt_settings')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('receipt_settings');
        });
    }
};
