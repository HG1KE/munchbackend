<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_cancellation_audit_logs')
            || Schema::hasColumn('order_cancellation_audit_logs', 'payment_status')) {
            return;
        }

        Schema::table('order_cancellation_audit_logs', function (Blueprint $table) {
            $table->string('payment_status', 32)->nullable()->after('previous_status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('order_cancellation_audit_logs')
            || ! Schema::hasColumn('order_cancellation_audit_logs', 'payment_status')) {
            return;
        }

        Schema::table('order_cancellation_audit_logs', function (Blueprint $table) {
            $table->dropColumn('payment_status');
        });
    }
};
