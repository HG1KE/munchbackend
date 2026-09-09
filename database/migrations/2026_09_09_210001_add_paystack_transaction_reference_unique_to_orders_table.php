<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_BROAD_INDEX = 'orders_payment_method_transaction_reference_unique';

    private const PAYSTACK_UNIQUE_INDEX = 'idx_orders_paystack_tx_ref_unique';

    private const GENERATED_COLUMN = 'paystack_tx_ref_unique';

    public function up(): void
    {
        if (! Schema::hasTable('orders')
            || ! Schema::hasColumn('orders', 'payment_method')
            || ! Schema::hasColumn('orders', 'transaction_reference')) {
            return;
        }

        $this->dropLegacyBroadUniqueIndex();

        if (! Schema::hasColumn('orders', self::GENERATED_COLUMN)) {
            $this->addGeneratedColumn();
        }

        if ($this->hasPaystackReferenceDuplicates()) {
            Log::warning('paystack.migration_unique_index_skipped', [
                'reason' => 'duplicate_paystack_transaction_references',
                'index' => self::PAYSTACK_UNIQUE_INDEX,
            ]);

            return;
        }

        if ($this->indexExists('orders', self::PAYSTACK_UNIQUE_INDEX)) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->unique(self::GENERATED_COLUMN, self::PAYSTACK_UNIQUE_INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        $this->dropIndexIfExists('orders', self::PAYSTACK_UNIQUE_INDEX);

        if (Schema::hasColumn('orders', self::GENERATED_COLUMN)) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn(self::GENERATED_COLUMN);
            });
        }
    }

    private function dropLegacyBroadUniqueIndex(): void
    {
        if (! $this->indexExists('orders', self::LEGACY_BROAD_INDEX)) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(self::LEGACY_BROAD_INDEX);
        });

        Log::info('paystack.migration_legacy_broad_index_dropped', [
            'index' => self::LEGACY_BROAD_INDEX,
        ]);
    }

    private function addGeneratedColumn(): void
    {
        $expression = <<<'SQL'
CASE
    WHEN payment_method = 'paystack'
        AND transaction_reference IS NOT NULL
        AND transaction_reference <> ''
    THEN transaction_reference
    ELSE NULL
END
SQL;

        if ($this->driver() === 'sqlite') {
            DB::statement(sprintf(
                'ALTER TABLE orders ADD COLUMN %s VARCHAR(255) GENERATED ALWAYS AS (%s) STORED',
                self::GENERATED_COLUMN,
                $expression
            ));

            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE orders ADD COLUMN %s VARCHAR(255) GENERATED ALWAYS AS (%s) STORED AFTER transaction_reference',
            self::GENERATED_COLUMN,
            $expression
        ));
    }

    private function hasPaystackReferenceDuplicates(): bool
    {
        return DB::table('orders')
            ->select('transaction_reference', DB::raw('COUNT(*) as duplicate_count'))
            ->where('payment_method', 'paystack')
            ->whereNotNull('transaction_reference')
            ->where('transaction_reference', '!=', '')
            ->groupBy('transaction_reference')
            ->having('duplicate_count', '>', 1)
            ->exists();
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if ($this->driver() === 'sqlite') {
            $result = DB::select(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
                [$table, $indexName]
            );

            return $result !== [];
        }

        $result = DB::select(
            'SHOW INDEX FROM `' . str_replace('`', '``', $table) . '` WHERE Key_name = ?',
            [$indexName]
        );

        return $result !== [];
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (! $this->indexExists($table, $indexName)) {
            return;
        }

        if ($this->driver() === 'sqlite') {
            DB::statement('DROP INDEX ' . $indexName);

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($indexName) {
            $blueprint->dropIndex($indexName);
        });
    }

    private function driver(): string
    {
        return Schema::getConnection()->getDriverName();
    }
};
