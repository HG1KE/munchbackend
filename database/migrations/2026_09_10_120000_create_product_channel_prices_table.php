<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_channel_prices')) {
            Schema::create('product_channel_prices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('branch_id');
                $table->string('channel', 16);
                $table->decimal('price', 24, 2)->nullable();
                $table->unsignedTinyInteger('is_available')->default(1);
                $table->timestamps();

                $table->unique(['product_id', 'branch_id', 'channel'], 'product_channel_prices_unique');
                $table->index(['branch_id', 'channel']);
                $table->index('product_id');
            });
        }

        if (! Schema::hasTable('product_by_branches') || ! Schema::hasTable('products')) {
            return;
        }

        if (DB::table('product_channel_prices')->exists()) {
            return;
        }

        $now = now();
        DB::table('product_by_branches')->orderBy('id')->chunkById(500, function ($rows) use ($now) {
            $productIds = $rows->pluck('product_id')->unique()->all();
            $defaults = DB::table('products')->whereIn('id', $productIds)->pluck('price', 'id');
            $inserts = [];

            foreach ($rows as $row) {
                $default = (float) ($defaults[$row->product_id] ?? 0);
                $branchPrice = (float) $row->price;
                $inserts[] = [
                    'product_id' => (int) $row->product_id,
                    'branch_id' => (int) $row->branch_id,
                    'channel' => 'pos',
                    'price' => abs($branchPrice - $default) > 0.009 ? $branchPrice : null,
                    'is_available' => (int) $row->is_available,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($inserts !== []) {
                DB::table('product_channel_prices')->insert($inserts);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_channel_prices');
    }
};
