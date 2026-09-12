<?php

namespace App\Support;

use App\CentralLogics\Helpers;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Rap2hpoutre\FastExcel\FastExcel;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared Sale Report export payload. PDF, CSV, and Excel all render this
 * structure so classification, timestamps, and totals cannot drift.
 */
class AdminSaleReportExport
{
    public const BRAND = 'MUNCH';

    public const FORMAT_PDF = 'pdf';

    public const FORMAT_CSV = 'csv';

    public const FORMAT_XLSX = 'xlsx';

    public const FORMAT_PRINT = 'print';

    public const MUNCH_CATEGORIES = ['dine_in', 'takeaway', 'delivery'];

    public const MARKETPLACE_CATEGORIES = ['glovo', 'uber', 'bolt_food'];

    /**
     * @return list<string>
     */
    public static function formats(): array
    {
        return [self::FORMAT_PDF, self::FORMAT_CSV, self::FORMAT_XLSX, self::FORMAT_PRINT];
    }

    public static function normalizeFormat(?string $format): string
    {
        $format = strtolower(trim((string) $format));
        if ($format === 'excel' || $format === 'xls') {
            return self::FORMAT_XLSX;
        }
        if (in_array($format, self::formats(), true)) {
            return $format;
        }

        return self::FORMAT_PDF;
    }

    public static function classify(?string $orderType, ?string $salesChannel): ?string
    {
        return PosOrderTypes::saleReportCategory($orderType, $salesChannel);
    }

    /**
     * @param  iterable<int, object|array<string, mixed>>  $orders
     * @param  array{
     *     branch_name?: string,
     *     from?: CarbonInterface|string,
     *     to?: CarbonInterface|string,
     *     payment_totals?: array<string, float|int|string>
     * }  $context
     * @return array<string, mixed>
     */
    public static function build(iterable $orders, array $context = []): array
    {
        $from = self::parseDay($context['from'] ?? Carbon::now());
        $to = self::parseDay($context['to'] ?? $from);
        $branchName = trim((string) ($context['branch_name'] ?? 'All Branches'));
        if ($branchName === '') {
            $branchName = 'All Branches';
        }

        $sections = self::emptySections();
        $seen = [];

        foreach ($orders as $order) {
            $id = self::orderId($order);
            if ($id !== '' && isset($seen[$id])) {
                continue;
            }
            $category = self::classify(
                self::value($order, 'order_type'),
                self::value($order, 'sales_channel')
            );
            if ($category === null) {
                continue;
            }
            if ($id !== '') {
                $seen[$id] = $category;
            }

            $row = self::orderRow($order, $category);
            $sectionKey = self::sectionKey($category);
            $sections[$sectionKey]['categories'][$category]['orders'][] = $row;
            $sections[$sectionKey]['categories'][$category]['total'] += $row['amount'];
            $sections[$sectionKey]['total'] += $row['amount'];
        }

        foreach ($sections as &$section) {
            $section['total'] = self::money($section['total']);
            foreach ($section['categories'] as &$category) {
                $category['total'] = self::money($category['total']);
                usort($category['orders'], static function (array $left, array $right): int {
                    return strcmp((string) $left['sort_at'], (string) $right['sort_at']);
                });
            }
            unset($category);
        }
        unset($section);

        $totals = [
            'munch_sales' => $sections['munch_sales']['total'],
            'glovo' => $sections['glovo']['total'],
            'uber' => $sections['uber']['total'],
            'bolt_food' => $sections['bolt_food']['total'],
        ];
        $totals['total_sales'] = self::money(
            $totals['munch_sales'] + $totals['glovo'] + $totals['uber'] + $totals['bolt_food']
        );

        $salesDateLabel = self::salesDateLabel($from, $to);

        return [
            'brand' => self::BRAND,
            'branch_name' => $branchName,
            'sales_date_label' => $salesDateLabel,
            'is_single_day' => $from->isSameDay($to),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'sections' => $sections,
            'totals' => $totals,
            'payment_totals' => self::normalizePaymentTotals($context['payment_totals'] ?? []),
            'assigned_order_ids' => $seen,
            'filename_base' => self::filenameBase($branchName, $salesDateLabel),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function filename(array $report, string $extension): string
    {
        $base = trim((string) ($report['filename_base'] ?? ''));
        if ($base === '') {
            $base = self::filenameBase(
                (string) ($report['branch_name'] ?? 'All Branches'),
                (string) ($report['sales_date_label'] ?? Carbon::now()->format('j F Y'))
            );
        }
        $extension = ltrim(strtolower($extension), '.');
        if ($extension === 'excel' || $extension === 'xls') {
            $extension = self::FORMAT_XLSX;
        }
        if (! in_array($extension, [self::FORMAT_PDF, self::FORMAT_CSV, self::FORMAT_XLSX], true)) {
            $extension = self::FORMAT_PDF;
        }

        return $base.'.'.$extension;
    }

    public static function filenameBase(string $branchName, string $salesDateLabel): string
    {
        $branchName = trim($branchName);
        if ($branchName === '') {
            $branchName = 'All Branches';
        }
        $prefix = str_starts_with(mb_strtolower($branchName), 'munch')
            ? $branchName
            : 'Munch '.$branchName;

        return self::sanitizeFilename($prefix.' Sales '.$salesDateLabel);
    }

    public static function salesDateLabel(CarbonInterface|string $from, CarbonInterface|string $to): string
    {
        $fromDay = self::parseDay($from);
        $toDay = self::parseDay($to);
        if ($fromDay->isSameDay($toDay)) {
            return self::formatOrdinalDate($fromDay);
        }

        return self::formatOrdinalDate($fromDay).' to '.self::formatOrdinalDate($toDay);
    }

    public static function formatOrdinalDate(CarbonInterface $date): string
    {
        $day = (int) $date->format('j');

        return $day.self::ordinal($day).' '.$date->format('F Y');
    }

    public static function formatTimestamp(mixed $value): string
    {
        if ($value instanceof CarbonInterface) {
            $dt = $value->copy();
        } else {
            $dt = TimezoneDisplay::parseStoredUtc($value);
        }
        if ($dt === null) {
            return '';
        }

        try {
            $dt = $dt->timezone(TimezoneDisplay::businessTimezone());
        } catch (\Throwable) {
            // keep the parsed instant
        }

        return $dt->format('j M Y h:i A');
    }

    public static function formatAmount(float|int|string $amount): string
    {
        $amount = self::money($amount);

        try {
            $formatted = Helpers::set_symbol($amount);
            if (is_string($formatted) && trim($formatted) !== '') {
                return $formatted;
            }
        } catch (\Throwable) {
            //
        }

        return 'Ksh '.number_format($amount, 2);
    }

    /**
     * Flatten the shared report so CSV and Excel receive the same rows.
     *
     * @param  array<string, mixed>  $report
     * @return list<array<string, string>>
     */
    public static function flattenRows(array $report): array
    {
        $rows = [];
        $rows[] = self::sheetRow(self::BRAND);
        $rows[] = self::sheetRow('Branch: '.($report['branch_name'] ?? ''));
        $rows[] = self::sheetRow('Sales Date: '.($report['sales_date_label'] ?? ''));
        $rows[] = self::sheetRow('');

        $munch = $report['sections']['munch_sales'] ?? [];
        $rows[] = self::sheetRow('MUNCH SALES');
        foreach (self::MUNCH_CATEGORIES as $category) {
            $block = $munch['categories'][$category] ?? ['label' => $category, 'orders' => []];
            $rows[] = self::sheetRow((string) ($block['label'] ?? $category));
            foreach ($block['orders'] ?? [] as $order) {
                $rows[] = self::orderSheetRow($order);
            }
        }
        $rows[] = self::sheetRow('Munch Sales Total', amount: self::formatAmount($report['totals']['munch_sales'] ?? 0));
        $rows[] = self::sheetRow('');

        $rows[] = self::sheetRow('MARKETPLACE SALES');
        foreach (self::MARKETPLACE_CATEGORIES as $category) {
            $section = $report['sections'][$category] ?? [];
            $label = (string) ($section['label'] ?? $category);
            $rows[] = self::sheetRow(strtoupper($label));
            foreach (($section['categories'][$category]['orders'] ?? []) as $order) {
                $rows[] = self::orderSheetRow($order);
            }
            $rows[] = self::sheetRow($label.' Total', amount: self::formatAmount($report['totals'][$category] ?? 0));
        }
        $rows[] = self::sheetRow('');
        $rows[] = self::sheetRow('TOTAL SALES', amount: self::formatAmount($report['totals']['total_sales'] ?? 0));
        $rows[] = self::sheetRow('');
        $rows[] = self::sheetRow('PAYMENT METHODS');
        foreach (self::paymentMethodLabels() as $key => $label) {
            $rows[] = self::sheetRow($label, amount: self::formatAmount($report['payment_totals'][$key] ?? 0));
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function csvString(array $report): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        $rows = self::flattenRows($report);
        if ($rows !== []) {
            fputcsv($handle, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($handle, array_values($row));
            }
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function downloadCsv(array $report, string $filename): StreamedResponse
    {
        return response()->streamDownload(static function () use ($report): void {
            echo "\xEF\xBB\xBF";
            echo self::csvString($report);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function downloadXlsx(array $report, string $filename): mixed
    {
        return (new FastExcel(self::flattenRows($report)))->download($filename);
    }

    public static function sanitizeFilename(string $name): string
    {
        $name = str_replace(['/', '\\', ':', '*', '?', '"', '<', '>', '|'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name, " \t\n\r\0\x0B.");
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function emptySections(): array
    {
        return [
            'munch_sales' => [
                'label' => 'Munch Sales',
                'heading' => 'MUNCH SALES',
                'total_label' => 'Munch Sales Total',
                'total' => 0.0,
                'categories' => [
                    'dine_in' => ['label' => 'Dine In', 'orders' => [], 'total' => 0.0],
                    'takeaway' => ['label' => 'Take Away', 'orders' => [], 'total' => 0.0],
                    'delivery' => ['label' => 'Delivery', 'orders' => [], 'total' => 0.0],
                ],
            ],
            'glovo' => [
                'label' => 'Glovo',
                'heading' => 'GLOVO',
                'total_label' => 'Glovo Total',
                'total' => 0.0,
                'categories' => [
                    'glovo' => ['label' => 'Glovo', 'orders' => [], 'total' => 0.0],
                ],
            ],
            'uber' => [
                'label' => 'Uber',
                'heading' => 'UBER',
                'total_label' => 'Uber Total',
                'total' => 0.0,
                'categories' => [
                    'uber' => ['label' => 'Uber', 'orders' => [], 'total' => 0.0],
                ],
            ],
            'bolt_food' => [
                'label' => 'Bolt Food',
                'heading' => 'BOLT FOOD',
                'total_label' => 'Bolt Food Total',
                'total' => 0.0,
                'categories' => [
                    'bolt_food' => ['label' => 'Bolt Food', 'orders' => [], 'total' => 0.0],
                ],
            ],
        ];
    }

    /**
     * @param  object|array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private static function orderRow(object|array $order, string $category): array
    {
        $createdAt = self::value($order, 'created_at');
        $sortAt = $createdAt instanceof CarbonInterface
            ? $createdAt->copy()->utc()->toIso8601String()
            : (string) ($createdAt ?? '');
        $salesCategory = in_array($category, self::MARKETPLACE_CATEGORIES, true)
            ? self::categoryLabel($category)
            : 'Munch Sales';

        return [
            'order_id' => self::orderId($order),
            'timestamp' => self::formatTimestamp($createdAt),
            'sort_at' => $sortAt,
            'order_number' => self::orderNumber($order),
            'platform_order_number' => PosOrderTypes::normalizePlatformOrderNumber(
                (string) (self::value($order, 'platform_order_number') ?? '')
            ),
            'sales_category' => $salesCategory,
            'order_type' => self::categoryLabel($category),
            'category' => $category,
            'amount' => self::money(self::value($order, 'order_amount') ?? 0),
        ];
    }

    /**
     * @param  object|array<string, mixed>  $order
     */
    private static function orderNumber(object|array $order): string
    {
        $display = self::value($order, 'order_display_id')
            ?? self::value($order, 'readable_order_id');
        if ($display !== null && trim((string) $display) !== '') {
            return (string) $display;
        }

        if ($order instanceof \App\Model\Order) {
            return OrderPublicNumber::display($order);
        }

        return OrderPublicNumber::display(is_array($order) ? $order : [
            'id' => self::orderId($order),
            'readable_order_id' => self::value($order, 'readable_order_id'),
        ]);
    }

    /**
     * @param  object|array<string, mixed>  $order
     */
    private static function orderId(object|array $order): string
    {
        $id = self::value($order, 'id');

        return $id === null ? '' : (string) $id;
    }

    /**
     * @param  object|array<string, mixed>  $order
     */
    private static function value(object|array $order, string $key): mixed
    {
        if (is_array($order)) {
            return $order[$key] ?? null;
        }

        return $order->{$key} ?? null;
    }

    private static function sectionKey(string $category): string
    {
        return in_array($category, self::MARKETPLACE_CATEGORIES, true) ? $category : 'munch_sales';
    }

    private static function categoryLabel(string $category): string
    {
        return match ($category) {
            'dine_in' => 'Dine In',
            'takeaway' => 'Take Away',
            'delivery' => 'Delivery',
            'glovo' => 'Glovo',
            'uber' => 'Uber',
            'bolt_food' => 'Bolt Food',
            default => $category,
        };
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, string>
     */
    private static function orderSheetRow(array $order): array
    {
        return self::sheetRow(
            (string) ($order['timestamp'] ?? ''),
            (string) ($order['order_number'] ?? ''),
            (string) ($order['platform_order_number'] ?? ''),
            (string) ($order['sales_category'] ?? ''),
            (string) ($order['order_type'] ?? ''),
            self::formatAmount($order['amount'] ?? 0)
        );
    }

    /**
     * @return array<string, string>
     */
    private static function sheetRow(
        string $timestamp,
        string $orderNumber = '',
        string $platformOrderNumber = '',
        string $salesCategory = '',
        string $orderType = '',
        string $amount = ''
    ): array {
        return [
            'Timestamp' => $timestamp,
            'Munch Order #' => $orderNumber,
            'Marketplace Order #' => $platformOrderNumber,
            'Sales Category' => $salesCategory,
            'Order Type' => $orderType,
            'Amount' => $amount,
        ];
    }

    /**
     * @param  array<string, float|int|string>  $totals
     * @return array<string, float>
     */
    private static function normalizePaymentTotals(array $totals): array
    {
        $normalized = [];
        foreach (array_keys(self::paymentMethodLabels()) as $key) {
            $normalized[$key] = self::money($totals[$key] ?? 0);
        }

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    public static function paymentMethodLabels(): array
    {
        return [
            'cash' => 'Cash',
            'card' => 'Card',
            'mpesa' => 'M-PESA',
            'paystack' => 'Paystack',
            'glovo' => 'Glovo',
            'uber' => 'Uber',
            'bolt_food' => 'Bolt Food',
        ];
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

    private static function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
