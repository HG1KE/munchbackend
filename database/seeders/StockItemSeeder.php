<?php

namespace Database\Seeders;

use App\Model\StockItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class StockItemSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('stock_items')) {
            return;
        }

        foreach (['Buns', 'Beef Patties', 'Chicken Patties'] as $name) {
            StockItem::query()->firstOrCreate(
                ['name' => $name],
                ['status' => true]
            );
        }
    }
}
