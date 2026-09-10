<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_price_audit_logs')) {
            return;
        }
        if (Schema::hasColumn('product_price_audit_logs', 'source_branch_id')) {
            return;
        }

        Schema::table('product_price_audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('source_branch_id')->nullable()->after('branch_id');
            $table->index('source_branch_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_price_audit_logs') || ! Schema::hasColumn('product_price_audit_logs', 'source_branch_id')) {
            return;
        }

        Schema::table('product_price_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['source_branch_id']);
            $table->dropColumn('source_branch_id');
        });
    }
};
