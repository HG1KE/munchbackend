<?php

namespace App\Services\Stock;

use App\Model\StockExportEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class StockExportArchiveService
{
    /**
     * Persist a minimal immutable snapshot of what was exported (payload + hashes).
     * Does not lock counts or require dual control.
     *
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $omitted
     */
    public function archive(
        int $branchId,
        string $countDate,
        array $report,
        array $omitted,
        string $pdfBinary,
        string $exporterRole = 'stocks',
        ?int $stockManagerId = null,
        ?int $adminId = null
    ): ?StockExportEvent {
        if (! Schema::hasTable('stock_export_events')) {
            return null;
        }

        $payload = [
            'branch_name' => $report['branch_name'] ?? null,
            'date' => $report['date'] ?? $countDate,
            'from' => $report['from'] ?? $countDate,
            'to' => $report['to'] ?? $countDate,
            'date_label' => $report['date_label'] ?? null,
            'rows' => $report['rows'] ?? [],
            'sections' => $report['sections'] ?? [],
            'omitted_appendix' => $report['omitted_appendix'] ?? $omitted,
            'sold_drilldown' => $report['sold_drilldown'] ?? [],
            'filename_base' => $report['filename_base'] ?? null,
            'exported_at' => now()->toIso8601String(),
            'timezone' => config('app.timezone'),
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadHash = hash('sha256', $payloadJson !== false ? $payloadJson : '');
        $pdfHash = hash('sha256', $pdfBinary);

        if ($omitted !== []) {
            Log::info('stock.export.omitted_items', [
                'branch_id' => $branchId,
                'count_date' => $countDate,
                'exporter_role' => $exporterRole,
                'stock_manager_id' => $stockManagerId,
                'admin_id' => $adminId,
                'omitted' => array_map(static function (array $row) {
                    return [
                        'stock_item_id' => $row['stock_item_id'] ?? null,
                        'name' => $row['name'] ?? null,
                        'variance_label' => $row['variance_label'] ?? null,
                        'sold_qty' => $row['sold_qty'] ?? $row['expected_sold'] ?? null,
                    ];
                }, $omitted),
            ]);
        }

        return StockExportEvent::query()->create([
            'branch_id' => $branchId,
            'count_date' => $countDate,
            'stock_manager_id' => $stockManagerId,
            'exporter_role' => $exporterRole,
            'admin_id' => $adminId,
            'payload' => $payload,
            'payload_hash' => $payloadHash,
            'pdf_hash' => $pdfHash,
            'omitted' => $omitted === [] ? null : $omitted,
        ]);
    }
}
