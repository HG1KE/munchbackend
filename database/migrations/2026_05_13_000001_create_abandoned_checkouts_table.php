<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('abandoned_checkouts')) {
            return;
        }

        Schema::create('abandoned_checkouts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('guest_id')->nullable()->index();
            $table->tinyInteger('is_guest')->default(0);
            $table->string('phone', 32)->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->json('cart')->nullable();
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('expected_total', 12, 2)->nullable();
            $table->string('currency_code', 8)->nullable();
            $table->string('locale', 8)->nullable();
            $table->string('source', 32)->default('web');
            $table->string('client_token', 64)->nullable()->index();
            $table->timestamp('converted_at')->nullable()->index();
            $table->unsignedBigInteger('converted_order_id')->nullable()->index();
            $table->timestamp('sms_sent_at')->nullable()->index();
            $table->unsignedTinyInteger('sms_attempts')->default(0);
            $table->string('last_provider_status', 32)->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->index(['sms_sent_at', 'converted_at', 'created_at'], 'abandoned_reaper_idx');
            $table->index(['phone', 'created_at'], 'abandoned_phone_recent_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('abandoned_checkouts')) {
            Schema::drop('abandoned_checkouts');
        }
    }
};
