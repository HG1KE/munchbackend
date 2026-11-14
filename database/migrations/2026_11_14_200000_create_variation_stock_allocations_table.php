<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('variation_stock_allocations')) {
            return;
        }

        Schema::create('variation_stock_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->string('option_key', 255);
            $table->string('group_name', 191)->nullable();
            $table->string('option_label', 191)->nullable();
            $table->unsignedBigInteger('stock_item_id');
            $table->decimal('quantity', 12, 3)->default(1);
            $table->timestamps();

            $table->unique(
                ['product_id', 'option_key', 'stock_item_id'],
                'variation_stock_alloc_unique'
            );
            $table->index('stock_item_id');
            $table->index(['product_id', 'option_key'], 'variation_stock_alloc_product_option');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variation_stock_allocations');
    }
};
