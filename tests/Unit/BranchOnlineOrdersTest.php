<?php

namespace Tests\Unit;

use App\Model\Branch;
use App\Support\BranchOnlineOrdering;
use App\Support\PosOrderTypes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BranchOnlineOrdersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureSchema();
    }

    public function test_online_orders_alerts_default_to_enabled_including_null(): void
    {
        $enabled = new Branch();
        $enabled->online_orders_enabled = 1;
        $this->assertTrue(BranchOnlineOrdering::isEnabled($enabled));

        $legacy = new Branch();
        $legacy->online_orders_enabled = null;
        $this->assertTrue(BranchOnlineOrdering::isEnabled($legacy));

        $this->assertTrue(BranchOnlineOrdering::isEnabled(null));

        $disabled = new Branch();
        $disabled->online_orders_enabled = 0;
        $this->assertFalse(BranchOnlineOrdering::isEnabled($disabled));
        $this->assertSame(['new_order' => 0], BranchOnlineOrdering::silencedAlertPayload());
    }

    public function test_customer_checkout_and_storefront_are_not_blocked(): void
    {
        $place = file_get_contents(app_path('Http/Controllers/Api/V1/OrderController.php'));
        $this->assertStringNotContainsString('BranchOnlineOrdering', $place);
        $this->assertStringNotContainsString('temporarily not accepting online orders', $place);

        $digital = file_get_contents(app_path('Http/Controllers/Api/V1/DigitalPaymentController.php'));
        $this->assertStringNotContainsString('BranchOnlineOrdering', $digital);

        $config = file_get_contents(app_path('CentralLogics/StorefrontConfigService.php'));
        $this->assertStringNotContainsString('BranchOnlineOrdering', $config);
        $this->assertStringNotContainsString("['can_order_today'] = false", $config);

        $helpers = file_get_contents(app_path('CentralLogics/helpers.php'));
        $this->assertStringNotContainsString('BranchOnlineOrdering', $helpers);
        $this->assertStringContainsString('$branchArray[\'can_order_today\'] = self::canOrderTodayForSchedules($todaySchedules, $currentTime);', $helpers);
        $this->assertStringNotContainsString('temporarily not accepting online orders', $helpers);
    }

    public function test_branch_dashboard_hides_online_orders_and_does_not_ring(): void
    {
        $dashboard = file_get_contents(resource_path('views/branch-views/dashboard.blade.php'));
        $this->assertStringContainsString("'showOnlineOrders' => \$showOnlineOrders ?? true", $dashboard);
        $this->assertStringContainsString("(\$showOnlineOrders ?? true) ? route('branch.dashboard.live-cards') : null", $dashboard);

        $tile = file_get_contents(resource_path('views/partials/_dashboard-online-orders.blade.php'));
        $this->assertStringContainsString('@if($showOnlineOrders)', $tile);
        $this->assertStringContainsString("translate('Online Orders')", $tile);
        $this->assertStringContainsString("translate('POS')", $tile);

        $sidebar = file_get_contents(resource_path('views/layouts/branch/partials/_sidebar.blade.php'));
        $this->assertStringContainsString('BranchOnlineOrdering::isEnabled(auth(\'branch\')->user())', $sidebar);

        $header = file_get_contents(resource_path('views/layouts/branch/partials/_header.blade.php'));
        $this->assertStringContainsString('BranchOnlineOrdering::isEnabled(auth(\'branch\')->user())', $header);

        $layout = file_get_contents(resource_path('views/layouts/branch/app.blade.php'));
        $this->assertStringContainsString('$branchAcceptsOnlineOrders && $admin_order_notification', $layout);

        $live = file_get_contents(app_path('Http/Controllers/Branch/DashboardLiveCardsController.php'));
        $this->assertStringContainsString("['online' => 0, 'express' => 0]", $live);

        $alert = file_get_contents(app_path('Http/Controllers/Branch/SystemController.php'));
        $this->assertStringContainsString('silencedAlertPayload()', $alert);

        $board = file_get_contents(app_path('Http/Controllers/Branch/OrderOperationsController.php'));
        $this->assertStringContainsString("redirect()->route('branch.dashboard')", $board);
        $this->assertStringContainsString('BranchOnlineOrdering::isEnabled', $board);
    }

    public function test_admin_dashboard_still_receives_and_rings_for_online_orders(): void
    {
        $adminDash = file_get_contents(app_path('Http/Controllers/Admin/DashboardController.php'));
        $this->assertStringContainsString('dashboardCounts(null)', $adminDash);
        $this->assertStringNotContainsString('BranchOnlineOrdering', $adminDash);

        $adminAlert = file_get_contents(app_path('Http/Controllers/Admin/SystemController.php'));
        $this->assertStringContainsString('pendingOrderAlertPayload(null)', $adminAlert);
        $this->assertStringNotContainsString('silencedAlertPayload', $adminAlert);

        $adminLayout = file_get_contents(resource_path('views/layouts/admin/app.blade.php'));
        $this->assertStringContainsString("route('admin.get-restaurant-data')", $adminLayout);
        $this->assertStringContainsString('munchPlayPendingOrderAlert', $adminLayout);
        $this->assertStringNotContainsString('BranchOnlineOrdering', $adminLayout);

        $ops = file_get_contents(app_path('Services/DashboardOrderOperationsService.php'));
        $this->assertStringNotContainsString('BranchOnlineOrdering', $ops);
        $this->assertStringContainsString('onlineOrders()', $ops);
    }

    public function test_pos_marketplace_and_reports_are_untouched(): void
    {
        $pos = file_get_contents(app_path('Http/Controllers/Branch/POSController.php'));
        $this->assertStringNotContainsString('BranchOnlineOrdering', $pos);
        $this->assertStringContainsString('function placeOrder', $pos);

        $this->assertFalse(PosOrderTypes::isOnlineOrder('pos', 'glovo'));
        $this->assertFalse(PosOrderTypes::isOnlineOrder('pos', 'uber'));
        $this->assertFalse(PosOrderTypes::isOnlineOrder('pos', 'bolt_food'));
        $this->assertFalse(PosOrderTypes::isOnlineOrder('pos', 'delivery'));
        $this->assertFalse(PosOrderTypes::isOnlineOrder('dine_in', 'dine_in'));
        $this->assertTrue(PosOrderTypes::isOnlineOrder('delivery', null));

        $table = file_get_contents(app_path('Http/Controllers/Api/V1/TableController.php'));
        $this->assertStringNotContainsString('BranchOnlineOrdering', $table);

        $report = file_get_contents(app_path('Http/Controllers/Admin/ReportController.php'));
        $this->assertStringNotContainsString('online_orders_enabled', $report);
        $this->assertStringNotContainsString('BranchOnlineOrdering', $report);
    }

    public function test_admin_branch_form_stores_the_dashboard_alert_switch(): void
    {
        $edit = file_get_contents(resource_path('views/admin-views/branch/edit.blade.php'));
        $this->assertStringContainsString('name="online_orders_enabled"', $edit);
        $this->assertStringContainsString("translate('Online Orders')", $edit);
        $this->assertStringContainsString('Customers can still order and Admin still receives them.', $edit);

        $controller = file_get_contents(app_path('Http/Controllers/Admin/BranchController.php'));
        $this->assertStringContainsString("'online_orders_enabled'", $controller);
        $this->assertStringNotContainsString('forgetCachedConfiguration()', $controller);

        $model = file_get_contents(app_path('Model/Branch.php'));
        $this->assertStringContainsString("'online_orders_enabled'", $model);

        $migration = file_get_contents(database_path('migrations/2026_09_10_210000_add_online_orders_enabled_to_branches_table.php'));
        $this->assertStringContainsString("boolean('online_orders_enabled')", $migration);
        $this->assertStringContainsString('nullable()', $migration);
        $this->assertStringContainsString('default(true)', $migration);
    }

    private function ensureSchema(): void
    {
        if (! Schema::hasTable('branches')) {
            Schema::create('branches', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->boolean('online_orders_enabled')->nullable()->default(true);
            });
        }
    }
}
