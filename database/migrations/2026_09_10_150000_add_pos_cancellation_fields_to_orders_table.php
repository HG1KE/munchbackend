<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'cancelled_by')) {
                $table->unsignedBigInteger('cancelled_by')->nullable()->after('order_status');
            }
            if (! Schema::hasColumn('orders', 'cancelled_by_type')) {
                $table->string('cancelled_by_type', 16)->nullable()->after('cancelled_by');
            }
            if (! Schema::hasColumn('orders', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('cancelled_by_type');
            }
            if (! Schema::hasColumn('orders', 'cancellation_reason')) {
                $table->string('cancellation_reason', 500)->nullable()->after('cancelled_at');
            }
            if (! Schema::hasColumn('orders', 'pos_cancel_client_uuid')) {
                $table->string('pos_cancel_client_uuid', 64)->nullable()->after('cancellation_reason');
            }
            if (! Schema::hasColumn('orders', 'pos_cancelled_sms_sent_at')) {
                $table->timestamp('pos_cancelled_sms_sent_at')->nullable()->after('pos_cancel_client_uuid');
            }
        });

        if (Schema::hasColumn('orders', 'pos_cancel_client_uuid')
            && Schema::hasColumn('orders', 'branch_id')) {
            try {
                Schema::table('orders', function (Blueprint $table) {
                    $table->unique(['branch_id', 'pos_cancel_client_uuid'], 'orders_branch_pos_cancel_uuid_unique');
                });
            } catch (\Throwable) {
                // Index may already exist on a retried deploy.
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            try {
                $table->dropUnique('orders_branch_pos_cancel_uuid_unique');
            } catch (\Throwable) {
                //
            }
            foreach ([
                'pos_cancelled_sms_sent_at',
                'pos_cancel_client_uuid',
                'cancellation_reason',
                'cancelled_at',
                'cancelled_by_type',
                'cancelled_by',
            ] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
