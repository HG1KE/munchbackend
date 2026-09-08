<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_automation_settings')) {
            return;
        }

        if (! Schema::hasColumn('order_automation_settings', 'require_delivery_man')) {
            Schema::table('order_automation_settings', function (Blueprint $table) {
                $table->boolean('require_delivery_man')->default(false)->after('dry_run');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_automation_settings')
            && Schema::hasColumn('order_automation_settings', 'require_delivery_man')) {
            Schema::table('order_automation_settings', function (Blueprint $table) {
                $table->dropColumn('require_delivery_man');
            });
        }
    }
};
