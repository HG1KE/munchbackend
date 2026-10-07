<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GENERATED_COLUMN = 'palpluss_tx_ref_unique';

    private const UNIQUE_INDEX = 'idx_orders_palpluss_tx_ref_unique';

    public function up(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        if (! Schema::hasColumn('orders', self::GENERATED_COLUMN)) {
            DB::statement(
                'ALTER TABLE `orders` ADD COLUMN `'.self::GENERATED_COLUMN.'` VARCHAR(64)
                 GENERATED ALWAYS AS (
                    CASE
                        WHEN `payment_method` = \'palpluss\' AND `transaction_reference` IS NOT NULL
                             AND `transaction_reference` <> \'\'
                        THEN `transaction_reference`
                        ELSE NULL
                    END
                 ) STORED'
            );
        }

        $indexExists = collect(DB::select('SHOW INDEX FROM `orders` WHERE Key_name = ?', [self::UNIQUE_INDEX]))
            ->isNotEmpty();

        if (! $indexExists && Schema::hasColumn('orders', self::GENERATED_COLUMN)) {
            DB::statement(
                'CREATE UNIQUE INDEX `'.self::UNIQUE_INDEX.'` ON `orders` (`'.self::GENERATED_COLUMN.'`)'
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        $indexExists = collect(DB::select('SHOW INDEX FROM `orders` WHERE Key_name = ?', [self::UNIQUE_INDEX]))
            ->isNotEmpty();

        if ($indexExists) {
            DB::statement('DROP INDEX `'.self::UNIQUE_INDEX.'` ON `orders`');
        }

        if (Schema::hasColumn('orders', self::GENERATED_COLUMN)) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn(self::GENERATED_COLUMN);
            });
        }
    }
};
