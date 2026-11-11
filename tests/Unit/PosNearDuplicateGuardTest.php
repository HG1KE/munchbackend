<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Model\OrderDetail;
use App\Support\PosNearDuplicateGuard;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosNearDuplicateGuardTest extends TestCase
{
    private string $previousConnection = 'mysql';

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousConnection = (string) config('database.default');
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropIfExists('order_details');
        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('branch_id')->default(1);
            $table->string('order_type')->default('pos');
            $table->string('sales_channel')->nullable();
            $table->string('client_uuid', 64)->nullable();
            $table->string('readable_order_id')->nullable();
            $table->decimal('order_amount', 12, 2)->default(0);
            $table->string('order_status')->default('delivered');
            $table->timestamp('placed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('quantity')->default(1);
            $table->text('variation')->nullable();
            $table->text('add_on_ids')->nullable();
            $table->text('add_on_qtys')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('order_details');
        Schema::dropIfExists('orders');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_message_is_plain_english(): void
    {
        $this->assertSame(
            'Duplicate order detected. You just posted the same order.',
            PosNearDuplicateGuard::message()
        );
        $this->assertSame('near_duplicate', PosNearDuplicateGuard::CODE);
        $this->assertSame(120, PosNearDuplicateGuard::WINDOW_SECONDS);
    }

    public function test_fingerprint_is_stable_across_line_order_and_json_shapes(): void
    {
        $a = PosNearDuplicateGuard::fingerprintFromDetails([
            [
                'product_id' => 10,
                'quantity' => 1,
                'variation' => json_encode([['type' => 'size', 'price' => 0]]),
                'add_on_ids' => json_encode([3, 1]),
                'add_on_qtys' => json_encode([1, 2]),
            ],
            [
                'product_id' => 5,
                'quantity' => 2,
                'variation' => '[]',
                'add_on_ids' => '[]',
                'add_on_qtys' => '[]',
            ],
        ]);
        $b = PosNearDuplicateGuard::fingerprintFromDetails([
            [
                'product_id' => 5,
                'quantity' => 2,
                'variation' => [],
                'add_on_ids' => [],
                'add_on_qtys' => [],
            ],
            [
                'product_id' => 10,
                'quantity' => 1,
                'variation' => [['price' => 0, 'type' => 'size']],
                'add_on_ids' => [3, 1],
                'add_on_qtys' => [1, 2],
            ],
        ]);

        $this->assertSame($a, $b);
        $this->assertNotSame(
            $a,
            PosNearDuplicateGuard::fingerprintFromDetails([
                [
                    'product_id' => 10,
                    'quantity' => 2,
                    'variation' => '[]',
                    'add_on_ids' => '[]',
                    'add_on_qtys' => '[]',
                ],
            ])
        );
    }

    public function test_find_recent_matches_same_branch_cart_and_total_within_window(): void
    {
        $now = Carbon::parse('2026-11-10 17:16:47', 'UTC');
        $fingerprint = $this->seedOrder(128609, [
            'branch_id' => 13,
            'sales_channel' => 'takeaway',
            'order_amount' => 840,
            'order_status' => 'delivered',
            'placed_at' => '2026-11-10 17:16:00',
            'created_at' => '2026-11-10 17:16:00',
        ], [
            ['product_id' => 101, 'quantity' => 1],
            ['product_id' => 202, 'quantity' => 1],
            ['product_id' => 303, 'quantity' => 1],
        ]);

        $found = PosNearDuplicateGuard::findRecent(13, 840.0, $fingerprint, $now);
        $this->assertNotNull($found);
        $this->assertSame(128609, (int) $found->id);
    }

    public function test_find_recent_ignores_cancelled_marketplace_other_branch_and_stale(): void
    {
        $now = Carbon::parse('2026-11-10 17:16:47', 'UTC');
        $lines = [
            ['product_id' => 101, 'quantity' => 1],
        ];
        $fingerprint = $this->seedOrder(1, [
            'branch_id' => 13,
            'sales_channel' => 'takeaway',
            'order_amount' => 100,
            'order_status' => 'canceled',
            'placed_at' => '2026-11-10 17:16:00',
        ], $lines);
        $this->seedOrder(2, [
            'branch_id' => 13,
            'sales_channel' => 'glovo',
            'order_type' => 'pos',
            'order_amount' => 100,
            'order_status' => 'confirmed',
            'placed_at' => '2026-11-10 17:16:00',
        ], $lines);
        $this->seedOrder(3, [
            'branch_id' => 99,
            'sales_channel' => 'takeaway',
            'order_amount' => 100,
            'order_status' => 'delivered',
            'placed_at' => '2026-11-10 17:16:00',
        ], $lines);
        $this->seedOrder(4, [
            'branch_id' => 13,
            'sales_channel' => 'dine_in',
            'order_amount' => 100,
            'order_status' => 'confirmed',
            'placed_at' => '2026-11-10 17:10:00',
            'created_at' => '2026-11-10 17:10:00',
        ], $lines);

        $this->assertNull(PosNearDuplicateGuard::findRecent(13, 100.0, $fingerprint, $now));
    }

    public function test_controller_blocks_near_duplicates_on_place(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $this->assertStringContainsString('PosNearDuplicateGuard', $controller);
        $this->assertStringContainsString('findNearDuplicatePosOrder', $controller);
        $this->assertStringContainsString('posNearDuplicateFail', $controller);
        $this->assertStringContainsString('near_duplicate', $controller);

        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $this->assertStringContainsString("body.code === 'near_duplicate'", $js);
        $this->assertStringContainsString('Duplicate order detected. You just posted the same order.', $js);
        $this->assertStringContainsString('toast(nearMessage, 5200, { danger: true })', $js);
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  list<array{product_id:int,quantity:int}>  $lines
     */
    private function seedOrder(int $id, array $order, array $lines): string
    {
        $created = $order['created_at'] ?? ($order['placed_at'] ?? now());
        Order::query()->insert([
            'id' => $id,
            'branch_id' => $order['branch_id'],
            'order_type' => $order['order_type'] ?? 'pos',
            'sales_channel' => $order['sales_channel'] ?? 'takeaway',
            'client_uuid' => $order['client_uuid'] ?? ('uuid-'.$id),
            'readable_order_id' => $order['readable_order_id'] ?? ('A'.$id),
            'order_amount' => $order['order_amount'],
            'order_status' => $order['order_status'] ?? 'delivered',
            'placed_at' => $order['placed_at'] ?? null,
            'created_at' => $created,
            'updated_at' => $created,
        ]);

        $detailRows = [];
        foreach ($lines as $line) {
            $detailRows[] = [
                'order_id' => $id,
                'product_id' => $line['product_id'],
                'quantity' => $line['quantity'],
                'variation' => $line['variation'] ?? '[]',
                'add_on_ids' => $line['add_on_ids'] ?? '[]',
                'add_on_qtys' => $line['add_on_qtys'] ?? '[]',
                'created_at' => $created,
                'updated_at' => $created,
            ];
        }
        OrderDetail::query()->insert($detailRows);

        return PosNearDuplicateGuard::fingerprintFromDetails($lines);
    }
}
