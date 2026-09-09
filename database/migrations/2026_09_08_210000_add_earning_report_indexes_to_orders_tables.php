<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && ! $this->hasIndexOn('orders', ['order_status', 'created_at'])) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index(['order_status', 'created_at'], 'orders_order_status_created_at_index');
            });
        }

        if (Schema::hasTable('order_details') && ! $this->hasIndexOn('order_details', ['order_id'])) {
            Schema::table('order_details', function (Blueprint $table) {
                $table->index(['order_id'], 'order_details_order_id_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && $this->indexExists('orders', 'orders_order_status_created_at_index')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('orders_order_status_created_at_index');
            });
        }

        if (Schema::hasTable('order_details') && $this->indexExists('order_details', 'order_details_order_id_index')) {
            Schema::table('order_details', function (Blueprint $table) {
                $table->dropIndex('order_details_order_id_index');
            });
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndexOn(string $table, array $columns): bool
    {
        $grouped = [];
        foreach ($this->indexes($table) as $row) {
            $grouped[$row->Key_name][(int) $row->Seq_in_index] = $row->Column_name;
        }

        foreach ($grouped as $ordered) {
            ksort($ordered);
            if (array_values($ordered) === $columns) {
                return true;
            }
        }

        return false;
    }

    private function indexExists(string $table, string $index): bool
    {
        foreach ($this->indexes($table) as $row) {
            if ($row->Key_name === $index) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<object{Key_name: string, Seq_in_index: int|string, Column_name: string}>
     */
    private function indexes(string $table): array
    {
        return DB::select('SHOW INDEX FROM `'.$table.'`');
    }
};
