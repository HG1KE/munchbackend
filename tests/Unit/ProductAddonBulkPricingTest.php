<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\ProductPricingController;
use App\Model\Product;
use App\Services\ProductBranchPricingCopyService;
use App\Services\ProductBulkPricingService;
use App\Services\ProductChannelPricingService;
use App\Services\ProductPricingAuditLogger;
use App\Support\AddonChannelPricing;
use App\Support\ProductVariationPricing;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductAddonBulkPricingTest extends TestCase
{
    private ProductBulkPricingService $bulk;

    private string $regular;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('addon_channel_prices');
        Schema::dropIfExists('add_ons');
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
            $table->string('add_ons')->nullable();
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
        Schema::create('add_ons', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->decimal('price', 24, 2)->default(0);
            $table->float('tax')->default(0);
            $table->timestamps();
        });
        Schema::create('addon_channel_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('addon_id');
            $table->string('channel', 16);
            $table->decimal('price', 24, 2);
            $table->timestamps();
            $table->unique(['addon_id', 'channel']);
        });

        $this->regular = ProductVariationPricing::optionKey('Size', 'Small');

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Westlands', 'status' => 1],
            ['id' => 2, 'name' => 'Bamburi', 'status' => 1],
        ]);

        $now = now();
        DB::table('add_ons')->insert([
            ['id' => 8, 'name' => 'Extra Cheese', 'price' => 100, 'tax' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 9, 'name' => 'Extra Sauce', 'price' => 40, 'tax' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 21, 'name' => 'Foreign Addon', 'price' => 25, 'tax' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('addon_channel_prices')->insert([
            ['addon_id' => 8, 'channel' => 'uber', 'price' => 150, 'created_at' => $now, 'updated_at' => $now],
            ['addon_id' => 8, 'channel' => 'glovo', 'price' => 160, 'created_at' => $now, 'updated_at' => $now],
            ['addon_id' => 8, 'channel' => 'bolt_food', 'price' => 170, 'created_at' => $now, 'updated_at' => $now],
            ['addon_id' => 9, 'channel' => 'uber', 'price' => 50, 'created_at' => $now, 'updated_at' => $now],
            ['addon_id' => 9, 'channel' => 'glovo', 'price' => 55, 'created_at' => $now, 'updated_at' => $now],
            ['addon_id' => 9, 'channel' => 'bolt_food', 'price' => 60, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $burgerVars = [[
            'name' => 'Size',
            'type' => 'single',
            'min' => 0,
            'max' => 0,
            'required' => 'off',
            'values' => [
                ['label' => 'Small', 'optionPrice' => 0, 'channelPrices' => ['uber' => 550, 'glovo' => 560, 'bolt_food' => 570]],
                ['label' => 'Large', 'optionPrice' => 200, 'channelPrices' => ['uber' => 750, 'glovo' => 760, 'bolt_food' => 770]],
            ],
        ]];

        DB::table('products')->insert([
            ['id' => 10, 'name' => 'Chicken Burger', 'price' => 500, 'discount_type' => 'amount', 'discount' => 0, 'variations' => json_encode($burgerVars), 'add_ons' => json_encode([8, 9]), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 11, 'name' => 'Fries', 'price' => 400, 'discount_type' => 'amount', 'discount' => 0, 'variations' => json_encode([]), 'add_ons' => json_encode([]), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 12, 'name' => 'Other Branch Burger', 'price' => 450, 'discount_type' => 'amount', 'discount' => 0, 'variations' => json_encode([]), 'add_ons' => json_encode([21]), 'created_at' => $now, 'updated_at' => $now],
        ]);

        foreach ([10, 11] as $productId) {
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
        $other = DB::table('products')->where('id', 12)->first();
        DB::table('product_by_branches')->insert([
            'product_id' => 12,
            'branch_id' => 2,
            'price' => $other->price,
            'discount_type' => 'amount',
            'discount' => 0,
            'is_available' => 1,
            'variations' => $other->variations,
            'stock_type' => 'unlimited',
            'stock' => 0,
        ]);

        $this->bulk = new ProductBulkPricingService(new ProductChannelPricingService(new ProductPricingAuditLogger()));
    }

    public function test_bulk_update_uber_addon_price(): void
    {
        $result = $this->applyAddon(10, 8, 'uber', 175);

        $this->assertArrayNotHasKey('error', $result, $result['error'] ?? '');
        $this->assertGreaterThan(0, $result['saved']);
        $this->assertSame(175.0, $this->addonChannel(8, 'uber'));
        $this->assertSame(160.0, $this->addonChannel(8, 'glovo'));
        $this->assertSame(170.0, $this->addonChannel(8, 'bolt_food'));
        $this->assertSame(100.0, $this->addonMaster(8));
    }

    public function test_bulk_update_glovo_addon_price(): void
    {
        $result = $this->applyAddon(10, 8, 'glovo', 165);

        $this->assertArrayNotHasKey('error', $result, $result['error'] ?? '');
        $this->assertSame(150.0, $this->addonChannel(8, 'uber'));
        $this->assertSame(165.0, $this->addonChannel(8, 'glovo'));
        $this->assertSame(170.0, $this->addonChannel(8, 'bolt_food'));
    }

    public function test_bulk_update_bolt_food_addon_price(): void
    {
        $result = $this->applyAddon(10, 8, 'bolt_food', 180);

        $this->assertArrayNotHasKey('error', $result, $result['error'] ?? '');
        $this->assertSame(150.0, $this->addonChannel(8, 'uber'));
        $this->assertSame(160.0, $this->addonChannel(8, 'glovo'));
        $this->assertSame(180.0, $this->addonChannel(8, 'bolt_food'));
    }

    public function test_update_multiple_addons_in_one_bulk_operation(): void
    {
        $result = $this->bulk->applyPrices([10], [1], [], [], [], [
            ['product_id' => 10, 'addon_id' => 8, 'channel' => 'uber', 'value' => 175],
            ['product_id' => 10, 'addon_id' => 9, 'channel' => 'uber', 'value' => 58],
        ]);

        $this->assertArrayNotHasKey('error', $result, $result['error'] ?? '');
        $this->assertSame(175.0, $this->addonChannel(8, 'uber'));
        $this->assertSame(58.0, $this->addonChannel(9, 'uber'));
        $this->assertSame(55.0, $this->addonChannel(9, 'glovo'));
        $this->assertSame(160.0, $this->addonChannel(8, 'glovo'));
    }

    public function test_update_multiple_marketplace_channels(): void
    {
        $result = $this->bulk->applyPrices([10], [1], [], [], [], [
            ['product_id' => 10, 'addon_id' => 8, 'channel' => 'uber', 'value' => 175],
            ['product_id' => 10, 'addon_id' => 8, 'channel' => 'glovo', 'value' => 165],
            ['product_id' => 10, 'addon_id' => 8, 'channel' => 'bolt_food', 'value' => 180],
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(175.0, $this->addonChannel(8, 'uber'));
        $this->assertSame(165.0, $this->addonChannel(8, 'glovo'));
        $this->assertSame(180.0, $this->addonChannel(8, 'bolt_food'));
        $this->assertSame(100.0, $this->addonMaster(8));
    }

    public function test_editing_one_addon_does_not_affect_another_addon(): void
    {
        $this->applyAddon(10, 8, 'uber', 175);

        $this->assertSame(50.0, $this->addonChannel(9, 'uber'));
        $this->assertSame(55.0, $this->addonChannel(9, 'glovo'));
        $this->assertSame(40.0, $this->addonMaster(9));
    }

    public function test_editing_uber_does_not_affect_glovo_or_bolt_food(): void
    {
        $this->applyAddon(10, 8, 'uber', 175);

        $this->assertSame(160.0, $this->addonChannel(8, 'glovo'));
        $this->assertSame(170.0, $this->addonChannel(8, 'bolt_food'));
    }

    public function test_editing_addon_marketplace_price_does_not_affect_master_addon_price(): void
    {
        $this->applyAddon(10, 8, 'uber', 175);

        $this->assertSame(100.0, $this->addonMaster(8));
        $this->assertEquals(500, (float) DB::table('products')->where('id', 10)->value('price'));
    }

    public function test_editing_addon_price_does_not_affect_variation_price(): void
    {
        $this->applyAddon(10, 8, 'uber', 175);

        $this->assertSame(550.0, $this->variationChannel(10, 1, 'Small', 'uber'));
        $this->assertSame(0.0, $this->variationOption(10, 1, 'Small'));
    }

    public function test_products_without_addons_continue_working(): void
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
        $this->assertSame([], $this->searchProducts('Fries')[0]['addons'] ?? []);
    }

    public function test_existing_variation_level_marketplace_bulk_pricing_still_works(): void
    {
        $result = $this->bulk->applyPrices([10], [1], [], [], [[
            'product_id' => 10,
            'variation_id' => $this->regular,
            'channel' => 'uber',
            'value' => 575,
        ]]);

        $this->assertArrayNotHasKey('error', $result, $result['error'] ?? '');
        $this->assertSame(575.0, $this->variationChannel(10, 1, 'Small', 'uber'));
        $this->assertSame(150.0, $this->addonChannel(8, 'uber'));
    }

    public function test_untouched_addon_fields_are_not_overwritten(): void
    {
        $before = AddonChannelPricing::mapForIds([8])[8];
        $this->applyAddon(10, 8, 'glovo', 165);
        $after = AddonChannelPricing::mapForIds([8])[8];

        $this->assertSame(165.0, $after['glovo']);
        $this->assertSame($before['uber'], $after['uber']);
        $this->assertSame($before['bolt_food'], $after['bolt_food']);
    }

    public function test_empty_addon_value_is_ignored(): void
    {
        $result = $this->bulk->applyPrices([10], [1], [], [], [], [
            ['product_id' => 10, 'addon_id' => 8, 'channel' => 'uber', 'value' => ''],
            ['product_id' => 10, 'addon_id' => 8, 'channel' => 'glovo', 'value' => 165],
        ]);

        $this->assertArrayNotHasKey('error', $result, $result['error'] ?? '');
        $this->assertSame(150.0, $this->addonChannel(8, 'uber'));
        $this->assertSame(165.0, $this->addonChannel(8, 'glovo'));
    }

    public function test_invalid_addon_ids_are_rejected(): void
    {
        $result = $this->bulk->applyPrices([10], [1], [], [], [], [
            ['product_id' => 10, 'addon_id' => 999, 'channel' => 'uber', 'value' => 175],
        ]);

        $this->assertSame(0, $result['saved']);
        $this->assertSame('Addon does not belong to the selected products', $result['error']);
        $this->assertSame(150.0, $this->addonChannel(8, 'uber'));
    }

    public function test_cross_product_addon_ids_are_rejected(): void
    {
        $result = $this->bulk->applyPrices([10], [1], [], [], [], [
            ['product_id' => 10, 'addon_id' => 21, 'channel' => 'uber', 'value' => 175],
        ]);

        $this->assertSame(0, $result['saved']);
        $this->assertSame('Addon does not belong to the selected products', $result['error']);
        $this->assertNull($this->addonChannel(21, 'uber'));
    }

    public function test_authorization_rejects_addon_for_a_product_not_in_the_request(): void
    {
        $result = $this->bulk->applyPrices([11], [1], [], [], [], [
            ['product_id' => 10, 'addon_id' => 8, 'channel' => 'uber', 'value' => 175],
        ]);

        $this->assertSame(0, $result['saved']);
        $this->assertSame('Addon does not belong to the selected products', $result['error']);
        $this->assertSame(150.0, $this->addonChannel(8, 'uber'));
    }

    public function test_cross_branch_updates_are_rejected_where_applicable(): void
    {
        $result = $this->bulk->applyPrices([12], [1], [], [], [], [
            ['product_id' => 12, 'addon_id' => 21, 'channel' => 'uber', 'value' => 30],
        ]);

        $this->assertSame(0, $result['saved']);
        $this->assertSame('Addon does not belong to the selected products', $result['error']);
        $this->assertNull($this->addonChannel(21, 'uber'));
    }

    public function test_failed_addon_update_does_not_commit_product_price_changes(): void
    {
        $result = $this->bulk->applyPrices(
            [10],
            [1],
            [['channel' => 'uber', 'action' => 'set_exact', 'value' => 900]],
            [10 => 900],
            [],
            [['product_id' => 10, 'addon_id' => 999, 'channel' => 'uber', 'value' => 175]]
        );

        $this->assertSame(0, $result['saved']);
        $this->assertNotEmpty($result['error'] ?? null);
        $this->assertNull(DB::table('product_channel_prices')->where([
            'product_id' => 10,
            'branch_id' => 1,
            'channel' => 'uber',
        ])->value('price'));
        $this->assertSame(150.0, $this->addonChannel(8, 'uber'));
        $this->assertEquals(500, (float) DB::table('products')->where('id', 10)->value('price'));
    }

    public function test_invalid_prices_are_rejected_and_do_not_save(): void
    {
        $result = $this->bulk->applyPrices([10], [1], [], [], [], [
            ['product_id' => 10, 'addon_id' => 8, 'channel' => 'uber', 'value' => -10],
        ]);

        $this->assertSame(0, $result['saved']);
        $this->assertSame('Addon prices cannot be negative', $result['error']);
        $this->assertSame(150.0, $this->addonChannel(8, 'uber'));
    }

    public function test_product_edit_displays_the_updated_values(): void
    {
        $this->applyAddon(10, 8, 'uber', 175);

        $map = AddonChannelPricing::mapForIds([8]);
        $this->assertSame(175.0, $map[8]['uber']);
        $this->assertSame(160.0, $map[8]['glovo']);
        $this->assertSame(170.0, $map[8]['bolt_food']);

        $payload = $this->searchProducts('Chicken Burger');
        $burger = collect($payload)->firstWhere('id', 10);
        $cheese = collect($burger['addons'])->firstWhere('id', 8);
        $this->assertEquals(175, $cheese['channel_prices']['uber']);
        $this->assertEquals(160, $cheese['channel_prices']['glovo']);
        $this->assertEquals(100, $cheese['price']);
    }

    public function test_product_edit_save_is_reflected_in_bulk_search(): void
    {
        AddonChannelPricing::saveProductForm([
            8 => ['uber' => 188],
        ], [8, 9]);

        $payload = $this->searchProducts('Chicken Burger');
        $burger = collect($payload)->firstWhere('id', 10);
        $cheese = collect($burger['addons'])->firstWhere('id', 8);
        $this->assertEquals(188, $cheese['channel_prices']['uber']);
        $this->assertEquals(160, $cheese['channel_prices']['glovo']);
    }

    public function test_pos_marketplace_pricing_uses_the_updated_addon_price(): void
    {
        $this->applyAddon(10, 8, 'uber', 175);
        $addon = (object) ['id' => 8, 'price' => 100.0];
        $prices = AddonChannelPricing::unitPrices([$addon], 'uber');

        $this->assertSame(175.0, $prices[8]);
        $this->assertSame(100.0, AddonChannelPricing::unitPrices([$addon], 'take_away')[8]);
        $this->assertSame(160.0, AddonChannelPricing::unitPrices([$addon], 'glovo')[8]);
        $this->assertSame(170.0, AddonChannelPricing::unitPrices([$addon], 'bolt_food')[8]);
    }

    public function test_current_prices_include_addon_rows_for_marketplace_channels(): void
    {
        $current = $this->bulk->currentPrices([10], [1], ['uber', 'glovo']);
        $addonRows = array_values(array_filter($current['rows'], fn ($row) => ! empty($row['addon_id'])));
        $variationRows = array_values(array_filter($current['rows'], fn ($row) => ! empty($row['variation_id'])));

        $this->assertNotEmpty($addonRows);
        $this->assertNotEmpty($variationRows);
        $cheeseUber = collect($addonRows)->first(fn ($row) => (int) $row['addon_id'] === 8 && $row['channel'] === 'uber');
        $this->assertSame(150.0, $cheeseUber['current_price']);
        $this->assertSame('Extra Cheese', $cheeseUber['addon_name']);
    }

    public function test_search_products_returns_addons_and_variations_together(): void
    {
        $payload = $this->searchProducts('Chicken Burger');
        $burger = collect($payload)->firstWhere('id', 10);
        $fries = collect($this->searchProducts('Fries'))->firstWhere('id', 11);

        $this->assertCount(2, $burger['variations']);
        $this->assertCount(2, $burger['addons']);
        $this->assertSame('Extra Cheese', $burger['addons'][0]['name']);
        $this->assertEquals(150, $burger['addons'][0]['channel_prices']['uber']);
        $this->assertSame([], $fries['addons'] ?? null);
        $this->assertSame([], $fries['variations'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function searchProducts(string $search): array
    {
        $pricing = new ProductChannelPricingService(new ProductPricingAuditLogger());
        $controller = new ProductPricingController(
            $pricing,
            $this->bulk,
            new ProductBranchPricingCopyService(new ProductPricingAuditLogger(), $pricing),
            new Product()
        );

        $response = $controller->searchProducts(Request::create('/admin/product/pricing/products', 'GET', [
            'search' => $search,
        ]));

        return $response->getData(true)['data'] ?? [];
    }

    /**
     * @return array{saved: int, error?: string}
     */
    private function applyAddon(int $productId, int $addonId, string $channel, float $value, array $branchIds = [1]): array
    {
        return $this->bulk->applyPrices([$productId], $branchIds, [], [], [], [[
            'product_id' => $productId,
            'addon_id' => $addonId,
            'channel' => $channel,
            'value' => $value,
        ]]);
    }

    private function addonChannel(int $addonId, string $channel): ?float
    {
        $value = DB::table('addon_channel_prices')->where([
            'addon_id' => $addonId,
            'channel' => $channel,
        ])->value('price');

        return $value === null ? null : (float) $value;
    }

    private function addonMaster(int $addonId): float
    {
        return (float) DB::table('add_ons')->where('id', $addonId)->value('price');
    }

    private function variationChannel(int $productId, int $branchId, string $label, string $channel): ?float
    {
        $raw = DB::table('product_by_branches')->where(['product_id' => $productId, 'branch_id' => $branchId])->value('variations');
        foreach (ProductVariationPricing::flatOptions($raw) as $option) {
            if ($option['label'] === $label) {
                return $option['channel_prices'][$channel] ?? null;
            }
        }

        return null;
    }

    private function variationOption(int $productId, int $branchId, string $label): float
    {
        $raw = DB::table('product_by_branches')->where(['product_id' => $productId, 'branch_id' => $branchId])->value('variations');
        foreach (ProductVariationPricing::flatOptions($raw) as $option) {
            if ($option['label'] === $label) {
                return $option['option_price'];
            }
        }

        return 0;
    }
}
