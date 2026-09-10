<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Services\BranchPosTodayOrdersService;
use App\Support\PosOrderTypes;
use App\Support\TimezoneDisplay;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchPosTodayOrdersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TimezoneDisplay::resetCache();
        $this->ensureSchema();
    }

    public function test_all_filter_does_not_narrow_by_channel(): void
    {
        $source = file_get_contents(app_path('Services/BranchPosTodayOrdersService.php'));
        $apply = $this->methodBody($source, 'applyFilter');

        $this->assertStringContainsString("\$filter === '' || \$filter === 'all'", $apply);
        $this->assertStringContainsString('return;', $apply);
        $this->assertStringContainsString("in_array(\$filter, PosOrderTypes::salesChannels(), true)", $apply);
        $this->assertStringContainsString('customer_delivery_address', $source);
        $this->assertStringContainsString('function addressField', $source);
        $this->assertStringNotContainsString('$address?->contact_person_name', $source);
    }

    public function test_delivery_json_address_does_not_crash_serialize(): void
    {
        $order = $this->makeOrder([
            'id' => 501,
            'sales_channel' => 'delivery',
            'order_type' => 'pos',
            'delivery_address' => json_encode([
                'contact_person_name' => 'Amina Delivery',
                'contact_person_number' => '0712345678',
                'address' => 'Nyali Road',
            ]),
        ]);

        $payload = (new BranchPosTodayOrdersService())->serializeOrder($order, 'Nyali');

        $this->assertSame('Amina Delivery', $payload['customer']);
        $this->assertSame('0712345678', $payload['phone']);
        $this->assertSame('Nyali Road', $payload['address']);
        $this->assertSame('delivery', $payload['sales_channel']);
        $this->assertSame('Delivery', $payload['sales_channel_label']);
    }

    public function test_all_tab_includes_every_pos_channel_and_excludes_website_orders(): void
    {
        $now = Carbon::now('UTC');
        $channels = [
            'delivery' => ['order_type' => 'pos', 'address' => ['contact_person_name' => 'Del', 'contact_person_number' => '0700000001', 'address' => 'Link Rd']],
            'takeaway' => ['order_type' => 'pos'],
            'dine_in' => ['order_type' => 'dine_in'],
            'glovo' => ['order_type' => 'pos'],
            'uber' => ['order_type' => 'pos'],
            'bolt_food' => ['order_type' => 'pos'],
        ];

        $id = 10;
        foreach ($channels as $channel => $meta) {
            $this->insertOrder($id++, [
                'sales_channel' => $channel,
                'order_type' => $meta['order_type'],
                'delivery_address' => isset($meta['address']) ? json_encode($meta['address']) : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->insertOrder(99, [
            'sales_channel' => null,
            'order_type' => 'delivery',
            'delivery_address' => json_encode(['contact_person_name' => 'Website', 'address' => 'App']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $service = new BranchPosTodayOrdersService();
        $all = $service->forBranch(1, 'Nyali', null, 'all', 1);

        $this->assertSame(6, $all['total']);
        $this->assertSame(
            ['bolt_food', 'uber', 'glovo', 'dine_in', 'takeaway', 'delivery'],
            array_column($all['orders'], 'sales_channel')
        );
        $this->assertNotContains('Website', array_column($all['orders'], 'customer'));

        $delivery = $service->forBranch(1, 'Nyali', null, 'delivery', 1);
        $this->assertSame(1, $delivery['total']);
        $this->assertSame('Del', $delivery['orders'][0]['customer']);
        $this->assertSame('Link Rd', $delivery['orders'][0]['address']);

        foreach (['takeaway', 'dine_in', 'glovo', 'uber', 'bolt_food'] as $channel) {
            $page = $service->forBranch(1, 'Nyali', null, $channel, 1);
            $this->assertSame(1, $page['total'], $channel);
            $this->assertSame($channel, $page['orders'][0]['sales_channel']);
        }
    }

    public function test_sales_channels_cover_the_all_tab_contract(): void
    {
        $this->assertSame(
            ['pos', 'delivery', 'takeaway', 'dine_in', 'glovo', 'uber', 'bolt_food'],
            PosOrderTypes::salesChannels()
        );
        $this->assertFalse(PosOrderTypes::isOnlineOrder('pos', 'delivery'));
        $this->assertTrue(PosOrderTypes::isOnlineOrder('delivery', null));
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeOrder(array $attrs): Order
    {
        $order = new Order();
        $order->setRawAttributes(array_merge([
            'id' => 1,
            'branch_id' => 1,
            'order_amount' => 100,
            'delivery_charge' => 0,
            'extra_discount' => 0,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'order_status' => 'confirmed',
            'order_type' => 'pos',
            'sales_channel' => 'takeaway',
            'order_note' => null,
            'rider_name' => null,
            'rider_phone' => null,
            'kitchen_printed_at' => null,
            'receipt_printed_at' => null,
            'created_at' => now()->utc()->toDateTimeString(),
            'updated_at' => now()->utc()->toDateTimeString(),
            'delivery_address' => null,
        ], $attrs), true);
        $order->exists = true;
        $order->setRelation('details', collect());
        $order->setRelation('customer', null);
        $order->setRelation('customer_delivery_address', null);
        $order->setRelation('order_change_amount', null);
        $order->setRelation('branch', null);
        $order->setRelation('cancelledByBranch', null);
        $order->setRelation('cancelledByAdmin', null);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertOrder(int $id, array $overrides = []): void
    {
        DB::table('orders')->insert(array_merge([
            'id' => $id,
            'readable_order_id' => 'M-'.$id,
            'branch_id' => 1,
            'order_amount' => 500,
            'delivery_charge' => 0,
            'extra_discount' => 0,
            'payment_status' => 'paid',
            'payment_method' => 'cash',
            'order_status' => 'confirmed',
            'order_type' => 'pos',
            'sales_channel' => 'takeaway',
            'delivery_address' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function methodBody(string $source, string $name): string
    {
        $start = strpos($source, 'function '.$name);
        $this->assertNotFalse($start, $name.' missing');

        return substr($source, $start, 900);
    }

    private function ensureSchema(): void
    {
        foreach ([
            'order_details', 'order_change_amounts', 'customer_addresses', 'users', 'admins', 'branches', 'orders',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('mpesa_till')->nullable();
            $table->timestamps();
        });
        DB::table('branches')->insert(['id' => 1, 'name' => 'Nyali']);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('phone')->nullable();
        });

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
        });

        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->string('contact_person_name')->nullable();
            $table->string('contact_person_number')->nullable();
            $table->string('address')->nullable();
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->text('product_details')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 24, 2)->default(0);
            $table->decimal('discount_on_product', 24, 2)->default(0);
            $table->text('variation')->nullable();
            $table->text('add_on_prices')->nullable();
            $table->text('add_on_qtys')->nullable();
        });

        Schema::create('order_change_amounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->decimal('paid_amount', 24, 2)->default(0);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('readable_order_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('branch_id')->default(1);
            $table->unsignedBigInteger('delivery_address_id')->nullable();
            $table->string('order_status')->nullable();
            $table->string('order_type')->nullable();
            $table->string('sales_channel')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('payment_method')->nullable();
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->decimal('delivery_charge', 24, 2)->default(0);
            $table->decimal('extra_discount', 24, 2)->default(0);
            $table->text('delivery_address')->nullable();
            $table->string('order_note')->nullable();
            $table->string('rider_name')->nullable();
            $table->string('rider_phone')->nullable();
            $table->timestamp('kitchen_printed_at')->nullable();
            $table->timestamp('receipt_printed_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->string('cancelled_by_type')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
        });
    }
}
