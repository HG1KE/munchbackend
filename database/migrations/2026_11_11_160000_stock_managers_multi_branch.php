<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_managers') && ! Schema::hasColumn('stock_managers', 'all_branches')) {
            Schema::table('stock_managers', function (Blueprint $table) {
                $table->boolean('all_branches')->default(false)->after('branch_id');
            });
        }

        if (! Schema::hasTable('stock_manager_branch')) {
            Schema::create('stock_manager_branch', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('stock_manager_id');
                $table->unsignedBigInteger('branch_id');
                $table->timestamps();

                $table->unique(['stock_manager_id', 'branch_id'], 'stock_manager_branch_unique');
                $table->index('branch_id');
            });
        }

        if (Schema::hasTable('stock_managers') && Schema::hasColumn('stock_managers', 'branch_id') && Schema::hasTable('stock_manager_branch')) {
            $rows = DB::table('stock_managers')
                ->whereNotNull('branch_id')
                ->select('id', 'branch_id')
                ->get();

            $now = now();
            foreach ($rows as $row) {
                $exists = DB::table('stock_manager_branch')
                    ->where('stock_manager_id', $row->id)
                    ->where('branch_id', $row->branch_id)
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('stock_manager_branch')->insert([
                    'stock_manager_id' => $row->id,
                    'branch_id' => $row->branch_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        if (Schema::hasTable('stock_managers') && Schema::hasColumn('stock_managers', 'branch_id')) {
            Schema::table('stock_managers', function (Blueprint $table) {
                $table->dropColumn('branch_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_managers') && ! Schema::hasColumn('stock_managers', 'branch_id')) {
            Schema::table('stock_managers', function (Blueprint $table) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('password');
                $table->index('branch_id');
            });

            if (Schema::hasTable('stock_manager_branch')) {
                $pivots = DB::table('stock_manager_branch')
                    ->orderBy('id')
                    ->get()
                    ->groupBy('stock_manager_id');

                foreach ($pivots as $managerId => $rows) {
                    $first = $rows->first();
                    if ($first) {
                        DB::table('stock_managers')
                            ->where('id', $managerId)
                            ->update(['branch_id' => $first->branch_id]);
                    }
                }
            }
        }

        if (Schema::hasTable('stock_managers') && Schema::hasColumn('stock_managers', 'all_branches')) {
            Schema::table('stock_managers', function (Blueprint $table) {
                $table->dropColumn('all_branches');
            });
        }

        Schema::dropIfExists('stock_manager_branch');
    }
};
