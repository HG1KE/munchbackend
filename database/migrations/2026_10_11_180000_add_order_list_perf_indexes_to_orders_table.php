<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meatco hot-path indexes: ring poll on checked + order_status;
 * customer-list / badge aggregates on user_id + order_status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!$this->indexExists('orders', 'orders_checked_order_status_index')) {
                $table->index(['checked', 'order_status'], 'orders_checked_order_status_index');
            }
            if (!$this->indexExists('orders', 'orders_user_id_order_status_index')) {
                $table->index(['user_id', 'order_status'], 'orders_user_id_order_status_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if ($this->indexExists('orders', 'orders_checked_order_status_index')) {
                $table->dropIndex('orders_checked_order_status_index');
            }
            if ($this->indexExists('orders', 'orders_user_id_order_status_index')) {
                $table->dropIndex('orders_user_id_order_status_index');
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        if (method_exists(Schema::class, 'hasIndex')) {
            return Schema::hasIndex($table, $index);
        }

        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();
        $rows = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $index]
        );

        return count($rows) > 0;
    }
};
