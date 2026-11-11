<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('palpluss_unmatched_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_id', 64)->unique();
            $table->string('external_reference', 32)->nullable()->index();
            $table->string('phone', 20)->nullable()->index();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status', 32)->nullable();
            $table->string('mpesa_receipt', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->unsignedBigInteger('resolved_attempt_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('palpluss_unmatched_transactions');
    }
};
