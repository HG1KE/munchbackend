<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('emergency_otp_mode_audit_logs')) {
            Schema::create('emergency_otp_mode_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('admin_id')->nullable();
                $table->string('action', 32);
                $table->boolean('enabled')->default(false);
                $table->string('ip_address', 64)->nullable();
                $table->text('reason')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['enabled', 'created_at']);
                $table->index('admin_id');
            });
        }

        if (! Schema::hasTable('login_setups')) {
            return;
        }

        $now = now();
        foreach ([
            'emergency_otp_mode' => '0',
            'emergency_otp_mode_reason' => '',
            'emergency_otp_mode_auto_enabled_at' => '',
        ] as $key => $value) {
            if (DB::table('login_setups')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('login_setups')->insert([
                'key' => $key,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_otp_mode_audit_logs');

        if (! Schema::hasTable('login_setups')) {
            return;
        }

        DB::table('login_setups')->whereIn('key', [
            'emergency_otp_mode',
            'emergency_otp_mode_reason',
            'emergency_otp_mode_auto_enabled_at',
        ])->delete();
    }
};
