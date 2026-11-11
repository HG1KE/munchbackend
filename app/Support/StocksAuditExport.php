<?php

namespace App\Support;

use App\Services\Stock\StockVarianceService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Stocks manager daily audit PDF — same columns as the portal table
 * (Opening / Received / Closing / Sold / Result), styled like POS sale PDFs.
 */
class StocksAuditExport
{
    public const BRAND = 'MUNCH';

    public const COLOR_MUNCH = '#E7032D';

    public const TINT_MUNCH = '#FDE8EC';

    public const COLOR_EXCESS_BG = '#eef2f6';

    public const COLOR_EXCESS_TEXT = '#475569';

    public const COLOR_SHORT_BG = '#fef2f2';

    public const COLOR_SHORT_TEXT = '#b91c1c';

    public const COLOR_BALANCED_BG = '#f0fdf4';

    public const COLOR_BALANCED_TEXT = '#166534';

    public const INCOMPLETE_MESSAGE = 'Complete all fields for the branch and date selected before exporting the report.';

    /**
     * @param  list<array<string, mixed>>  $rows  From StockVarianceService::rowsForBranchDate
     * @return array{
     *     brand: string,
     *     branch_name: string,
     *     date: string,
     *     date_label: string,
     *     rows: list<array<string, mixed>>,
     *     filename_base: string,
     *     incomplete: bool
     * }
     */
    public static function build(string $branchName, CarbonInterface|string $date, array $rows): array
    {
        $day = self::parseDay($date);
        $branchName = trim($branchName) !== '' ? trim($branchName) : 'Branch';
        $normalized = [];
        $incomplete = false;

        foreach ($rows as $row) {
            $opening = self::nullableFloat($row['opening_qty'] ?? null);
            $received = self::nullableFloat($row['received_qty'] ?? null);
            $closing = self::nullableFloat($row['closing_qty'] ?? null);
            $sold = round((float) ($row['expected_sold'] ?? 0), 3);
            $variance = array_key_exists('variance', $row)
                ? self::nullableFloat($row['variance'])
                : StockVarianceService::result($opening, $received, $sold, $closing);

            if ($opening === null || $received === null || $closing === null) {
                $incomplete = true;
            }

            $kind = self::resultKind($variance);
            $normalized[] = [
                'stock_item_id' => (int) ($row['stock_item_id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'opening_qty' => $opening,
                'received_qty' => $received,
                'closing_qty' => $closing,
                'sold_qty' => $sold,
                'variance' => $variance,
                'variance_label' => (string) ($row['variance_label'] ?? self::defaultVarianceLabel($variance)),
                'result_kind' => $kind,
                'opening_display' => self::formatQty($opening),
                'received_display' => self::formatQty($received),
                'closing_display' => self::formatQty($closing),
                'sold_display' => self::formatQty($sold),
            ];
        }

        $dateLabel = self::formatReportDate($day);

        return [
            'brand' => self::BRAND,
            'branch_name' => $branchName,
            'date' => $day->toDateString(),
            'date_label' => $dateLabel,
            'rows' => $normalized,
            'filename_base' => self::filenameBase($branchName, $day),
            'incomplete' => $incomplete || $normalized === [],
        ];
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
        $branchName = trim($branchName);
        if ($branchName === '') {
            $branchName = 'Branch';
        }
        $prefix = str_starts_with(mb_strtolower($branchName), 'munch')
            ? $branchName
            : 'Munch '.$branchName;

        $day = self::parseDay($date);

        return AdminSaleReportExport::sanitizeFilename(
            $prefix.' Stocks Audit '.self::formatFilenameDate($day)
        );
    }

    /** e.g. Tuesday, 11th November 2026 */
    public static function formatReportDate(CarbonInterface|string $date): string
    {
        $day = self::parseDay($date);
        $n = (int) $day->format('j');

        return $day->format('l').', '.$n.self::ordinal($n).' '.$day->format('F Y');
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

        return $variance > 0 ? 'excess' : 'short';
    }

    /**
     * @return array{bg: string, text: string}
     */
    public static function resultColors(string $kind): array
    {
        return match ($kind) {
            'excess' => ['bg' => self::COLOR_EXCESS_BG, 'text' => self::COLOR_EXCESS_TEXT],
            'short' => ['bg' => self::COLOR_SHORT_BG, 'text' => self::COLOR_SHORT_TEXT],
            'balanced' => ['bg' => self::COLOR_BALANCED_BG, 'text' => self::COLOR_BALANCED_TEXT],
            default => ['bg' => '#f8fafc', 'text' => '#64748b'],
        };
    }

    private static function defaultVarianceLabel(?float $variance): string
    {
        return (new StockVarianceService())->formatVarianceLabel($variance, 'short');
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
