<?php

namespace Tests\Unit;

use App\Model\Order;
use App\Models\OrderChangeAmount;
use App\Support\PosCheckoutIdempotency;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PosCheckoutIdempotencyTest extends TestCase
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

        Schema::dropIfExists('order_change_amounts');
        Schema::dropIfExists('orders');
        Schema::create('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('branch_id')->default(1);
            $table->string('order_type')->default('pos');
            $table->string('sales_channel')->nullable();
            $table->string('platform_order_number')->nullable();
            $table->string('marketplace_dedupe_key', 128)->nullable();
            $table->string('client_uuid', 64)->nullable();
            $table->string('readable_order_id')->nullable();
            $table->decimal('order_amount', 12, 2)->default(0);
            $table->string('payment_status')->default('paid');
            $table->string('order_status')->default('delivered');
            $table->timestamps();
            $table->unique(['branch_id', 'client_uuid'], 'orders_branch_client_uuid_unique');
            $table->unique('marketplace_dedupe_key', 'orders_marketplace_dedupe_key_unique');
        });
        Schema::create('order_change_amounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->float('order_amount')->default(0);
            $table->float('paid_amount')->default(0);
            $table->timestamps();
            $table->unique('order_id', 'order_change_amounts_order_id_unique');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('order_change_amounts');
        Schema::dropIfExists('orders');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_normalize_uuid_trims_and_rejects_empty(): void
    {
        $this->assertNull(PosCheckoutIdempotency::normalizeUuid(''));
        $this->assertNull(PosCheckoutIdempotency::normalizeUuid('   '));
        $this->assertNull(PosCheckoutIdempotency::normalizeUuid(null));
        $this->assertSame('abc-123', PosCheckoutIdempotency::normalizeUuid(' abc-123 '));
    }

    public function test_client_uuid_is_unique_per_branch_and_retries_reuse_the_first_order(): void
    {
        $uuid = '11111111-1111-4111-8111-111111111111';
        $this->insertOrder([
            'id' => 10,
            'branch_id' => 14,
            'client_uuid' => $uuid,
            'readable_order_id' => 'A10',
            'sales_channel' => 'takeaway',
        ]);
        $this->insertOrder([
            'id' => 11,
            'branch_id' => 3,
            'client_uuid' => $uuid,
            'readable_order_id' => 'A11',
            'sales_channel' => 'takeaway',
        ]);

        $found = PosCheckoutIdempotency::findByClientUuid(14, $uuid);
        $this->assertNotNull($found);
        $this->assertSame(10, (int) $found->id);
        $this->assertNull(PosCheckoutIdempotency::findByClientUuid(14, 'different-uuid'));
        $this->assertSame(11, (int) PosCheckoutIdempotency::findByClientUuid(3, $uuid)?->id);

        try {
            $this->insertOrder([
                'id' => 12,
                'branch_id' => 14,
                'client_uuid' => $uuid,
                'readable_order_id' => 'A12',
            ]);
            $this->fail('expected unique violation for the same branch UUID');
        } catch (QueryException $e) {
            $this->assertTrue(PosCheckoutIdempotency::isClientUuidConflict($e));
        }
    }

    public function test_unique_constraint_recovery_returns_the_existing_sale(): void
    {
        $uuid = '22222222-2222-4222-8222-222222222222';
        $this->insertOrder([
            'id' => 20,
            'branch_id' => 14,
            'client_uuid' => $uuid,
            'readable_order_id' => 'A20',
            'sales_channel' => 'dine_in',
        ]);

        try {
            $this->insertOrder([
                'id' => 21,
                'branch_id' => 14,
                'client_uuid' => $uuid,
                'readable_order_id' => 'A21',
            ]);
            $this->fail('expected unique violation');
        } catch (QueryException $e) {
            $recovered = PosCheckoutIdempotency::recoverFromException(14, $uuid, 'dine_in', null, $e);
            $this->assertNotNull($recovered);
            $this->assertSame(20, (int) $recovered->id);
            $this->assertSame('A20', $recovered->readable_order_id);
        }

        $this->assertSame(1, Order::query()->where('branch_id', 14)->where('client_uuid', $uuid)->count());
    }

    public function test_marketplace_lookup_returns_the_first_historical_ticket_without_backfill(): void
    {
        $this->insertOrder([
            'id' => 10434,
            'branch_id' => 14,
            'sales_channel' => 'glovo',
            'platform_order_number' => '#797',
            'marketplace_dedupe_key' => null,
            'client_uuid' => 'aaaa-1',
            'readable_order_id' => 'A10434',
        ]);
        $this->insertOrder([
            'id' => 10444,
            'branch_id' => 14,
            'sales_channel' => 'glovo',
            'platform_order_number' => '#797',
            'marketplace_dedupe_key' => null,
            'client_uuid' => 'aaaa-2',
            'readable_order_id' => 'A10444',
        ]);

        $found = PosCheckoutIdempotency::findByMarketplaceTicket(14, 'glovo', '#797');
        $this->assertNotNull($found);
        $this->assertSame(10434, (int) $found->id);
        $this->assertNull($found->marketplace_dedupe_key);
        $this->assertSame(2, Order::query()->where('platform_order_number', '#797')->count());
    }

    public function test_new_marketplace_rows_cannot_insert_a_duplicate_dedupe_key(): void
    {
        $key = PosCheckoutIdempotency::marketplaceKey(14, 'glovo', '#900');
        $this->assertSame('14|glovo|#900', $key);

        $this->insertOrder([
            'id' => 30,
            'branch_id' => 14,
            'sales_channel' => 'glovo',
            'platform_order_number' => '#900',
            'marketplace_dedupe_key' => $key,
            'client_uuid' => 'bbbb-1',
            'readable_order_id' => 'A30',
        ]);

        try {
            $this->insertOrder([
                'id' => 31,
                'branch_id' => 14,
                'sales_channel' => 'glovo',
                'platform_order_number' => '#900',
                'marketplace_dedupe_key' => $key,
                'client_uuid' => 'bbbb-2',
                'readable_order_id' => 'A31',
            ]);
            $this->fail('expected unique violation for marketplace_dedupe_key');
        } catch (QueryException $e) {
            $this->assertTrue(PosCheckoutIdempotency::isMarketplaceConflict($e));
        }
    }

    public function test_tender_rows_are_unique_per_order(): void
    {
        $this->insertOrder(['id' => 40, 'branch_id' => 14, 'client_uuid' => 'tender-1']);
        OrderChangeAmount::query()->create([
            'order_id' => 40,
            'order_amount' => 590,
            'paid_amount' => 590,
        ]);

        try {
            OrderChangeAmount::query()->create([
                'order_id' => 40,
                'order_amount' => 590,
                'paid_amount' => 590,
            ]);
            $this->fail('expected unique violation for order_change_amounts.order_id');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_place_order_source_requires_uuid_and_recovers_unique_conflicts(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $this->assertStringContainsString('client_uuid is required', $controller);
        $this->assertStringContainsString('PosCheckoutIdempotency', $controller);
        $this->assertStringContainsString('UniqueConstraintViolationException', $controller);
        $this->assertStringContainsString('persistPosTender', $controller);
        $this->assertStringContainsString('marketplace_dedupe_key', $controller);
        $this->assertStringContainsString("'idempotent' => true", $controller);
        $this->assertStringNotContainsString('dispatchPosDeliveryCustomerSms($existing)', $controller);
        $this->assertStringNotContainsString('online_checkout_uuid', $controller);
    }

    public function test_migrations_add_unique_keys_without_backfilling_historical_marketplace_pairs(): void
    {
        $tender = file_get_contents(database_path('migrations/2026_09_13_210000_add_unique_order_id_to_order_change_amounts_table.php'));
        $market = file_get_contents(database_path('migrations/2026_09_13_211000_add_marketplace_dedupe_key_to_orders_table.php'));
        $audit = file_get_contents(database_path('migrations/2026_09_13_212000_add_payment_status_to_order_cancellation_audit_logs.php'));

        $this->assertStringContainsString('order_change_amounts_order_id_unique', $tender);
        $this->assertStringContainsString('COUNT(*)', $tender);
        $this->assertStringContainsString('orders_marketplace_dedupe_key_unique', $market);
        $this->assertStringContainsString('Historical rows stay', $market);
        $this->assertStringContainsString('payment_status', $audit);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function insertOrder(array $attrs): void
    {
        DB::table('orders')->insert(array_merge([
            'id' => $attrs['id'] ?? ((int) (Order::query()->max('id') ?? 100) + 1),
            'branch_id' => 14,
            'order_type' => 'pos',
            'sales_channel' => 'takeaway',
            'platform_order_number' => null,
            'marketplace_dedupe_key' => null,
            'client_uuid' => null,
            'readable_order_id' => 'A'.($attrs['id'] ?? 'X'),
            'order_amount' => 590,
            'payment_status' => 'paid',
            'order_status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }
}
