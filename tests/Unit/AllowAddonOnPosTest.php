<?php

namespace Tests\Unit;

use App\Model\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AllowAddonOnPosTest extends TestCase
{
    public function test_migration_defaults_existing_and_new_products_to_off(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_11_180000_add_allow_addon_on_pos_to_products_table.php'));
        $this->assertStringContainsString("boolean('allow_addon_on_pos')->default(false)", $migration);
        $this->assertStringContainsString("Schema::hasColumn('products', 'allow_addon_on_pos')", $migration);

        $previous = config('database.default');
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        try {
            Schema::dropIfExists('products');
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
            });
            DB::table('products')->insert(['name' => 'Legacy Burger']);
            $migrator = require database_path('migrations/2026_09_11_180000_add_allow_addon_on_pos_to_products_table.php');
            $migrator->up();
            $this->assertFalse((bool) DB::table('products')->where('name', 'Legacy Burger')->value('allow_addon_on_pos'));
            DB::table('products')->insert(['name' => 'New Burger']);
            $this->assertFalse((bool) DB::table('products')->where('name', 'New Burger')->value('allow_addon_on_pos'));
            $this->assertSame(0, (int) DB::table('products')->where('allow_addon_on_pos', 1)->count());
        } finally {
            Schema::dropIfExists('products');
            config(['database.default' => $previous]);
            DB::purge('sqlite');
        }
    }

    public function test_null_or_missing_flag_is_treated_as_disabled(): void
    {
        $product = new Product();
        $product->allow_addon_on_pos = null;
        $this->assertFalse($product->allowsAddonOnPos());

        $legacy = new Product();
        $this->assertFalse($legacy->allowsAddonOnPos());
    }

    public function test_admin_create_and_edit_forms_include_the_toggle_defaulting_off(): void
    {
        $create = file_get_contents(resource_path('views/admin-views/product/index.blade.php'));
        $edit = file_get_contents(resource_path('views/admin-views/product/edit.blade.php'));

        $this->assertStringContainsString("translate('Allow Addon on POS')", $create);
        $this->assertStringContainsString("translate('Allow customers to select addons for this product on POS.')", $create);
        $this->assertStringContainsString('name="allow_addon_on_pos"', $create);
        $this->assertStringNotContainsString('name="allow_addon_on_pos" checked', $create);
        $this->assertStringNotContainsString("name=\"allow_addon_on_pos\" checked", str_replace('  ', ' ', $create));

        $this->assertStringContainsString("translate('Allow Addon on POS')", $edit);
        $this->assertStringContainsString("translate('Allow customers to select addons for this product on POS.')", $edit);
        $this->assertStringContainsString("name=\"allow_addon_on_pos\" {{ (\$product->allow_addon_on_pos ?? false) ? 'checked' : '' }}", $edit);
    }

    public function test_product_save_persists_the_toggle_on_create_and_edit(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Admin/ProductController.php'));
        $this->assertSame(2, substr_count($controller, "allow_addon_on_pos = \$request->input('allow_addon_on_pos') == 'on'"));
    }

    public function test_pos_catalog_sends_addons_only_when_the_toggle_is_on(): void
    {
        $catalog = file_get_contents(app_path('Services/BranchPosCatalogService.php'));
        $this->assertStringContainsString('allowsAddonOnPos()', $catalog);
        $this->assertStringContainsString("'allow_addon_on_pos' => \$allowAddonOnPos", $catalog);
        $this->assertStringContainsString("'addons' => \$addons", $catalog);
        $this->assertStringContainsString('pos-allow-addon-on-pos-1', $catalog);
        $this->assertStringContainsString('addonMapForProducts', $catalog);
        $this->assertStringContainsString('AddOn::query()', $catalog);
    }

    public function test_pos_backend_ignores_addons_unless_the_product_toggle_is_on(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $this->assertStringContainsString('function attachPosAddons', $controller);
        $this->assertStringContainsString('if (! $product->allowsAddonOnPos())', $controller);
        $this->assertStringContainsString("\$input['addon_id']", $controller);
        $this->assertStringContainsString("\$input['addon_quantities']", $controller);
        $this->assertStringContainsString('$product->allowsAddonOnPos()', $controller);
    }

    public function test_pos_js_gates_the_existing_addon_selector_per_product(): void
    {
        $js = file_get_contents(public_path('assets/admin/js/munch-pos-app.js'));
        $this->assertStringContainsString('function posAddons', $js);
        $this->assertStringContainsString('function productNeedsModifiers', $js);
        $this->assertStringContainsString('product.allow_addon_on_pos', $js);
        $this->assertStringContainsString('name="pos-addon"', $js);
        $this->assertStringContainsString('addon_id: line.addon_id || []', $js);
        $this->assertStringContainsString('function lineAddonTotal', $js);
        $this->assertStringContainsString('function addonSelectionKey', $js);
        $this->assertStringNotContainsString('window.location.reload()', $js);
    }

    public function test_online_product_formatting_does_not_use_the_pos_addon_toggle(): void
    {
        $helpers = file_get_contents(app_path('CentralLogics/helpers.php'));
        $this->assertStringNotContainsString('allow_addon_on_pos', $helpers);
        $api = file_get_contents(app_path('Http/Controllers/Api/V1/ProductController.php'));
        $this->assertStringNotContainsString('allow_addon_on_pos', $api);
    }

    public function test_addon_cart_scenarios(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for POS addon cart scenarios');
        }

        $script = base_path('tests/Js/pos-addon-cart.test.js');
        $output = [];
        $code = 0;
        exec(escapeshellcmd($node).' '.escapeshellarg($script).' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $combined = implode("\n", $output);
        $this->assertStringContainsString('toggle off hides addon selector', $combined);
        $this->assertStringContainsString('toggle on shows addon selector', $combined);
        $this->assertStringContainsString('no addons skips selector even if toggle on', $combined);
        $this->assertStringContainsString('addon combinations stay on separate lines', $combined);
        $this->assertStringContainsString('selected addon price is included', $combined);
    }
}
