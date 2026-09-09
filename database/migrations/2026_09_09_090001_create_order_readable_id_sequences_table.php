<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_readable_id_sequences', function (Blueprint $table) {
            $table->id();
            $table->char('letter', 1)->default('A');
            $table->unsignedInteger('last_number')->default(10000);
            $table->timestamps();
        });

        DB::table('order_readable_id_sequences')->insert([
            'letter' => 'A',
            'last_number' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_readable_id_sequences');
    }
};
