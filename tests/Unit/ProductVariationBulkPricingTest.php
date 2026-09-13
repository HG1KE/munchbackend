<?php

namespace Tests\Unit;

use App\Services\ProductBulkPricingService;
use App\Services\ProductChannelPricingService;
use App\Services\ProductPricingAuditLogger;
use App\Support\ProductVariationPricing;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductVariationBulkPricingTest extends TestCase
{
    private ProductBulkPricingService $bulk;

    private string $regular;

    private string $large;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('product_price_audit_logs');
        Schema::dropIfExists('product_channel_prices');
        Schema::dropIfExists('product_by_branches');
        Schema::dropIfExists('translations');
        Schema::dropIfExists('products');
        Schema::dropIfExists('branches');

        Schema::create('branches', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->integer('status')->default(1);
        });
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type');
            $table->unsignedBigInteger('translationable_id');
            $table->string('locale');
            $table->string('key');
            $table->text('value')->nullable();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name')->nullable();
            $table->decimal('price', 24, 2)->default(0);
            $table->string('discount_type')->nullable();
            $table->float('discount')->default(0);
            $table->text('variations')->nullable();
            $table->timestamps();
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

        $this->regular = ProductVariationPricing::optionKey('Size', 'Regular');
        $this->large = ProductVariationPricing::optionKey('Size', 'Large');

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Westlands', 'status' => 1],
            ['id' => 2, 'name' => 'Bamburi', 'status' => 1],
        ]);

        $nuggets = $this->variationPayload([
            'Regular' => ['optionPrice' => 200, 'channelPrices' => ['uber' => 250, 'glovo' => 260, 'bolt_food' => 270]],
            'Large' => ['optionPrice' => 300, 'channelPrices' => ['uber' => 350, 'glovo' => 360, 'bolt_food' => 370]],
        ]);
        $plain = $this->variationPayload([]);

        $now = now();
        DB::table('products')->insert([
            ['id' => 10, 'name' => '4 Piece Nuggets', 'price' => 200, 'discount_type' => 'amount', 'discount' => 0, 'variations' => json_encode($nuggets), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 11, 'name' => 'Fries', 'price' => 400, 'discount_type' => 'amount', 'discount' => 0, 'variations' => json_encode($plain), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 12, 'name' => 'Chicken Burger', 'price' => 500, 'discount_type' => 'amount', 'discount' => 0, 'variations' => json_encode($this->variationPayload([
                'Small' => ['optionPrice' => 0],
                'Large' => ['optionPrice' => 200],
            ])), 'created_at' => $now, 'updated_at' => $now],
        ]);

        foreach ([10, 11, 12] as $productId) {
            $product = DB::table('products')->where('id', $productId)->first();
            foreach ([1, 2] as $branchId) {
                DB::table('product_by_branches')->insert([
                    'product_id' => $productId,
                    'branch_id' => $branchId,
                    'price' => $product->price,
                    'discount_type' => 'amount',
                    'discount' => 0,
                    'is_available' => 1,
                    'variations' => $product->variations,
                    'stock_type' => 'unlimited',
                    'stock' => 0,
                ]);
            }
        }

        $this->bulk = new ProductBulkPricingService(new ProductChannelPricingService(new ProductPricingAuditLogger()));
    }

    public function test_bulk_update_uber_variation_price(): void
    {
        $result = $this->applyVariation(10, $this->regular, 'uber', 275);

        $this->assertArrayNotHasKey('error', $result, $result['error'] ?? '');
        $this->assertGreaterThan(0, $result['saved']);
        $this->assertSame(275.0, $this->channelPrice(10, 1, 'Regular', 'uber'));
        $this->assertSame(260.0, $this->channelPrice(10, 1, 'Regular', 'glovo'));
        $this->assertSame(270.0, $this->channelPrice(10, 1, 'Regular', 'bolt_food'));
        $this->assertSame(200.0, $this->optionPrice(10, 1, 'Regular'));
    }

    public function test_bulk_update_glovo_and_bolt_food_variation_prices(): void
    {
        $this->applyVariation(10, $this->regular, 'glovo', 265);
        $this->applyVariation(10, $this->regular, 'bolt_food', 280);

        $this->assertSame(250.0, $this->channelPrice(10, 1, 'Regular', 'uber'));
        $this->assertSame(265.0, $this->channelPrice(10, 1, 'Regular', 'glovo'));
        $this->assertSame(280.0, $this->channelPrice(10, 1, 'Regular', 'bolt_food'));
    }

    public function test_update_multiple_variations_and_channels_in_one_request(): void
    {
        $result = $this->bulk->applyPrices([10], [1], [], [], [
            ['product_id' => 10, 'variation_id' => $this->regular, 'channel' => 'uber', 'value' => 275],
            ['product_id' => 10, 'variation_id' => $this->large, 'channel' => 'glovo', 'value' => 365],
            ['product_id' => 10, 'variation_id' => $this->large, 'channel' => 'bolt_food', 'value' => 380],
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(275.0, $this->channelPrice(10, 1, 'Regular', 'uber'));
        $this->assertSame(260.0, $this->channelPrice(10, 1, 'Regular', 'glovo'));
        $this->assertSame(350.0, $this->channelPrice(10, 1, 'Large', 'uber'));
        $this->assertSame(365.0, $this->channelPrice(10, 1, 'Large', 'glovo'));
        $this->assertSame(380.0, $this->channelPrice(10, 1, 'Large', 'bolt_food'));
        $this->assertSame(300.0, $this->optionPrice(10, 1, 'Large'));
    }

    public function test_editing_one_variation_does_not_modify_another_or_master(): void
    {
        $this->applyVariation(10, $this->regular, 'uber', 275);

        $this->assertSame(350.0, $this->channelPrice(10, 1, 'Large', 'uber'));
        $this->assertSame(360.0, $this->channelPrice(10, 1, 'Large', 'glovo'));
        $this->assertSame(200.0, $this->optionPrice(10, 1, 'Regular'));
        $this->assertSame(300.0, $this->optionPrice(10, 1, 'Large'));
        $this->assertEquals(200, (float) DB::table('products')->where('id', 10)->value('price'));
        $this->assertEquals(200, (float) DB::table('product_by_branches')->where(['product_id' => 10, 'branch_id' => 1])->value('price'));
    }

    public function test_product_level_bulk_pricing_does_not_change_variation_prices(): void
    {
        $result = $this->bulk->applyPrices(
            [10],
            [1],
            [['channel' => 'uber', 'action' => 'set_exact', 'value' => 900]],
            [10 => 900]
        );

        $this->assertArrayNotHasKey('error', $result, $result['error'] ?? '');
        $this->assertEquals(900, (float) DB::table('product_channel_prices')->where([
            'product_id' => 10,
            'branch_id' => 1,
            'channel' => 'uber',
        ])->value('price'));
        $this->assertSame(250.0, $this->channelPrice(10, 1, 'Regular', 'uber'));
        $this->assertSame(350.0, $this->channelPrice(10, 1, 'Large', 'uber'));
        $this->assertEquals(200, (float) DB::table('products')->where('id', 10)->value('price'));
    }

    public function test_product_level_bulk_pricing_still_works_for_products_without_variations(): void
    {
        $result = $this->bulk->applyPrices(
            [11],
            [1],
            [['channel' => 'uber', 'action' => 'set_exact', 'value' => 450]],
            [11 => 450]
        );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertEquals(450, (float) DB::table('product_channel_prices')->where([
            'product_id' => 11,
            'branch_id' => 1,
            'channel' => 'uber',
        ])->value('price'));
        $this->assertEquals(400, (float) DB::table('products')->where('id', 11)->value('price'));
        $this->assertSame([], ProductVariationPricing::flatOptions(DB::table('product_by_branches')->where(['product_id' => 11, 'branch_id' => 1])->value('variations')));
    }

    public function test_untouched_fields_are_not_overwritten(): void
    {
        $before = $this->channelPrices(10, 1, 'Regular');
        $this->applyVariation(10, $this->regular, 'uber', 275);
        $after = $this->channelPrices(10, 1, 'Regular');

        $this->assertSame(275.0, $after['uber']);
        $this->assertSame($before['glovo'], $after['glovo']);
        $this->assertSame($before['bolt_food'], $after['bolt_food']);
    }

    public function test_validation_rejects_invalid_prices_and_does_not_save(): void
    {
        $result = $this->bulk->applyPrices([10], [1], [], [], [
            ['product_id' => 10, 'variation_id' => $this->regular, 'channel' => 'uber', 'value' => -10],
        ]);

        $this->assertSame(0, $result['saved']);
        $this->assertSame('Variation prices cannot be negative', $result['error']);
        $this->assertSame(250.0, $this->channelPrice(10, 1, 'Regular', 'uber'));
    }

    public function test_cross_product_variation_ids_are_rejected(): void
    {
        $foreign = ProductVariationPricing::optionKey('Size', 'Small');
        $result = $this->bulk->applyPrices([10], [1], [], [], [
            ['product_id' => 10, 'variation_id' => $foreign, 'channel' => 'uber', 'value' => 275],
        ]);

        $this->assertSame(0, $result['saved']);
        $this->assertSame('Variation does not belong to the selected products', $result['error']);
        $this->assertSame(250.0, $this->channelPrice(10, 1, 'Regular', 'uber'));
    }

    public function test_authorization_rejects_variation_for_a_product_not_in_the_request(): void
    {
        $result = $this->bulk->applyPrices([11], [1], [], [], [
            ['product_id' => 10, 'variation_id' => $this->regular, 'channel' => 'uber', 'value' => 275],
        ]);

        $this->assertSame(0, $result['saved']);
        $this->assertSame('Variation does not belong to the selected products', $result['error']);
        $this->assertSame(250.0, $this->channelPrice(10, 1, 'Regular', 'uber'));
    }

    public function test_cross_branch_updates_are_rejected_by_scope(): void
    {
        $this->applyVariation(10, $this->regular, 'uber', 275, [1]);

        $this->assertSame(275.0, $this->channelPrice(10, 1, 'Regular', 'uber'));
        $this->assertSame(250.0, $this->channelPrice(10, 2, 'Regular', 'uber'));
    }

    public function test_failed_variation_update_does_not_commit_product_price_changes(): void
    {
        $result = $this->bulk->applyPrices(
            [10],
            [1],
            [['channel' => 'uber', 'action' => 'set_exact', 'value' => 900]],
            [10 => 900],
            [['product_id' => 10, 'variation_id' => 'missing::option', 'channel' => 'uber', 'value' => 275]]
        );

        $this->assertSame(0, $result['saved']);
        $this->assertNotEmpty($result['error'] ?? null);
        $this->assertNull(DB::table('product_channel_prices')->where([
            'product_id' => 10,
            'branch_id' => 1,
            'channel' => 'uber',
        ])->value('price'));
        $this->assertSame(250.0, $this->channelPrice(10, 1, 'Regular', 'uber'));
        $this->assertEquals(200, (float) DB::table('products')->where('id', 10)->value('price'));
    }

    public function test_catalog_product_edit_payload_keeps_individual_variation_pricing(): void
    {
        $this->applyVariation(10, $this->regular, 'uber', 275);

        $catalog = ProductVariationPricing::flatOptions(DB::table('products')->where('id', 10)->value('variations'));
        $this->assertSame(275.0, $catalog[0]['channel_prices']['uber']);
        $this->assertSame(260.0, $catalog[0]['channel_prices']['glovo']);
        $this->assertSame(200.0, $catalog[0]['option_price']);
        $this->assertSame(350.0, $catalog[1]['channel_prices']['uber']);
    }

    public function test_current_prices_include_variation_rows_for_marketplace_channels(): void
    {
        $current = $this->bulk->currentPrices([10], [1], ['uber', 'glovo']);
        $variationRows = array_values(array_filter($current['rows'], fn ($row) => ! empty($row['variation_id'])));

        $this->assertNotEmpty($variationRows);
        $regularUber = collect($variationRows)->first(fn ($row) => $row['variation_id'] === $this->regular && $row['channel'] === 'uber');
        $this->assertSame(250.0, $regularUber['current_price']);
        $this->assertSame('Regular', $regularUber['variation_name']);
    }

    /**
     * @param  list<int>  $branchIds
     * @return array{saved: int, error?: string}
     */
    private function applyVariation(int $productId, string $variationId, string $channel, float $value, array $branchIds = [1]): array
    {
        return $this->bulk->applyPrices([$productId], $branchIds, [], [], [[
            'product_id' => $productId,
            'variation_id' => $variationId,
            'channel' => $channel,
            'value' => $value,
        ]]);
    }

    private function channelPrice(int $productId, int $branchId, string $label, string $channel): ?float
    {
        return $this->channelPrices($productId, $branchId, $label)[$channel] ?? null;
    }

    /**
     * @return array<string, float>
     */
    private function channelPrices(int $productId, int $branchId, string $label): array
    {
        $raw = DB::table('product_by_branches')->where(['product_id' => $productId, 'branch_id' => $branchId])->value('variations');
        foreach (ProductVariationPricing::flatOptions($raw) as $option) {
            if ($option['label'] === $label) {
                return $option['channel_prices'];
            }
        }

        return [];
    }

    private function optionPrice(int $productId, int $branchId, string $label): float
    {
        $raw = DB::table('product_by_branches')->where(['product_id' => $productId, 'branch_id' => $branchId])->value('variations');
        foreach (ProductVariationPricing::flatOptions($raw) as $option) {
            if ($option['label'] === $label) {
                return $option['option_price'];
            }
        }

        return 0;
    }

    /**
     * @param  array<string, array<string, mixed>>  $options
     * @return list<array<string, mixed>>
     */
    private function variationPayload(array $options): array
    {
        if ($options === []) {
            return [];
        }

        $values = [];
        foreach ($options as $label => $option) {
            $values[] = array_merge(['label' => $label], $option);
        }

        return [[
            'name' => 'Size',
            'type' => 'single',
            'min' => 0,
            'max' => 0,
            'required' => 'off',
            'values' => $values,
        ]];
    }
}
