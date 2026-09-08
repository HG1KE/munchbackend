<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reorder_reminder_logs')) {
            return;
        }

        Schema::create('reorder_reminder_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('phone', 32)->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('last_delivered_order_id')->index();
            $table->string('customer_name', 128)->nullable();
            $table->string('branch_name', 128)->nullable();
            $table->decimal('last_order_total', 12, 2)->nullable();
            $table->string('favorite_item', 255)->nullable();
            $table->date('last_order_date')->nullable();
            $table->string('status', 16)->default('queued')->index();
            $table->string('skip_reason', 64)->nullable();
            $table->unsignedTinyInteger('attempt_number')->default(1);
            $table->unsignedTinyInteger('sms_attempts')->default(0);
            $table->timestamp('sms_sent_at')->nullable()->index();
            $table->string('last_provider_status', 32)->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_delivered_order_id'], 'reorder_episode_idx');
            $table->index(['status', 'created_at'], 'reorder_status_created_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('reorder_reminder_logs')) {
            Schema::drop('reorder_reminder_logs');
        }
    }
};
