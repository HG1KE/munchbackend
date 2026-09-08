<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dateTime('visible_from')->nullable()->after('available_time_ends');
            $table->dateTime('visible_until')->nullable()->after('visible_from');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['visible_from', 'visible_until']);
        });
    }
};
