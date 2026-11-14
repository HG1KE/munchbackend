<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('addon_stock_allocations')) {
            Schema::create('addon_stock_allocations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('addon_id');
                $table->unsignedBigInteger('stock_item_id');
                $table->decimal('quantity', 12, 3)->default(1);
                $table->timestamps();

                $table->unique(['addon_id', 'stock_item_id'], 'addon_stock_alloc_unique');
                $table->index('stock_item_id');
            });
        }

        if (Schema::hasTable('stock_sale_deductions') && ! Schema::hasColumn('stock_sale_deductions', 'addon_id')) {
            Schema::table('stock_sale_deductions', function (Blueprint $table) {
                $table->unsignedBigInteger('addon_id')->default(0)->after('product_id');
            });

            // Replace product-only unique with product+addon so addon rows
            // (product_id=0, addon_id>0) cannot collide with product rows.
            Schema::table('stock_sale_deductions', function (Blueprint $table) {
                $table->dropUnique('stock_sale_deductions_unique');
            });
            Schema::table('stock_sale_deductions', function (Blueprint $table) {
                $table->unique(
                    ['order_id', 'product_id', 'addon_id', 'stock_item_id'],
                    'stock_sale_deductions_unique'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_sale_deductions') && Schema::hasColumn('stock_sale_deductions', 'addon_id')) {
            Schema::table('stock_sale_deductions', function (Blueprint $table) {
                $table->dropUnique('stock_sale_deductions_unique');
            });
            Schema::table('stock_sale_deductions', function (Blueprint $table) {
                $table->dropColumn('addon_id');
                $table->unique(['order_id', 'product_id', 'stock_item_id'], 'stock_sale_deductions_unique');
            });
        }

        Schema::dropIfExists('addon_stock_allocations');
    }
};
