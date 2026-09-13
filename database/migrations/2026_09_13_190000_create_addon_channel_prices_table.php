<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('addon_channel_prices')) {
            return;
        }

        Schema::create('addon_channel_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('addon_id');
            $table->string('channel', 16);
            $table->decimal('price', 24, 2);
            $table->timestamps();

            $table->unique(['addon_id', 'channel'], 'addon_channel_prices_unique');
            $table->index('addon_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addon_channel_prices');
    }
};
