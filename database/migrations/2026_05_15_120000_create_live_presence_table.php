<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('live_presence')) {
            return;
        }

        Schema::create('live_presence', function (Blueprint $table) {
            $table->id();
            $table->string('client_token', 64)->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('current_state', 32)->nullable()->index();
            $table->string('current_path', 255)->nullable();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('live_presence')) {
            Schema::drop('live_presence');
        }
    }
};
