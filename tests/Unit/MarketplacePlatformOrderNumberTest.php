<?php

namespace Tests\Unit;

use App\Support\PosOrderTypes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketplacePlatformOrderNumberTest extends TestCase
{
    public function test_migration_adds_nullable_platform_order_number(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_11_220000_add_platform_order_number_to_orders_table.php'));
        $this->assertStringContainsString("string('platform_order_number', 64)->nullable()", $migration);
        $this->assertStringContainsString("Schema::hasColumn('orders', 'platform_order_number')", $migration);

        $previous = config('database.default');
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        try {
            Schema::dropIfExists('orders');
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->string('sales_channel', 32)->nullable();
            });
            $migrator = require database_path('migrations/2026_09_11_220000_add_platform_order_number_to_orders_table.php');
            $migrator->up();
            $this->assertTrue(Schema::hasColumn('orders', 'platform_order_number'));
            DB::table('orders')->insert(['sales_channel' => 'takeaway']);
            $this->assertNull(DB::table('orders')->value('platform_order_number'));
        } finally {
            Schema::dropIfExists('orders');
            config(['database.default' => $previous]);
            DB::purge('sqlite');
        }
    }

    public function test_backend_and_details_reuse_the_dedicated_field(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $this->assertStringContainsString('marketplacePlatformOrderError', $controller);
        $this->assertStringContainsString("input('platform_order_number')", $controller);
        $this->assertStringContainsString('normalizePlatformOrderNumber', $controller);
        $this->assertStringContainsString('posDeliveryFieldError', $controller);

        $today = file_get_contents(app_path('Services/BranchPosTodayOrdersService.php'));
        $this->assertStringContainsString("'platform_order_number'", $today);
        $this->assertStringContainsString("'platform_order_label'", $today);

        $report = file_get_contents(app_path('Http/Controllers/Admin/ReportController.php'));
        $this->assertStringContainsString("'platform_order_number'", $report);
        $this->assertStringContainsString("'sales_channel_label'", $report);

        $adminDetails = file_get_contents(resource_path('views/admin-views/order/order-view.blade.php'));
        $branchDetails = file_get_contents(resource_path('views/branch-views/order/order-view.blade.php'));
        $this->assertStringContainsString("partials.platform-order-number", $adminDetails);
        $this->assertStringContainsString("partials.platform-order-number", $branchDetails);

        $partial = file_get_contents(resource_path('views/partials/platform-order-number.blade.php'));
        $this->assertStringContainsString('isMarketplaceChannel', $partial);
        $this->assertStringContainsString('platformOrderNumberLabel', $partial);

        $this->assertStringNotContainsString('window.location.reload()', file_get_contents(public_path('assets/admin/js/munch-pos-app.js')));
        $this->assertFalse(PosOrderTypes::isMarketplace('delivery'));
        $this->assertFalse(PosOrderTypes::isMarketplace('take_away'));
        $this->assertFalse(PosOrderTypes::isMarketplace('dine_in'));
    }

    public function test_marketplace_order_number_js_scenarios(): void
    {
        $node = trim((string) shell_exec('command -v node'));
        if ($node === '') {
            $this->markTestSkipped('node is required for marketplace POS order-number scenarios');
        }

        $script = base_path('tests/Js/pos-marketplace-order-number.test.js');
        $output = [];
        $code = 0;
        exec(escapeshellcmd($node).' '.escapeshellarg($script).' 2>&1', $output, $code);

        $this->assertSame(0, $code, implode("\n", $output));
        $combined = implode("\n", $output);
        $this->assertStringContainsString('marketplace place order opens the order-number modal', $combined);
        $this->assertStringContainsString('walk-in types skip the marketplace modal', $combined);
        $this->assertStringContainsString('empty marketplace number cannot submit', $combined);
        $this->assertStringContainsString('lowercase marketplace numbers become uppercase', $combined);
        $this->assertStringContainsString('delivery phone must be exactly 10 digits', $combined);
    }
}
