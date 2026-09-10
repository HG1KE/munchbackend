<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_cancellation_audit_logs')) {
            return;
        }

        Schema::create('order_cancellation_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('actor_type', 16)->default('branch');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('previous_status', 32)->nullable();
            $table->string('new_status', 32)->default('canceled');
            $table->string('reason', 500);
            $table->string('source', 32)->default('pos');
            $table->string('ip_address', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'created_at']);
            $table->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_cancellation_audit_logs');
    }
};
