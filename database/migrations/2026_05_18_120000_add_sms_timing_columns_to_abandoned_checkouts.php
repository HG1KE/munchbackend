<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('abandoned_checkouts')) {
            return;
        }

        Schema::table('abandoned_checkouts', function (Blueprint $table) {
            if (! Schema::hasColumn('abandoned_checkouts', 'sms_queued_at')) {
                $table->timestamp('sms_queued_at')->nullable()->index()->after('sms_sent_at');
            }
            if (! Schema::hasColumn('abandoned_checkouts', 'sms_processed_at')) {
                $table->timestamp('sms_processed_at')->nullable()->index()->after('sms_queued_at');
            }
            if (! Schema::hasColumn('abandoned_checkouts', 'last_skip_reason')) {
                $table->string('last_skip_reason', 64)->nullable()->after('last_error');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('abandoned_checkouts')) {
            return;
        }

        Schema::table('abandoned_checkouts', function (Blueprint $table) {
            foreach (['sms_queued_at', 'sms_processed_at', 'last_skip_reason'] as $column) {
                if (Schema::hasColumn('abandoned_checkouts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
