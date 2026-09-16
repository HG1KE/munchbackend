<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('admin_id')->nullable()->after('user_id');
            $table->string('idempotency_key', 64)->nullable()->unique()->after('reference');
            $table->index('admin_id');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['admin_id']);
            $table->dropColumn(['admin_id', 'idempotency_key']);
        });
    }
};
