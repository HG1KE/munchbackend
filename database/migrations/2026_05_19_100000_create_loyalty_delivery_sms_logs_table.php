<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('loyalty_delivery_sms_logs')) {
            return;
        }

        Schema::create('loyalty_delivery_sms_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('phone', 32)->index();
            $table->string('customer_name', 128)->nullable();
            $table->unsignedInteger('earned_points')->default(0);
            $table->unsignedInteger('points_balance')->default(0);
            $table->string('status', 16)->default('pending')->index();
            $table->string('skip_reason', 64)->nullable();
            $table->string('last_provider_status', 32)->nullable();
            $table->string('last_error', 255)->nullable();
            $table->text('message_body')->nullable();
            $table->timestamp('sms_sent_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'loyalty_delivery_sms_status_created_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('loyalty_delivery_sms_logs')) {
            Schema::drop('loyalty_delivery_sms_logs');
        }
    }
};
