<?php

namespace Tests\Unit;

use App\Model\StockManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockManagerBranchesTest extends TestCase
{
    private string $previousConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = config('database.default');
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('stock_managers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('all_branches')->default(false);
            $table->boolean('status')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('stock_manager_branch', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_manager_id');
            $table->unsignedBigInteger('branch_id');
            $table->timestamps();
            $table->unique(['stock_manager_id', 'branch_id'], 'stock_manager_branch_unique');
        });

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Nyali', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Westlands', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'CBD', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('stock_manager_branch');
        Schema::dropIfExists('stock_managers');
        Schema::dropIfExists('branches');
        config(['database.default' => $this->previousConnection]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_pivot_branches_limit_access(): void
    {
        $manager = StockManager::query()->create([
            'name' => 'Manager',
            'email' => 'mgr@example.com',
            'password' => 'secret',
            'all_branches' => false,
            'status' => true,
        ]);
        $manager->branches()->sync([1, 3]);

        $ids = $manager->fresh()->accessibleBranches()->pluck('id')->sort()->values()->all();
        $this->assertSame([1, 3], $ids);
        $this->assertTrue($manager->canAccessBranch(1));
        $this->assertFalse($manager->canAccessBranch(2));
        $this->assertTrue($manager->canAccessBranch(3));
    }

    public function test_all_branches_flag_grants_every_branch(): void
    {
        $manager = StockManager::query()->create([
            'name' => 'All Mgr',
            'email' => 'all@example.com',
            'password' => 'secret',
            'all_branches' => true,
            'status' => true,
        ]);

        $ids = $manager->accessibleBranches()->pluck('id')->sort()->values()->all();
        $this->assertSame([1, 2, 3], $ids);
        $this->assertTrue($manager->canAccessBranch(2));
        $this->assertStringContainsString('All branches', $manager->branchLabel());
    }

    public function test_admin_and_dashboard_ui_support_multi_branch(): void
    {
        $index = file_get_contents(resource_path('views/admin-views/stock-manager/index.blade.php'));
        $this->assertStringContainsString('branch_ids[]', $index);
        $this->assertStringContainsString('all_branches', $index);
        $this->assertStringContainsString("translate('All branches')", $index);

        $edit = file_get_contents(resource_path('views/admin-views/stock-manager/edit.blade.php'));
        $this->assertStringContainsString('branch_ids[]', $edit);
        $this->assertStringContainsString('all_branches', $edit);

        $dashboard = file_get_contents(resource_path('views/stocks-views/dashboard/index.blade.php'));
        $this->assertStringContainsString('showBranchPicker', $dashboard);
        $this->assertStringContainsString('name="branch_id"', $dashboard);

        $controller = file_get_contents(app_path('Http/Controllers/Admin/StockManagerController.php'));
        $this->assertStringContainsString("branches()->sync", $controller);
        $this->assertStringContainsString('all_branches', $controller);
    }
}
