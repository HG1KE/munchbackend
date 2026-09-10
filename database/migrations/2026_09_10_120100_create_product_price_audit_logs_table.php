<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_price_audit_logs')) {
            return;
        }

        Schema::create('product_price_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('actor_type', 16)->default('admin');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('channel', 16);
            $table->string('field', 32);
            $table->string('old_value', 64)->nullable();
            $table->string('new_value', 64)->nullable();
            $table->string('source', 32)->default('drawer');
            $table->string('ip_address', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['product_id', 'created_at']);
            $table->index(['branch_id', 'channel']);
            $table->index('admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_audit_logs');
    }
};
