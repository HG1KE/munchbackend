<?php

namespace Tests\Unit;

use App\Services\Stock\StockVarianceService;
use Tests\TestCase;

class StockVarianceServiceTest extends TestCase
{
    public function test_physical_sold_is_opening_plus_received_minus_closing_minus_spoiled(): void
    {
        $this->assertSame(28.0, StockVarianceService::physicalSold(100, 10, 80, 2));
        $this->assertSame(20.0, StockVarianceService::physicalSold(100, 0, 80, 0));
        $this->assertNull(StockVarianceService::physicalSold(100, null, 80, 0));
        $this->assertNull(StockVarianceService::physicalSold(100, 10, null, 0));
        $this->assertNull(StockVarianceService::physicalSold(100, 10, 80, null));
    }

    public function test_result_formula_matches_physical_sense(): void
    {
        // Opening 10 + Received 10 − Sold 0 − Spoiled 0 = 20 expected on hand
        // Closing 4 → 16 missing
        $this->assertSame(-16.0, StockVarianceService::result(10, 10, 0, 4, 0));
        // Closing 20 → balanced
        $this->assertSame(0.0, StockVarianceService::result(10, 10, 0, 20, 0));
        // Closing 22 → 2 excess
        $this->assertSame(2.0, StockVarianceService::result(10, 10, 0, 22, 0));

        // Nuggets: 150 open, 0 recv, 150 close, 4 sold → 4 excess
        $this->assertSame(4.0, StockVarianceService::result(150, 0, 4, 150, 0));

        // Spoilage reduces expected on hand: 100 + 20 − 50 − 5 = 65 expected; close 60 → 5 missing
        $this->assertSame(-5.0, StockVarianceService::result(100, 20, 50, 60, 5));

        $this->assertNull(StockVarianceService::result(100, null, 50, 60, 0));
        $this->assertNull(StockVarianceService::result(null, 0, 50, 60, 0));
        $this->assertNull(StockVarianceService::result(100, 0, 50, null, 0));
        $this->assertNull(StockVarianceService::result(100, 0, 50, 60, null));
    }

    public function test_excess_and_missing_labels(): void
    {
        $svc = new StockVarianceService();

        // expected sold 50 vs physical 40 → 10 excess (more on shelf than sales imply)
        $this->assertSame(10.0, StockVarianceService::variance(40, 50));
        $this->assertSame('10 excess', $svc->formatVarianceLabel(10));

        // expected sold 50 vs physical 60 → 10 missing
        $this->assertSame(-10.0, StockVarianceService::variance(60, 50));
        $this->assertSame('10 missing', $svc->formatVarianceLabel(-10));

        $this->assertSame('balanced', $svc->formatVarianceLabel(0));
        $this->assertSame('—', $svc->formatVarianceLabel(null));
    }

    public function test_admin_and_stocks_ui_reference_variance(): void
    {
        $adminReport = file_get_contents(resource_path('views/admin-views/stock-report/index.blade.php'));
        $this->assertStringNotContainsString("translate('STOCK SOLD')", $adminReport);
        $this->assertStringNotContainsString("translate('Variance')", $adminReport);
        $this->assertStringContainsString("translate('Stock Report')", $adminReport);
        $this->assertStringContainsString("translate('Opening Stock')", $adminReport);
        $this->assertStringContainsString("translate('Received')", $adminReport);
        $this->assertStringContainsString("translate('Spoiled')", $adminReport);
        $this->assertStringContainsString("translate('Closing Stock')", $adminReport);
        $this->assertStringContainsString("translate('Sold')", $adminReport);
        $this->assertStringContainsString("translate('Result')", $adminReport);
        $this->assertStringContainsString('received_qty', $adminReport);
        $this->assertStringContainsString('spoiled_qty', $adminReport);
        $this->assertStringContainsString('variance_label', $adminReport);
        $this->assertStringContainsString('munch-admin-stock-chip--excess', $adminReport);
        $this->assertStringContainsString('munch-admin-stock-chip--missing', $adminReport);
        $this->assertStringNotContainsString('munch-admin-stock-chip--short', $adminReport);
        $this->assertStringContainsString("name=\"from\"", $adminReport);
        $this->assertStringContainsString("name=\"to\"", $adminReport);
        $this->assertStringContainsString('admin.stock-report.export', $adminReport);

        $dashboard = file_get_contents(resource_path('views/stocks-views/dashboard/index.blade.php'));
        $this->assertStringNotContainsString("translate('STOCK SOLD')", $dashboard);
        $this->assertStringContainsString("translate('Opening Stock')", $dashboard);
        $this->assertStringContainsString("translate('Received')", $dashboard);
        $this->assertStringContainsString("translate('Spoiled')", $dashboard);
        $this->assertStringContainsString("translate('Closing Stock')", $dashboard);
        $this->assertStringContainsString("translate('Sold')", $dashboard);
        $this->assertStringContainsString("translate('Result')", $dashboard);
        $this->assertStringContainsString('opening[', $dashboard);
        $this->assertStringContainsString('received[', $dashboard);
        $this->assertStringContainsString('spoiled[', $dashboard);
        $this->assertStringContainsString('closing[', $dashboard);
        $this->assertStringContainsString('data-stocks-received', $dashboard);
        $this->assertStringContainsString('data-stocks-spoiled', $dashboard);
        $this->assertStringContainsString('showBranchPicker', $dashboard);
        $this->assertStringContainsString('munch-stocks-dashboard.js', $dashboard);
        $this->assertStringContainsString('data-stocks-result', $dashboard);
        $this->assertStringContainsString('data-fill-message', $dashboard);
        $this->assertStringContainsString('munch-stocks-chip--', $dashboard);
        $this->assertStringContainsString("'missing'", $dashboard);
        $this->assertStringContainsString("'excess'", $dashboard);
        $this->assertStringNotContainsString("'short'", $dashboard);

        $controller = file_get_contents(app_path('Http/Controllers/Stocks/DashboardController.php'));
        $this->assertStringContainsString("rowsForBranchDate(\$branchId, \$date, 'missing')", $controller);
        $this->assertStringNotContainsString("'short'", $controller);
        $this->assertStringContainsString('Fill in all boxes before saving', $controller);
        $this->assertStringContainsString("'saved' => 1", $controller);
        $this->assertStringContainsString('received_qty', $controller);
        $this->assertStringContainsString('spoiled_qty', $controller);
        $this->assertStringContainsString("'received'", $controller);
        $this->assertStringContainsString("'spoiled'", $controller);

        $js = file_get_contents(public_path('assets/admin/js/munch-stocks-dashboard.js'));
        $this->assertStringContainsString('missing', $js);
        $this->assertStringContainsString('excess', $js);
        $this->assertStringNotContainsString("'short'", $js);
        $this->assertStringNotContainsString('+ \' short\'', $js);
        $this->assertStringContainsString('+ \' missing\'', $js);
        $this->assertStringContainsString('data-stocks-opening', $js);
        $this->assertStringContainsString('data-stocks-received', $js);
        $this->assertStringContainsString('data-stocks-spoiled', $js);
        $this->assertStringContainsString('Closing − (Opening + Received', $js);
        $this->assertStringContainsString('Spoiled', $js);
        $this->assertStringContainsString('munch-stocks-draft:', $js);
        $this->assertStringContainsString('allBoxesFilled', $js);
        $this->assertStringContainsString('received:', $js);
        $this->assertStringContainsString('spoiled:', $js);
        $this->assertStringNotContainsString('data-stocks-sold', $js);

        $model = file_get_contents(app_path('Model/StockDailyCount.php'));
        $this->assertStringContainsString("'received_qty'", $model);
        $this->assertStringContainsString("'spoiled_qty'", $model);

        $migration = file_get_contents(database_path('migrations/2026_11_11_180000_add_received_qty_to_stock_daily_counts.php'));
        $this->assertStringContainsString('received_qty', $migration);

        $spoilMigration = file_get_contents(database_path('migrations/2026_11_13_120000_add_spoiled_qty_to_stock_daily_counts.php'));
        $this->assertStringContainsString('spoiled_qty', $spoilMigration);
    }

    public function test_product_allocation_ui_wired(): void
    {
        $partial = file_get_contents(resource_path('views/admin-views/product/partials/_stock-allocations.blade.php'));
        $this->assertStringContainsString('stock_allocation_item_id[]', $partial);
        $this->assertStringContainsString('stock_allocation_qty[]', $partial);

        $edit = file_get_contents(resource_path('views/admin-views/product/edit.blade.php'));
        $this->assertStringContainsString('_stock-allocations', $edit);

        $create = file_get_contents(resource_path('views/admin-views/product/index.blade.php'));
        $this->assertStringContainsString('_stock-allocations', $create);

        $controller = file_get_contents(app_path('Http/Controllers/Admin/ProductController.php'));
        $this->assertStringContainsString('ProductStockAllocationService', $controller);

        $list = file_get_contents(resource_path('views/admin-views/product/list.blade.php'));
        $this->assertStringContainsString('data-stock=', $list);
        $this->assertStringContainsString('munch-stock-modal', $list);
        $this->assertStringContainsString('stock_allocation_item_id[]', file_get_contents(public_path('assets/admin/js/munch-product-stock.js')));

        $allocController = file_get_contents(app_path('Http/Controllers/Admin/ProductStockAllocationController.php'));
        $this->assertStringContainsString('syncFromRequest', $allocController);
        $this->assertStringContainsString('payloadForProduct', $allocController);
    }

    public function test_sidebar_and_login_links(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/admin/partials/_sidebar.blade.php'));
        $this->assertStringContainsString("translate('Stocks')", $sidebar);
        $this->assertStringContainsString("translate('Stock Report')", $sidebar);
        $this->assertStringNotContainsString("translate('Stock variance')", $sidebar);
        $this->assertStringContainsString('admin.stock-item.index', $sidebar);
        $this->assertStringContainsString('admin.stock-report.index', $sidebar);

        $adminLogin = file_get_contents(resource_path('views/admin-views/auth/login.blade.php'));
        $this->assertStringContainsString('_munch-login-panel', $adminLogin);
        $script = file_get_contents(resource_path('views/admin-views/auth/partials/_munch-login-mode-script.blade.php'));
        $this->assertStringContainsString('stocks.auth.login', $script);

        $auth = file_get_contents(config_path('auth.php'));
        $this->assertStringContainsString("'stocks'", $auth);
        $this->assertStringContainsString('stock_managers', $auth);
    }
}
