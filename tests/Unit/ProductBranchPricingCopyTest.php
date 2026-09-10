<?php

namespace Tests\Unit;

use App\Services\ProductBranchPricingCopyService;
use App\Services\ProductChannelPricingService;
use App\Services\ProductPricingAuditLogger;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductBranchPricingCopyTest extends TestCase
{
    private ProductBranchPricingCopyService $copy;

    private ProductChannelPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('product_price_audit_logs');
        Schema::dropIfExists('product_channel_prices');
        Schema::dropIfExists('product_by_branches');
        Schema::dropIfExists('products');
        Schema::dropIfExists('branches');

        Schema::create('branches', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->integer('status')->default(1);
        });
        Schema::create('products', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name')->nullable();
            $table->decimal('price', 24, 2)->default(0);
        });
        Schema::create('product_by_branches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id');
            $table->decimal('price', 24, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->float('discount')->default(0);
            $table->unsignedTinyInteger('is_available')->default(1);
            $table->text('variations')->nullable();
            $table->string('stock_type')->nullable();
            $table->integer('stock')->default(0);
            $table->timestamps();
        });
        Schema::create('product_channel_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('channel', 16);
            $table->decimal('price', 24, 2)->nullable();
            $table->unsignedTinyInteger('is_available')->default(1);
            $table->timestamps();
            $table->unique(['product_id', 'branch_id', 'channel']);
        });
        Schema::create('product_price_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->string('actor_type', 16)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('source_branch_id')->nullable();
            $table->string('channel', 16);
            $table->string('field', 32);
            $table->string('old_value', 64)->nullable();
            $table->string('new_value', 64)->nullable();
            $table->string('source', 32)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Westlands', 'status' => 1],
            ['id' => 2, 'name' => 'Kilimani', 'status' => 1],
            ['id' => 3, 'name' => 'Karen', 'status' => 1],
        ]);
        DB::table('products')->insert([
            ['id' => 10, 'name' => 'Burger', 'price' => 1000],
            ['id' => 11, 'name' => 'Fries', 'price' => 400],
        ]);

        $logger = new ProductPricingAuditLogger();
        $this->pricing = new ProductChannelPricingService($logger);
        $this->copy = new ProductBranchPricingCopyService($logger, $this->pricing);
    }

    public function test_copy_pos_pricing(): void
    {
        $this->channel(10, 1, 'pos', 1200, 1);
        $this->pbb(10, 1, 1200, 1);

        $result = $this->copy->apply(1, [2], ['pos'], [], 'overwrite');

        $this->assertTrue($result['copied']);
        $this->assertEquals(1200, (float) $this->destPrice(10, 2, 'pos'));
        $this->assertEquals(1200, (float) DB::table('product_by_branches')->where(['product_id' => 10, 'branch_id' => 2])->value('price'));
    }

    public function test_copy_uber_glovo_and_bolt_pricing(): void
    {
        $this->channel(10, 1, 'uber', 1400, 1);
        $this->channel(10, 1, 'glovo', 1450, 1);
        $this->channel(10, 1, 'bolt_food', 1380, 1);

        $this->copy->apply(1, [2], ['uber'], [], 'overwrite');
        $this->copy->apply(1, [2], ['glovo'], [], 'overwrite');
        $this->copy->apply(1, [2], ['bolt_food'], [], 'overwrite');

        $this->assertEquals(1400, (float) $this->destPrice(10, 2, 'uber'));
        $this->assertEquals(1450, (float) $this->destPrice(10, 2, 'glovo'));
        $this->assertEquals(1380, (float) $this->destPrice(10, 2, 'bolt_food'));
        $this->assertNull($this->destPrice(10, 2, 'pos'));
    }

    public function test_copy_availability_only_leaves_prices_alone(): void
    {
        $this->channel(10, 1, 'pos', 1200, 0);
        $this->channel(10, 2, 'pos', 1100, 1);

        $this->copy->apply(1, [2], [], ['pos'], 'overwrite');

        $row = DB::table('product_channel_prices')->where(['product_id' => 10, 'branch_id' => 2, 'channel' => 'pos'])->first();
        $this->assertEquals(1100, (float) $row->price);
        $this->assertSame(0, (int) $row->is_available);
    }

    public function test_copy_to_multiple_branches(): void
    {
        $this->channel(10, 1, 'pos', 1250, 1);

        $result = $this->copy->apply(1, [2, 3], ['pos'], [], 'overwrite');

        $this->assertSame(2, $result['branches_updated']);
        $this->assertEquals(1250, (float) $this->destPrice(10, 2, 'pos'));
        $this->assertEquals(1250, (float) $this->destPrice(10, 3, 'pos'));
    }

    public function test_overwrite_replaces_existing_overrides(): void
    {
        $this->channel(10, 1, 'uber', 1400, 1);
        $this->channel(10, 2, 'uber', 999, 1);

        $this->copy->apply(1, [2], ['uber'], [], 'overwrite');

        $this->assertEquals(1400, (float) $this->destPrice(10, 2, 'uber'));
    }

    public function test_fill_missing_only_writes_empty_overrides(): void
    {
        $this->channel(10, 1, 'uber', 1400, 1);
        $this->channel(11, 1, 'uber', 500, 1);
        $this->channel(10, 2, 'uber', 999, 1);

        $this->copy->apply(1, [2], ['uber'], [], 'fill_missing');

        $this->assertEquals(999, (float) $this->destPrice(10, 2, 'uber'));
        $this->assertEquals(500, (float) $this->destPrice(11, 2, 'uber'));
    }

    public function test_skip_existing_does_not_touch_destination_rows(): void
    {
        $this->channel(10, 1, 'glovo', 1450, 1);
        $this->channel(10, 2, 'glovo', null, 1);

        $result = $this->copy->apply(1, [2], ['glovo'], [], 'skip_existing');

        $this->assertSame('Nothing to copy for this selection', $result['error']);
        $this->assertNull($this->destPrice(10, 2, 'glovo'));
    }

    public function test_audit_entries_include_source_destination_and_copy_action(): void
    {
        $this->channel(10, 1, 'bolt_food', 1380, 1);
        $this->copy->apply(1, [2], ['bolt_food'], [], 'overwrite');

        $log = DB::table('product_price_audit_logs')->first();
        $this->assertNotNull($log);
        $this->assertSame('copy_branch_pricing', $log->source);
        $this->assertSame(1, (int) $log->source_branch_id);
        $this->assertSame(2, (int) $log->branch_id);
        $this->assertSame(10, (int) $log->product_id);
        $this->assertSame('bolt_food', $log->channel);
        $this->assertSame('1380', $log->new_value);
        $this->assertNotNull($log->created_at);
    }

    public function test_transaction_rolls_back_when_audit_fails(): void
    {
        $this->channel(10, 1, 'pos', 1200, 1);
        $logger = new class extends ProductPricingAuditLogger
        {
            public function record(array $entries, string $source = 'drawer'): void
            {
                throw new \RuntimeException('audit failed');
            }
        };
        $copy = new ProductBranchPricingCopyService($logger, new ProductChannelPricingService($logger));

        $result = $copy->apply(1, [2], ['pos'], [], 'overwrite');

        $this->assertSame('Pricing copy failed and was rolled back', $result['error']);
        $this->assertNull($this->destPrice(10, 2, 'pos'));
        $this->assertSame(0, DB::table('product_by_branches')->where('branch_id', 2)->count());
    }

    public function test_copy_does_not_change_unselected_channel_inheritance(): void
    {
        $this->channel(10, 1, 'pos', 1200, 1);
        $this->channel(10, 1, 'uber', 1400, 1);
        $this->channel(10, 2, 'pos', 1100, 1);

        $this->copy->apply(1, [2], ['uber'], [], 'overwrite');

        $destPos = (float) $this->destPrice(10, 2, 'pos');
        $destUber = (float) $this->destPrice(10, 2, 'uber');
        $this->assertSame(1100.0, $destPos);
        $this->assertSame(1400.0, $destUber);
        $this->assertSame(1100.0, $this->pricing->resolvePrice('pos', 1000, $destPos, null));
        $this->assertSame(1400.0, $this->pricing->resolvePrice('uber', 1000, $destPos, $destUber));
        $this->assertSame(1100.0, $this->pricing->resolvePrice('glovo', 1000, $destPos, null));
    }

    public function test_validation_rejects_invalid_copy_requests(): void
    {
        $this->assertSame('Select a source branch', $this->copy->preview(0, [2], ['pos'], [], 'overwrite')['error']);
        $this->assertSame('Select at least one destination branch', $this->copy->preview(1, [], ['pos'], [], 'overwrite')['error']);
        $this->assertSame('Destination branches cannot include the source branch', $this->copy->preview(1, [1], ['pos'], [], 'overwrite')['error']);
        $this->assertSame('Choose at least one price or availability field to copy', $this->copy->preview(1, [2], [], [], 'overwrite')['error']);
        $this->assertSame('Nothing to copy for this selection', $this->copy->preview(1, [2], ['pos'], [], 'overwrite')['error']);
    }

    public function test_null_overrides_stay_null_unless_overwritten(): void
    {
        $this->channel(10, 1, 'uber', null, 1);
        $this->channel(10, 2, 'uber', 999, 1);

        $this->copy->apply(1, [2], ['uber'], [], 'fill_missing');
        $this->assertEquals(999, (float) $this->destPrice(10, 2, 'uber'));

        $this->copy->apply(1, [2], ['uber'], [], 'overwrite');
        $this->assertNull($this->destPrice(10, 2, 'uber'));
    }

    private function channel(int $productId, int $branchId, string $channel, mixed $price, int $available): void
    {
        DB::table('product_channel_prices')->insert([
            'product_id' => $productId,
            'branch_id' => $branchId,
            'channel' => $channel,
            'price' => $price,
            'is_available' => $available,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function pbb(int $productId, int $branchId, float $price, int $available): void
    {
        DB::table('product_by_branches')->insert([
            'product_id' => $productId,
            'branch_id' => $branchId,
            'price' => $price,
            'discount_type' => 'percent',
            'discount' => 0,
            'is_available' => $available,
            'variations' => '[]',
            'stock_type' => 'unlimited',
            'stock' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function destPrice(int $productId, int $branchId, string $channel): mixed
    {
        return DB::table('product_channel_prices')
            ->where(['product_id' => $productId, 'branch_id' => $branchId, 'channel' => $channel])
            ->value('price');
    }
}
