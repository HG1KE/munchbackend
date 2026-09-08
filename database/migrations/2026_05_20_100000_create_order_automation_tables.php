<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('order_automation_settings')) {
            Schema::create('order_automation_settings', function (Blueprint $table) {
                $table->unsignedTinyInteger('id')->primary();
                $table->boolean('is_enabled')->default(false);
                $table->unsignedSmallInteger('auto_complete_hours')->default(24);
                $table->json('eligible_statuses');
                $table->json('excluded_statuses');
                $table->string('branch_ids', 255)->nullable();
                $table->boolean('dry_run')->default(false);
                $table->boolean('require_delivery_man')->default(false);
                $table->timestamps();
            });

            DB::table('order_automation_settings')->insert([
                'id' => 1,
                'is_enabled' => false,
                'auto_complete_hours' => 24,
                'eligible_statuses' => json_encode(config('order_automation.default_eligible_statuses')),
                'excluded_statuses' => json_encode(config('order_automation.default_excluded_statuses')),
                'branch_ids' => null,
                'dry_run' => false,
                'require_delivery_man' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('order_automation_runs')) {
            Schema::create('order_automation_runs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('run_type', 16)->index();
                $table->boolean('dry_run')->default(false);
                $table->unsignedBigInteger('admin_id')->nullable();
                $table->unsignedInteger('eligible_count')->default(0);
                $table->unsignedInteger('completed_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->json('settings_snapshot')->nullable();
                $table->json('entries')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['created_at', 'run_type'], 'order_automation_runs_created_type_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_automation_runs');
        Schema::dropIfExists('order_automation_settings');
    }
};
