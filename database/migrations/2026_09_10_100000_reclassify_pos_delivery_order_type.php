<?php

use App\Support\PosOrderTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class ReclassifyPosDeliveryOrderType extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'sales_channel')) {
            return;
        }

        DB::table('orders')
            ->where('order_type', 'delivery')
            ->whereIn('sales_channel', PosOrderTypes::salesChannels())
            ->update(['order_type' => 'pos']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'sales_channel')) {
            return;
        }

        DB::table('orders')
            ->where('order_type', 'pos')
            ->where('sales_channel', PosOrderTypes::DELIVERY)
            ->update(['order_type' => 'delivery']);
    }
}
