<?php

namespace App\Support;

use App\Services\Stock\StockVarianceService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Stocks manager daily audit PDF — same columns as the portal table
 * (Opening / Received / Spoiled / Closing / Sold / Result), styled like POS sale PDFs.
 */
class StocksAuditExport
{
    public const BRAND = 'MUNCH';

    public const COLOR_MUNCH = '#E7032D';

    public const TINT_MUNCH = '#FDE8EC';

    public const COLOR_EXCESS_BG = '#eef2f6';

    public const COLOR_EXCESS_TEXT = '#475569';

    public const COLOR_MISSING_BG = '#fef2f2';

    public const COLOR_MISSING_TEXT = '#b91c1c';

    public const COLOR_BALANCED_BG = '#f0fdf4';

    public const COLOR_BALANCED_TEXT = '#166534';

    public const INCOMPLETE_MESSAGE = 'Complete all fields for the branch and date selected before exporting the report.';

    public const HIDDEN_ACK_MESSAGE = 'Some active stock items are hidden from this PDF (Show in Stock PDF off). Review the appendix and acknowledge before exporting.';

    /**
     * @param  list<array<string, mixed>>  $rows  From StockVarianceService::rowsForBranchDate
     * @param  array<int, list<array<string, mixed>>>  $soldDrilldown
     * @return array{
     *     brand: string,
     *     branch_name: string,
     *     date: string,
     *     from: string,
     *     to: string,
     *     date_label: string,
     *     is_single_day: bool,
     *     rows: list<array<string, mixed>>,
     *     sections: list<array{date:string,date_label:string,rows:list<array<string,mixed>>}>,
     *     omitted_appendix: list<array<string, mixed>>,
     *     sold_drilldown: array<int, list<array<string, mixed>>>,
     *     filename_base: string,
     *     incomplete: bool,
     *     requires_hidden_ack: bool
     * }
     */
    public static function build(
        string $branchName,
        CarbonInterface|string $date,
        array $rows,
        array $soldDrilldown = []
    ): array {
        $day = self::parseDay($date);

        return self::buildRange($branchName, $day, $day, [
            ['date' => $day->toDateString(), 'rows' => $rows],
        ], $soldDrilldown);
    }

    /**
     * Admin (and multi-day) audit PDF payload. Prefer one section per day for clarity.
     *
     * @param  list<array{date?:string,rows?:list<array<string,mixed>>}>  $dayRows
     * @param  array<int, list<array<string, mixed>>>  $soldDrilldown
     * @return array{
     *     brand: string,
     *     branch_name: string,
     *     date: string,
     *     from: string,
     *     to: string,
     *     date_label: string,
     *     is_single_day: bool,
     *     rows: list<array<string, mixed>>,
     *     sections: list<array{date:string,date_label:string,rows:list<array<string,mixed>>}>,
     *     omitted_appendix: list<array<string, mixed>>,
     *     sold_drilldown: array<int, list<array<string, mixed>>>,
     *     filename_base: string,
     *     incomplete: bool,
     *     requires_hidden_ack: bool
     * }
     */
    public static function buildRange(
        string $branchName,
        CarbonInterface|string $from,
        CarbonInterface|string $to,
        array $dayRows,
        array $soldDrilldown = []
    ): array {
        $fromDay = self::parseDay($from);
        $toDay = self::parseDay($to);
        if ($toDay->lt($fromDay)) {
            [$fromDay, $toDay] = [$toDay->copy(), $fromDay->copy()];
        }

        $branchName = trim($branchName) !== '' ? trim($branchName) : 'Branch';
        $sections = [];
        $incomplete = false;
        $flatRows = [];
        $omittedAppendix = [];

        foreach ($dayRows as $dayPayload) {
            $day = self::parseDay($dayPayload['date'] ?? $fromDay);
            $split = self::splitRowsForPdf(is_array($dayPayload['rows'] ?? null) ? $dayPayload['rows'] : []);
            $normalized = $split['included'];
            foreach ($split['omitted'] as $omittedRow) {
                $omittedRow['count_date'] = $day->toDateString();
                $omittedAppendix[] = $omittedRow;
            }
            foreach ($normalized as $row) {
                if (
                    $row['opening_qty'] === null
                    || $row['received_qty'] === null
                    || $row['spoiled_qty'] === null
                    || $row['closing_qty'] === null
                ) {
                    $incomplete = true;
                }
            }
            if ($normalized === []) {
                $incomplete = true;
            }

            $sections[] = [
                'date' => $day->toDateString(),
                'date_label' => self::formatReportDate($day),
                'rows' => $normalized,
            ];
            foreach ($normalized as $row) {
                $flatRows[] = $row;
            }
        }

        if ($sections === []) {
            $sections[] = [
                'date' => $fromDay->toDateString(),
                'date_label' => self::formatReportDate($fromDay),
                'rows' => [],
            ];
            $incomplete = true;
        }

        $isSingleDay = $fromDay->isSameDay($toDay);
        $dateLabel = self::formatRangeLabel($fromDay, $toDay);

        return [
            'brand' => self::BRAND,
            'branch_name' => $branchName,
            'date' => $fromDay->toDateString(),
            'from' => $fromDay->toDateString(),
            'to' => $toDay->toDateString(),
            'date_label' => $dateLabel,
            'is_single_day' => $isSingleDay,
            'rows' => $isSingleDay ? ($sections[0]['rows'] ?? []) : $flatRows,
            'sections' => $sections,
            'omitted_appendix' => $omittedAppendix,
            'sold_drilldown' => $soldDrilldown,
            'filename_base' => self::filenameBaseForRange($branchName, $fromDay, $toDay),
            'incomplete' => $incomplete || $flatRows === [],
            'requires_hidden_ack' => $omittedAppendix !== [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{included:list<array<string,mixed>>,omitted:list<array<string,mixed>>}
     */
    private static function splitRowsForPdf(array $rows): array
    {
        $included = [];
        $omitted = [];

        foreach ($rows as $row) {
            $normalized = self::normalizeRow($row);
            if (! self::shouldIncludeInPdf($row)) {
                $omitted[] = $normalized;
                continue;
            }
            $included[] = $normalized;
        }

        return ['included' => $included, 'omitted' => $omitted];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $opening = self::nullableFloat($row['opening_qty'] ?? null);
        $received = self::nullableFloat($row['received_qty'] ?? null);
        $spoiled = self::nullableFloat($row['spoiled_qty'] ?? null);
        $closing = self::nullableFloat($row['closing_qty'] ?? null);
        $sold = round((float) ($row['expected_sold'] ?? $row['sold_qty'] ?? 0), 3);
        $variance = array_key_exists('variance', $row)
            ? self::nullableFloat($row['variance'])
            : StockVarianceService::result($opening, $received, $sold, $closing, $spoiled);

        $kind = self::resultKind($variance);

        return [
            'stock_item_id' => (int) ($row['stock_item_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'show_in_stock_pdf' => self::shouldIncludeInPdf($row),
            'opening_qty' => $opening,
            'received_qty' => $received,
            'spoiled_qty' => $spoiled,
            'closing_qty' => $closing,
            'sold_qty' => $sold,
            'variance' => $variance,
            'variance_label' => (string) ($row['variance_label'] ?? self::defaultVarianceLabel($variance)),
            'result_kind' => $kind,
            'opening_display' => self::formatQty($opening),
            'received_display' => self::formatQty($received),
            'spoiled_display' => self::formatQty($spoiled),
            'closing_display' => self::formatQty($closing),
            'sold_display' => self::formatQty($sold),
        ];
    }

    /**
     * Default true when the flag is absent (older callers / fixtures).
     *
     * @param  array<string, mixed>  $row
     */
    public static function shouldIncludeInPdf(array $row): bool
    {
        if (! array_key_exists('show_in_stock_pdf', $row)) {
            return true;
        }

        return filter_var($row['show_in_stock_pdf'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function isComplete(array $report): bool
    {
        return empty($report['incomplete']) && ! empty($report['rows']);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function filename(array $report): string
    {
        $base = trim((string) ($report['filename_base'] ?? ''));
        if ($base === '') {
            $base = self::filenameBase(
                (string) ($report['branch_name'] ?? 'Branch'),
                (string) ($report['date'] ?? Carbon::now()->toDateString())
            );
        }

        return $base.'.pdf';
    }

    public static function filenameBase(string $branchName, CarbonInterface|string $date): string
    {
        $day = self::parseDay($date);

        return self::filenameBaseForRange($branchName, $day, $day);
    }

    public static function filenameBaseForRange(
        string $branchName,
        CarbonInterface|string $from,
        CarbonInterface|string $to
    ): string {
        $branchName = trim($branchName);
        if ($branchName === '') {
            $branchName = 'Branch';
        }
        $prefix = str_starts_with(mb_strtolower($branchName), 'munch')
            ? $branchName
            : 'Munch '.$branchName;

        $fromDay = self::parseDay($from);
        $toDay = self::parseDay($to);
        if ($toDay->lt($fromDay)) {
            [$fromDay, $toDay] = [$toDay->copy(), $fromDay->copy()];
        }

        $datePart = self::formatFilenameDate($fromDay);
        if (! $fromDay->isSameDay($toDay)) {
            $datePart .= ' to '.self::formatFilenameDate($toDay);
        }

        return AdminSaleReportExport::sanitizeFilename(
            $prefix.' Stocks Audit '.$datePart
        );
    }

    /** e.g. Tuesday, 11th November 2026 */
    public static function formatReportDate(CarbonInterface|string $date): string
    {
        $day = self::parseDay($date);
        $n = (int) $day->format('j');

        return $day->format('l').', '.$n.self::ordinal($n).' '.$day->format('F Y');
    }

    /** Single day or "from to to" with weekday + ordinal on each end. */
    public static function formatRangeLabel(
        CarbonInterface|string $from,
        CarbonInterface|string $to
    ): string {
        $fromDay = self::parseDay($from);
        $toDay = self::parseDay($to);
        if ($toDay->lt($fromDay)) {
            [$fromDay, $toDay] = [$toDay->copy(), $fromDay->copy()];
        }

        if ($fromDay->isSameDay($toDay)) {
            return self::formatReportDate($fromDay);
        }

        return self::formatReportDate($fromDay).' to '.self::formatReportDate($toDay);
    }

    /** e.g. 11th November 26 */
    public static function formatFilenameDate(CarbonInterface|string $date): string
    {
        $day = self::parseDay($date);
        $n = (int) $day->format('j');

        return $n.self::ordinal($n).' '.$day->format('F').' '.$day->format('y');
    }

    public static function formatQty(float|int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $n = round((float) $value, 3);
        $fixed = number_format(abs($n), 3, '.', '');
        $fixed = rtrim(rtrim($fixed, '0'), '.');
        if ($fixed === '') {
            $fixed = '0';
        }

        return ($n < 0 ? '-' : '').$fixed;
    }

    public static function resultKind(?float $variance): string
    {
        if ($variance === null) {
            return 'empty';
        }
        if (abs($variance) < 0.0005) {
            return 'balanced';
        }

        return $variance > 0 ? 'excess' : 'missing';
    }

    /**
     * @return array{bg: string, text: string}
     */
    public static function resultColors(string $kind): array
    {
        return match ($kind) {
            'excess' => ['bg' => self::COLOR_EXCESS_BG, 'text' => self::COLOR_EXCESS_TEXT],
            'missing' => ['bg' => self::COLOR_MISSING_BG, 'text' => self::COLOR_MISSING_TEXT],
            'balanced' => ['bg' => self::COLOR_BALANCED_BG, 'text' => self::COLOR_BALANCED_TEXT],
            default => ['bg' => '#f8fafc', 'text' => '#64748b'],
        };
    }

    private static function defaultVarianceLabel(?float $variance): string
    {
        return (new StockVarianceService())->formatVarianceLabel($variance, 'missing');
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 3);
    }

    private static function parseDay(CarbonInterface|string $value): Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::parse($value->toDateTimeString())->startOfDay();
        }

        return Carbon::parse((string) $value)->startOfDay();
    }

    private static function ordinal(int $day): string
    {
        if ($day % 100 >= 11 && $day % 100 <= 13) {
            return 'th';
        }

        return match ($day % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
}
