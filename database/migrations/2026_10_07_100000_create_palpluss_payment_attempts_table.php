<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('palpluss_payment_attempts')) {
            return;
        }

        Schema::create('palpluss_payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('payment_request_id')->index();
            $table->string('transaction_id', 64)->nullable()->unique();
            $table->string('account_reference', 12);
            $table->string('phone', 20);
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('KES');
            $table->string('channel_id', 64)->nullable();
            $table->string('status', 32)->default('initiated');
            $table->string('provider_request_id', 128)->nullable();
            $table->string('provider_checkout_id', 128)->nullable();
            $table->string('mpesa_receipt', 64)->nullable();
            $table->string('result_code', 32)->nullable();
            $table->string('result_desc', 255)->nullable();
            $table->json('last_webhook_payload')->nullable();
            $table->timestamp('stk_initiated_at')->nullable();
            $table->timestamp('terminal_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->unsignedBigInteger('placed_order_id')->nullable()->index();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();

            $table->index(['payment_request_id', 'status']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('palpluss_payment_attempts');
    }
};
