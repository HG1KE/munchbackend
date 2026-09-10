<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branches') || Schema::hasColumn('branches', 'pos_mpesa_enabled')) {
            return;
        }

        $afterTill = Schema::hasColumn('branches', 'mpesa_till');

        Schema::table('branches', function (Blueprint $table) use ($afterTill) {
            if ($afterTill) {
                $table->boolean('pos_mpesa_enabled')->default(true)->after('mpesa_till');
            } else {
                $table->boolean('pos_mpesa_enabled')->default(true);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('branches') || ! Schema::hasColumn('branches', 'pos_mpesa_enabled')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('pos_mpesa_enabled');
        });
    }
};
