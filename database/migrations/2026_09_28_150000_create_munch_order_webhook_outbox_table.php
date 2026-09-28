<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('munch_order_webhook_outbox')) {
            return;
        }

        Schema::create('munch_order_webhook_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 64);
            $table->unsignedBigInteger('order_id');
            $table->string('event_key', 128);
            $table->json('payload');
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['event_type', 'order_id'], 'munch_order_webhook_outbox_event_order_unique');
            $table->index(['status', 'created_at'], 'munch_order_webhook_outbox_status_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('munch_order_webhook_outbox');
    }
};
