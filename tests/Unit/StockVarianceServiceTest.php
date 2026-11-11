<?php

namespace Tests\Unit;

use App\Services\Stock\StockVarianceService;
use Tests\TestCase;

class StockVarianceServiceTest extends TestCase
{
    public function test_physical_sold_is_opening_minus_closing(): void
    {
        $this->assertSame(20.0, StockVarianceService::physicalSold(100, 80));
        $this->assertNull(StockVarianceService::physicalSold(100, null));
    }

    public function test_excess_and_missing_labels(): void
    {
        $svc = new StockVarianceService();

        // physical 60 vs expected 50 → 10 excess
        $this->assertSame(10.0, StockVarianceService::variance(60, 50));
        $this->assertSame('10 excess', $svc->formatVarianceLabel(10));

        // physical 40 vs expected 50 → 10 missing
        $this->assertSame(-10.0, StockVarianceService::variance(40, 50));
        $this->assertSame('10 missing', $svc->formatVarianceLabel(-10));

        $this->assertSame('balanced', $svc->formatVarianceLabel(0));
        $this->assertSame('—', $svc->formatVarianceLabel(null));
    }

    public function test_admin_and_stocks_ui_reference_variance(): void
    {
        $adminReport = file_get_contents(resource_path('views/admin-views/stock-report/index.blade.php'));
        $this->assertStringContainsString("translate('STOCK SOLD')", $adminReport);
        $this->assertStringContainsString('variance_label', $adminReport);

        $dashboard = file_get_contents(resource_path('views/stocks-views/dashboard/index.blade.php'));
        $this->assertStringContainsString("translate('STOCK SOLD')", $dashboard);
        $this->assertStringContainsString('opening[', $dashboard);
        $this->assertStringContainsString('closing[', $dashboard);
        $this->assertStringContainsString('showBranchPicker', $dashboard);
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
        $this->assertStringContainsString('admin.stock-item.index', $sidebar);
        $this->assertStringContainsString('admin.stock-report.index', $sidebar);

        $adminLogin = file_get_contents(resource_path('views/admin-views/auth/login.blade.php'));
        $this->assertStringContainsString('stocks.auth.login', $adminLogin);

        $auth = file_get_contents(config_path('auth.php'));
        $this->assertStringContainsString("'stocks'", $auth);
        $this->assertStringContainsString('stock_managers', $auth);
    }
}
