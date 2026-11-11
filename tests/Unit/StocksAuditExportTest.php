<?php

namespace Tests\Unit;

use App\Support\StocksAuditExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Tests\TestCase;

class StocksAuditExportTest extends TestCase
{
    public function test_report_date_uses_weekday_and_ordinal_not_numeric_slash(): void
    {
        $label = StocksAuditExport::formatReportDate('2026-11-11');
        $this->assertSame('Wednesday, 11th November 2026', $label);
        $this->assertStringNotContainsString('11/11/2026', $label);
        $this->assertStringNotContainsString('2026-11-11', $label);

        $this->assertSame('Sunday, 1st November 2026', StocksAuditExport::formatReportDate('2026-11-01'));
        $this->assertSame('Monday, 2nd November 2026', StocksAuditExport::formatReportDate('2026-11-02'));
        $this->assertSame('Tuesday, 3rd November 2026', StocksAuditExport::formatReportDate('2026-11-03'));
        $this->assertSame('Friday, 13th November 2026', StocksAuditExport::formatReportDate('2026-11-13'));
    }

    public function test_filename_uses_branch_stocks_audit_and_two_digit_year(): void
    {
        $report = StocksAuditExport::build('Bamburi', '2026-11-11', [
            $this->sampleRow(),
        ]);

        $this->assertSame(
            'Munch Bamburi Stocks Audit 11th November 26.pdf',
            StocksAuditExport::filename($report)
        );
        $this->assertSame(
            'Munch Bamburi Stocks Audit 11th November 26',
            $report['filename_base']
        );

        $prefixed = StocksAuditExport::build('Munch Bamburi', '2026-11-11', [
            $this->sampleRow(),
        ]);
        $this->assertSame(
            'Munch Bamburi Stocks Audit 11th November 26.pdf',
            StocksAuditExport::filename($prefixed)
        );

        $unsafe = StocksAuditExport::filenameBase('Bamburi/West', '2026-11-11');
        $this->assertSame('Munch Bamburi West Stocks Audit 11th November 26', $unsafe);
    }

    public function test_build_marks_incomplete_when_opening_received_or_closing_blank(): void
    {
        $complete = StocksAuditExport::build('Bamburi', '2026-11-11', [
            $this->sampleRow(),
        ]);
        $this->assertTrue(StocksAuditExport::isComplete($complete));
        $this->assertFalse($complete['incomplete']);
        $this->assertSame('Wednesday, 11th November 2026', $complete['date_label']);
        $this->assertSame('excess', $complete['rows'][0]['result_kind']);
        $this->assertSame('10 excess', $complete['rows'][0]['variance_label']);

        $missingReceived = StocksAuditExport::build('Bamburi', '2026-11-11', [
            $this->sampleRow(['received_qty' => null, 'variance' => null, 'variance_label' => '—']),
        ]);
        $this->assertFalse(StocksAuditExport::isComplete($missingReceived));
        $this->assertTrue($missingReceived['incomplete']);

        $missingOpening = StocksAuditExport::build('Bamburi', '2026-11-11', [
            $this->sampleRow(['opening_qty' => null, 'variance' => null, 'variance_label' => '—']),
        ]);
        $this->assertFalse(StocksAuditExport::isComplete($missingOpening));

        $missingClosing = StocksAuditExport::build('Bamburi', '2026-11-11', [
            $this->sampleRow(['closing_qty' => null, 'variance' => null, 'variance_label' => '—']),
        ]);
        $this->assertFalse(StocksAuditExport::isComplete($missingClosing));
    }

    public function test_result_kinds_match_ui_chip_colors(): void
    {
        $this->assertSame('excess', StocksAuditExport::resultKind(5.0));
        $this->assertSame('short', StocksAuditExport::resultKind(-2.0));
        $this->assertSame('balanced', StocksAuditExport::resultKind(0.0));
        $this->assertSame('empty', StocksAuditExport::resultKind(null));

        $excess = StocksAuditExport::resultColors('excess');
        $this->assertSame('#eef2f6', $excess['bg']);
        $this->assertSame('#475569', $excess['text']);

        $short = StocksAuditExport::resultColors('short');
        $this->assertSame('#fef2f2', $short['bg']);
        $this->assertSame('#b91c1c', $short['text']);
    }

    public function test_pdf_view_columns_match_manager_table_without_stock_sold(): void
    {
        $report = StocksAuditExport::build('Bamburi', '2026-11-11', [
            $this->sampleRow(['variance' => -4, 'variance_label' => '4 short']),
            $this->sampleRow([
                'stock_item_id' => 2,
                'name' => 'Buns',
                'opening_qty' => 50,
                'received_qty' => 0,
                'closing_qty' => 40,
                'expected_sold' => 10,
                'variance' => 0,
                'variance_label' => 'balanced',
            ]),
        ]);

        $html = view('stocks-views.dashboard.partials._stocks-audit-export', compact('report'))->render();
        $this->assertStringContainsString('Opening Stock', $html);
        $this->assertStringContainsString('Received', $html);
        $this->assertStringContainsString('Closing Stock', $html);
        $this->assertStringContainsString('Sold', $html);
        $this->assertStringContainsString('Result', $html);
        $this->assertStringNotContainsString('STOCK SOLD', $html);
        $this->assertStringContainsString('Wednesday, 11th November 2026', $html);
        $this->assertStringContainsString('Bamburi', $html);
        $this->assertStringContainsString('4 short', $html);
        $this->assertStringContainsString('#fef2f2', $html);
        $this->assertStringContainsString('#b91c1c', $html);
        $this->assertStringContainsString('balanced', $html);

        $binary = Pdf::loadView('stocks-views.dashboard.partials._stocks-audit-export', compact('report'))->output();
        $this->assertNotSame('', $binary);
        $this->assertStringStartsWith('%PDF', $binary);
    }

    public function test_dashboard_wires_export_button_route_and_gate_message(): void
    {
        $dashboard = file_get_contents(resource_path('views/stocks-views/dashboard/index.blade.php'));
        $this->assertStringContainsString('munch-stocks-export-report', $dashboard);
        $this->assertStringContainsString("translate('Export') }} {{ \$branchName }} {{ translate('Report') }}", $dashboard);
        $this->assertStringContainsString('stocks.export', $dashboard);
        $this->assertStringContainsString(StocksAuditExport::INCOMPLETE_MESSAGE, $dashboard);
        $this->assertStringContainsString('munch-stocks-dashboard.js', $dashboard);

        $routes = file_get_contents(base_path('routes/stocks.php'));
        $this->assertStringContainsString("name('export')", $routes);
        $this->assertStringContainsString('exportReport', $routes);

        $controller = file_get_contents(app_path('Http/Controllers/Stocks/DashboardController.php'));
        $this->assertStringContainsString('function exportReport', $controller);
        $this->assertStringContainsString('StocksAuditExport', $controller);
        $this->assertStringContainsString('INCOMPLETE_MESSAGE', $controller);

        $js = file_get_contents(public_path('assets/admin/js/munch-stocks-dashboard.js'));
        $this->assertStringContainsString('munch-stocks-export-report', $js);
        $this->assertStringContainsString('data-export-url', $js);
        $this->assertStringContainsString(StocksAuditExport::INCOMPLETE_MESSAGE, $js);
    }

    public function test_build_range_uses_per_day_sections_and_range_filename(): void
    {
        $report = StocksAuditExport::buildRange('Bamburi', '2026-11-11', '2026-11-13', [
            [
                'date' => '2026-11-11',
                'rows' => [$this->sampleRow(['variance' => 10, 'variance_label' => '10 excess'])],
            ],
            [
                'date' => '2026-11-12',
                'rows' => [$this->sampleRow([
                    'variance' => -4,
                    'variance_label' => '4 short',
                    'expected_sold' => 54,
                ])],
            ],
            [
                'date' => '2026-11-13',
                'rows' => [$this->sampleRow([
                    'variance' => 0,
                    'variance_label' => 'balanced',
                    'expected_sold' => 60,
                ])],
            ],
        ]);

        $this->assertFalse($report['is_single_day']);
        $this->assertSame('2026-11-11', $report['from']);
        $this->assertSame('2026-11-13', $report['to']);
        $this->assertSame(
            'Wednesday, 11th November 2026 to Friday, 13th November 2026',
            $report['date_label']
        );
        $this->assertCount(3, $report['sections']);
        $this->assertSame('Wednesday, 11th November 2026', $report['sections'][0]['date_label']);
        $this->assertSame('Thursday, 12th November 2026', $report['sections'][1]['date_label']);
        $this->assertSame(
            'Munch Bamburi Stocks Audit 11th November 26 to 13th November 26.pdf',
            StocksAuditExport::filename($report)
        );

        $html = view('stocks-views.dashboard.partials._stocks-audit-export', compact('report'))->render();
        $this->assertStringContainsString('Date range', $html);
        $this->assertStringContainsString('STOCKS AUDIT — Wednesday, 11th November 2026', $html);
        $this->assertStringContainsString('STOCKS AUDIT — Thursday, 12th November 2026', $html);
        $this->assertStringContainsString('STOCKS AUDIT — Friday, 13th November 2026', $html);
        $this->assertStringContainsString('10 excess', $html);
        $this->assertStringContainsString('4 short', $html);
        $this->assertStringContainsString('#eef2f6', $html);
        $this->assertStringContainsString('#fef2f2', $html);

        $binary = Pdf::loadView('stocks-views.dashboard.partials._stocks-audit-export', compact('report'))->output();
        $this->assertStringStartsWith('%PDF', $binary);
    }

    public function test_admin_stock_report_wires_range_export_and_manager_columns(): void
    {
        $adminRoutes = file_get_contents(base_path('routes/admin.php'));
        $this->assertStringContainsString('[StockReportController::class, \'export\']', $adminRoutes);

        $controller = file_get_contents(app_path('Http/Controllers/Admin/StockReportController.php'));
        $this->assertStringContainsString('function export', $controller);
        $this->assertStringContainsString('buildRange', $controller);
        $this->assertStringContainsString("rowsForBranchDate(\$branchId, \$day, 'short')", $controller);
        $this->assertStringContainsString("'from'", $controller);
        $this->assertStringContainsString("'to'", $controller);

        $this->assertSame(
            'Wednesday, 11th November 2026 to Friday, 13th November 2026',
            StocksAuditExport::formatRangeLabel('2026-11-11', '2026-11-13')
        );
        $this->assertSame(
            'Wednesday, 11th November 2026',
            StocksAuditExport::formatRangeLabel('2026-11-11', '2026-11-11')
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sampleRow(array $overrides = []): array
    {
        return array_merge([
            'stock_item_id' => 1,
            'name' => 'Beef Patties',
            'opening_qty' => 100.0,
            'received_qty' => 20.0,
            'closing_qty' => 60.0,
            'physical_sold' => 60.0,
            'expected_sold' => 50.0,
            'variance' => 10.0,
            'variance_label' => '10 excess',
        ], $overrides);
    }
}
