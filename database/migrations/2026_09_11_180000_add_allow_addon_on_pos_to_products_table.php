<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || Schema::hasColumn('products', 'allow_addon_on_pos')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('allow_addon_on_pos')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'allow_addon_on_pos')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('allow_addon_on_pos');
        });
    }
};
