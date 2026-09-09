<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_requests')) {
            return;
        }

        Schema::table('payment_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_requests', 'place_order_draft')) {
                $table->json('place_order_draft')->nullable()->after('additional_data');
            }
            if (! Schema::hasColumn('payment_requests', 'placement_status')) {
                $table->string('placement_status', 24)->default('pending')->after('is_paid');
            }
            if (! Schema::hasColumn('payment_requests', 'placed_order_id')) {
                $table->unsignedBigInteger('placed_order_id')->nullable()->after('placement_status');
            }
            if (! Schema::hasColumn('payment_requests', 'placement_error')) {
                $table->json('placement_error')->nullable()->after('placed_order_id');
            }
            if (! Schema::hasColumn('payment_requests', 'placement_attempted_at')) {
                $table->timestamp('placement_attempted_at')->nullable()->after('placement_error');
            }
        });

        $this->backfillFromAdditionalData();

        Schema::table('payment_requests', function (Blueprint $table) {
            if (Schema::hasColumn('payment_requests', 'placement_status')) {
                $table->index(['payment_method', 'placement_status', 'is_paid'], 'payment_requests_paystack_placement_idx');
            }
            if (Schema::hasColumn('payment_requests', 'transaction_id')) {
                $table->index('transaction_id', 'payment_requests_transaction_id_idx');
            }
            if (Schema::hasColumn('payment_requests', 'placed_order_id')) {
                $table->index('placed_order_id', 'payment_requests_placed_order_id_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payment_requests')) {
            return;
        }

        Schema::table('payment_requests', function (Blueprint $table) {
            $table->dropIndex('payment_requests_paystack_placement_idx');
            $table->dropIndex('payment_requests_transaction_id_idx');
            $table->dropIndex('payment_requests_placed_order_id_idx');
        });

        Schema::table('payment_requests', function (Blueprint $table) {
            $columns = [
                'place_order_draft',
                'placement_status',
                'placed_order_id',
                'placement_error',
                'placement_attempted_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('payment_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function backfillFromAdditionalData(): void
    {
        if (! Schema::hasColumn('payment_requests', 'place_order_draft')) {
            return;
        }

        DB::table('payment_requests')
            ->select(['id', 'additional_data', 'placed_order_id'])
            ->orderBy('created_at')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $additional = json_decode((string) ($row->additional_data ?? ''), true);
                    if (! is_array($additional)) {
                        continue;
                    }

                    $updates = [];

                    if (empty($row->placed_order_id) && isset($additional['placed_order_id']) && is_numeric($additional['placed_order_id'])) {
                        $updates['placed_order_id'] = (int) $additional['placed_order_id'];
                        $updates['placement_status'] = 'placed';
                    }

                    if (isset($additional['place_order_draft']) && is_array($additional['place_order_draft'])) {
                        $updates['place_order_draft'] = json_encode($additional['place_order_draft']);
                    }

                    if ($updates !== []) {
                        DB::table('payment_requests')->where('id', $row->id)->update($updates);
                    }
                }
            }, 'id');
    }
};
