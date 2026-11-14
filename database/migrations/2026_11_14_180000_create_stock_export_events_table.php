<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_export_events')) {
            return;
        }

        Schema::create('stock_export_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->date('count_date');
            $table->unsignedBigInteger('stock_manager_id')->nullable();
            $table->string('exporter_role', 32)->default('stocks'); // stocks|admin
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->json('payload');
            $table->string('payload_hash', 64);
            $table->string('pdf_hash', 64)->nullable();
            $table->json('omitted')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'count_date'], 'stock_export_events_branch_date');
            $table->index('payload_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_export_events');
    }
};
