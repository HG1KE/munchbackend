<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_items')) {
            Schema::create('stock_items', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->boolean('status')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('product_stock_allocations')) {
            Schema::create('product_stock_allocations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('stock_item_id');
                $table->decimal('quantity', 12, 3)->default(1);
                $table->timestamps();

                $table->unique(['product_id', 'stock_item_id'], 'product_stock_alloc_unique');
                $table->index('stock_item_id');
            });
        }

        if (! Schema::hasTable('stock_managers')) {
            Schema::create('stock_managers', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->unsignedBigInteger('branch_id');
                $table->boolean('status')->default(true);
                $table->rememberToken();
                $table->timestamps();

                $table->index('branch_id');
            });
        }

        if (! Schema::hasTable('stock_daily_counts')) {
            Schema::create('stock_daily_counts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('stock_item_id');
                $table->date('count_date');
                $table->decimal('opening_qty', 12, 3)->default(0);
                $table->decimal('closing_qty', 12, 3)->nullable();
                $table->unsignedBigInteger('stock_manager_id')->nullable();
                $table->timestamps();

                $table->unique(['branch_id', 'stock_item_id', 'count_date'], 'stock_daily_counts_unique');
                $table->index('count_date');
            });
        }

        if (! Schema::hasTable('stock_sale_deductions')) {
            Schema::create('stock_sale_deductions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('branch_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('stock_item_id');
                $table->decimal('quantity', 12, 3)->default(0);
                $table->date('sale_date');
                $table->timestamps();

                $table->unique(['order_id', 'product_id', 'stock_item_id'], 'stock_sale_deductions_unique');
                $table->index(['branch_id', 'sale_date', 'stock_item_id'], 'stock_sale_deductions_branch_date_item');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_sale_deductions');
        Schema::dropIfExists('stock_daily_counts');
        Schema::dropIfExists('stock_managers');
        Schema::dropIfExists('product_stock_allocations');
        Schema::dropIfExists('stock_items');
    }
};
