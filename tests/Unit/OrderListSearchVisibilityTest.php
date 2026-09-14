<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Support\OrderPublicNumber;
use App\Support\PosOrderTypes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderListSearchVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('readable_order_id')->nullable();
            $table->unsignedBigInteger('branch_id')->default(1);
            $table->string('order_status')->default('delivered');
            $table->string('order_type')->default('delivery');
            $table->string('sales_channel')->nullable();
            $table->date('delivery_date')->nullable();
            $table->boolean('checked')->default(false);
            $table->timestamps();
        });
    }

    public function test_default_order_list_stays_online_only(): void
    {
        $this->seedOrders();

        $this->assertSame([2], Order::query()->forOrderList(false)->orderBy('id')->pluck('id')->all());
        $this->assertSame([2], Order::query()->onlineOrders()->orderBy('id')->pluck('id')->all());
    }

    public function test_search_includes_pos_dine_in_takeaway_and_delivery(): void
    {
        $this->seedOrders();

        $this->assertSame(
            [1, 2, 3, 5, 6, 10],
            Order::query()->forOrderList(true)->orderBy('id')->pluck('id')->all()
        );
    }

    public function test_search_still_excludes_marketplace_pos(): void
    {
        $this->seedOrders();

        $ids = Order::query()->forOrderList(true)->orderBy('id')->pluck('id')->all();

        $this->assertNotContains(4, $ids);
        $this->assertNotContains(7, $ids);
        $this->assertNotContains(8, $ids);
        $this->assertTrue(PosOrderTypes::isMarketplaceSalesChannel('glovo'));
        $this->assertSame(['pos', 'delivery', 'takeaway', 'dine_in'], PosOrderTypes::munchPosSalesChannels());
        $this->assertSame(['glovo', 'uber', 'bolt_food'], PosOrderTypes::marketplaceSalesChannels());
    }

    public function test_readable_order_id_search_finds_pos_takeaway_without_confusing_internal_id(): void
    {
        $this->insert(10651, 'A99999', 'delivery', null);
        $this->insert(115061, 'A10651', 'pos', 'takeaway');

        $matches = Order::query()
            ->forOrderList(true)
            ->where(function ($query) {
                $query->orWhere('id', 'like', '%A10651%')
                    ->orWhere('readable_order_id', 'like', '%A10651%');
            })
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([115061], $matches);
        $this->assertSame([], Order::query()->forOrderList(false)->where('readable_order_id', 'A10651')->pluck('id')->all());
        $this->assertSame(0, Order::query()->where('id', 10651)->where('readable_order_id', 'A10651')->count());
    }

    public function test_details_route_uses_readable_id_or_internal_id_never_both(): void
    {
        $this->insert(10651, 'A99999', 'delivery', null);
        $this->insert(115061, 'A10651', 'pos', 'takeaway');

        $this->assertSame(115061, OrderPublicNumber::constrainRouteId(Order::query(), 'A10651')->value('id'));
        $this->assertSame(115061, OrderPublicNumber::constrainRouteId(Order::query(), '#a10651')->value('id'));
        $this->assertSame(10651, OrderPublicNumber::constrainRouteId(Order::query(), '10651')->value('id'));
        $this->assertSame(115061, OrderPublicNumber::constrainRouteId(Order::query(), '115061')->value('id'));
        $this->assertNull(OrderPublicNumber::constrainRouteId(Order::query(), '88888')->value('id'));
    }

    public function test_admin_and_branch_order_controllers_use_searchable_pos_scope(): void
    {
        $admin = file_get_contents(app_path('Http/Controllers/Admin/OrderController.php'));
        $branch = file_get_contents(app_path('Http/Controllers/Branch/OrderController.php'));

        $this->assertStringContainsString('forOrderList($includeSearchablePos)', $admin);
        $this->assertStringContainsString('OrderPublicNumber::constrainRouteId', $admin);
        $this->assertStringNotContainsString('$query->notPos()->notDineIn()', $admin);
        $this->assertStringContainsString('forOrderList($includeSearchablePos)', $branch);
        $this->assertStringContainsString('OrderPublicNumber::constrainRouteId', $branch);
        $this->assertStringNotContainsString('notPos()->notDineIn()', $branch);
        $this->assertStringNotContainsString('whereDate(\'delivery_date\', \'<=\', Carbon::now()', $branch);
    }

    private function seedOrders(): void
    {
        $this->insert(1, 'A10001', 'pos', 'takeaway');
        $this->insert(2, 'A10002', 'delivery', null);
        $this->insert(3, 'A10003', 'dine_in', 'dine_in');
        $this->insert(4, 'A10004', 'pos', 'glovo');
        $this->insert(5, 'A10005', 'pos', 'delivery');
        $this->insert(6, 'A10006', 'delivery', 'delivery');
        $this->insert(7, 'A10007', 'pos', 'uber');
        $this->insert(8, 'A10008', 'pos', 'bolt_food');
        $this->insert(10, 'A10010', 'pos', 'pos');
    }

    private function insert(int $id, string $readable, string $type, ?string $channel): void
    {
        DB::table('orders')->insert([
            'id' => $id,
            'readable_order_id' => $readable,
            'branch_id' => 1,
            'order_status' => 'delivered',
            'order_type' => $type,
            'sales_channel' => $channel,
            'delivery_date' => now()->format('Y-m-d'),
            'checked' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
