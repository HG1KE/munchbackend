<?php

namespace App\Support;

use App\CentralLogics\Helpers;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
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

    public const SECTION_KEYS = ['munch_sales', 'glovo', 'uber', 'bolt_food'];

    /** Exact Munch brand red. */
    public const COLOR_MUNCH = '#E7032D';

    /** Official Glovo brand yellow. */
    public const COLOR_GLOVO = '#FFC244';

    /** Official Uber Eats green from Uber's published creative palette. */
    public const COLOR_UBER = '#06C167';

    /** Official Bolt green. */
    public const COLOR_BOLT_FOOD = '#34D186';

    public const TINT_MUNCH = '#FDE8EC';

    public const TINT_GLOVO = '#FFF8E6';

    public const TINT_UBER = '#E8F9F0';

    public const TINT_BOLT_FOOD = '#E9F9F2';

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
        $includeDate = ! $from->isSameDay($to);

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

            $row = self::orderRow($order, $category, $includeDate);
            $sectionKey = self::sectionKey($category);
            $sections[$sectionKey]['categories'][$category]['orders'][] = $row;
            $sections[$sectionKey]['categories'][$category]['total'] += $row['amount'];
            $sections[$sectionKey]['orders'][] = $row;
            $sections[$sectionKey]['total'] += $row['amount'];
        }

        foreach ($sections as &$section) {
            $section['total'] = self::money($section['total']);
            $section['order_count'] = count($section['orders']);
            foreach ($section['categories'] as &$category) {
                $category['total'] = self::money($category['total']);
                $category['order_count'] = count($category['orders']);
                usort($category['orders'], static function (array $left, array $right): int {
                    return strcmp((string) $left['sort_at'], (string) $right['sort_at']);
                });
            }
            unset($category);
            usort($section['orders'], static function (array $left, array $right): int {
                return strcmp((string) $left['sort_at'], (string) $right['sort_at']);
            });
        }
        unset($section);

        $totals = [
            'munch_sales' => $sections['munch_sales']['total'],
            'glovo' => $sections['glovo']['total'],
            'uber' => $sections['uber']['total'],
            'bolt_food' => $sections['bolt_food']['total'],
        ];
        $orderCounts = [
            'munch_sales' => $sections['munch_sales']['order_count'],
            'glovo' => $sections['glovo']['order_count'],
            'uber' => $sections['uber']['order_count'],
            'bolt_food' => $sections['bolt_food']['order_count'],
        ];

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
            'order_counts' => $orderCounts,
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

    public static function formatTimestamp(mixed $value, bool $includeDate = false): string
    {
        return self::formatTime($value, $includeDate);
    }

    public static function formatTime(mixed $value, bool $includeDate = false): string
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

        return $includeDate
            ? $dt->format('j M Y g:i A')
            : $dt->format('g:i A');
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

    public static function formatOrderCount(int $count): string
    {
        return 'Total Orders: '.$count;
    }

    /**
     * @param  array<string, mixed>  $section
     */
    public static function sectionOrderCount(array $section): int
    {
        if (isset($section['order_count'])) {
            return (int) $section['order_count'];
        }

        return count(self::sectionOrders($section));
    }

    /**
     * @param  array<string, mixed>  $section
     * @return list<array<string, mixed>>
     */
    public static function sectionOrders(array $section): array
    {
        if (isset($section['orders']) && is_array($section['orders'])) {
            return $section['orders'];
        }

        $orders = [];
        foreach ($section['categories'] ?? [] as $category) {
            foreach ($category['orders'] ?? [] as $order) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    /**
     * @return list<string>
     */
    public static function sectionColumnLabels(string $sectionKey): array
    {
        if ($sectionKey === 'munch_sales') {
            return ['Time', 'Munch Order #', 'Type', 'Amount'];
        }

        $platform = self::marketplaceOrderColumn($sectionKey);
        if ($platform === '') {
            return ['Time', 'Munch Order #', 'Type', 'Amount'];
        }

        return ['Time', 'Munch Order #', $platform, 'Type', 'Amount'];
    }

    public static function marketplaceOrderColumn(string $sectionKey): string
    {
        return match ($sectionKey) {
            'glovo' => 'Glovo Order #',
            'uber' => 'Uber Order #',
            'bolt_food' => 'Bolt Food Order #',
            default => '',
        };
    }

    /**
     * Flatten the shared report so CSV and Excel receive the same rows.
     *
     * @param  array<string, mixed>  $report
     * @return list<array{type: string, section: string|null, cells: list<string>}>
     */
    public static function flattenExport(array $report): array
    {
        $rows = [];
        $rows[] = self::exportRow('brand', null, [self::BRAND]);
        $rows[] = self::exportRow('meta', null, ['Branch: '.($report['branch_name'] ?? '')]);
        $rows[] = self::exportRow('meta', null, ['Sales Date: '.($report['sales_date_label'] ?? '')]);
        $rows[] = self::exportRow('blank', null, ['']);

        foreach (self::SECTION_KEYS as $sectionKey) {
            $section = $report['sections'][$sectionKey] ?? [];
            $heading = (string) ($section['heading'] ?? strtoupper((string) ($section['label'] ?? $sectionKey)));
            $columns = self::sectionColumnLabels($sectionKey);
            $rows[] = self::exportRow('section', $sectionKey, [$heading]);
            $rows[] = self::exportRow('columns', $sectionKey, $columns);

            $orders = self::sectionOrders($section);
            if ($orders === []) {
                $rows[] = self::exportRow('empty', $sectionKey, ['No orders']);
            } else {
                foreach ($orders as $order) {
                    $rows[] = self::exportRow('order', $sectionKey, self::orderCells($order, $sectionKey));
                }
            }

            $rows[] = self::exportRow('total', $sectionKey, self::sectionTotalCells($section, $sectionKey, $report));
            $rows[] = self::exportRow('blank', null, ['']);
        }

        $rows[] = self::exportRow('payment_heading', 'payments', ['PAYMENT METHODS']);
        foreach (self::paymentMethodLabels() as $key => $label) {
            $rows[] = self::exportRow('payment', 'payments', [
                $label,
                self::formatAmount($report['payment_totals'][$key] ?? 0),
            ]);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<list<string>>
     */
    public static function flattenRows(array $report): array
    {
        return array_map(
            static fn (array $row): array => $row['cells'],
            self::flattenExport($report)
        );
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

        foreach (self::flattenExport($report) as $row) {
            fputcsv($handle, $row['cells']);
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
    public static function writeXlsx(string $path, array $report): void
    {
        $writer = new XlsxWriter(new XlsxOptions());
        $writer->openToFile($path);
        foreach (self::flattenExport($report) as $row) {
            $writer->addRow(Row::fromValues($row['cells'], self::excelRowStyle($row)));
        }
        $writer->close();
    }

    /**
     * @param  array<string, mixed>  $report
     */
    public static function downloadXlsx(array $report, string $filename): mixed
    {
        return response()->streamDownload(static function () use ($report): void {
            self::writeXlsx('php://output', $report);
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
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
    public static function sectionThemes(): array
    {
        return [
            'munch_sales' => [
                'color' => self::COLOR_MUNCH,
                'tint' => self::TINT_MUNCH,
                'header_ink' => '#FFFFFF',
            ],
            'glovo' => [
                'color' => self::COLOR_GLOVO,
                'tint' => self::TINT_GLOVO,
                'header_ink' => '#111111',
            ],
            'uber' => [
                'color' => self::COLOR_UBER,
                'tint' => self::TINT_UBER,
                'header_ink' => '#111111',
            ],
            'bolt_food' => [
                'color' => self::COLOR_BOLT_FOOD,
                'tint' => self::TINT_BOLT_FOOD,
                'header_ink' => '#111111',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function sectionTheme(string $sectionKey): array
    {
        return self::sectionThemes()[$sectionKey] ?? [
            'color' => '#111111',
            'tint' => '#F5F5F5',
            'header_ink' => '#FFFFFF',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function emptySections(): array
    {
        $themes = self::sectionThemes();

        return [
            'munch_sales' => [
                'label' => 'Munch Sales',
                'heading' => 'MUNCH SALES',
                'total_label' => 'Munch Sales Total',
                'total' => 0.0,
                'order_count' => 0,
                'orders' => [],
                'color' => $themes['munch_sales']['color'],
                'tint' => $themes['munch_sales']['tint'],
                'header_ink' => $themes['munch_sales']['header_ink'],
                'categories' => [
                    'dine_in' => ['label' => 'Dine In', 'orders' => [], 'total' => 0.0, 'order_count' => 0],
                    'takeaway' => ['label' => 'Take Away', 'orders' => [], 'total' => 0.0, 'order_count' => 0],
                    'delivery' => ['label' => 'Delivery', 'orders' => [], 'total' => 0.0, 'order_count' => 0],
                ],
            ],
            'glovo' => [
                'label' => 'Glovo',
                'heading' => 'GLOVO',
                'total_label' => 'Glovo Total',
                'total' => 0.0,
                'order_count' => 0,
                'orders' => [],
                'color' => $themes['glovo']['color'],
                'tint' => $themes['glovo']['tint'],
                'header_ink' => $themes['glovo']['header_ink'],
                'categories' => [
                    'glovo' => ['label' => 'Glovo', 'orders' => [], 'total' => 0.0, 'order_count' => 0],
                ],
            ],
            'uber' => [
                'label' => 'Uber',
                'heading' => 'UBER',
                'total_label' => 'Uber Total',
                'total' => 0.0,
                'order_count' => 0,
                'orders' => [],
                'color' => $themes['uber']['color'],
                'tint' => $themes['uber']['tint'],
                'header_ink' => $themes['uber']['header_ink'],
                'categories' => [
                    'uber' => ['label' => 'Uber', 'orders' => [], 'total' => 0.0, 'order_count' => 0],
                ],
            ],
            'bolt_food' => [
                'label' => 'Bolt Food',
                'heading' => 'BOLT FOOD',
                'total_label' => 'Bolt Food Total',
                'total' => 0.0,
                'order_count' => 0,
                'orders' => [],
                'color' => $themes['bolt_food']['color'],
                'tint' => $themes['bolt_food']['tint'],
                'header_ink' => $themes['bolt_food']['header_ink'],
                'categories' => [
                    'bolt_food' => ['label' => 'Bolt Food', 'orders' => [], 'total' => 0.0, 'order_count' => 0],
                ],
            ],
        ];
    }

    /**
     * @param  object|array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private static function orderRow(object|array $order, string $category, bool $includeDate = false): array
    {
        $createdAt = self::value($order, 'created_at');
        $sortAt = $createdAt instanceof CarbonInterface
            ? $createdAt->copy()->utc()->toIso8601String()
            : (string) ($createdAt ?? '');
        $salesCategory = in_array($category, self::MARKETPLACE_CATEGORIES, true)
            ? self::categoryLabel($category)
            : 'Munch Sales';
        $time = self::formatTime($createdAt, $includeDate);

        return [
            'order_id' => self::orderId($order),
            'time' => $time,
            'timestamp' => $time,
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
     * @return list<string>
     */
    private static function orderCells(array $order, string $sectionKey): array
    {
        $time = (string) ($order['time'] ?? $order['timestamp'] ?? '');
        $number = (string) ($order['order_number'] ?? '');
        $type = (string) ($order['order_type'] ?? '');
        $amount = self::formatAmount($order['amount'] ?? 0);
        if (self::marketplaceOrderColumn($sectionKey) === '') {
            return [$time, $number, $type, $amount];
        }

        return [$time, $number, (string) ($order['platform_order_number'] ?? ''), $type, $amount];
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $report
     * @return list<string>
     */
    private static function sectionTotalCells(array $section, string $sectionKey, array $report): array
    {
        $columns = self::sectionColumnLabels($sectionKey);
        $width = max(count($columns), 2);
        $cells = array_fill(0, $width, '');
        $cells[0] = self::formatOrderCount(self::sectionOrderCount($section));
        $cells[$width - 2] = (string) ($section['total_label'] ?? (($section['label'] ?? $sectionKey).' Total'));
        $cells[$width - 1] = self::formatAmount($report['totals'][$sectionKey] ?? ($section['total'] ?? 0));

        return $cells;
    }

    /**
     * @param  list<string>  $cells
     * @return array{type: string, section: string|null, cells: list<string>}
     */
    private static function exportRow(string $type, ?string $section, array $cells): array
    {
        return [
            'type' => $type,
            'section' => $section,
            'cells' => $cells,
        ];
    }

    /**
     * @param  array{type: string, section: string|null, cells: list<string>}  $row
     */
    private static function excelRowStyle(array $row): Style
    {
        $style = (new Style())
            ->setFontName('Arial')
            ->setFontSize(11)
            ->setFontColor('111111');

        $theme = $row['section'] ? self::sectionTheme($row['section']) : null;

        return match ($row['type']) {
            'brand' => $style->setFontBold()->setFontSize(16),
            'section' => $style
                ->setFontBold()
                ->setFontSize(12)
                ->setFontColor(self::excelHex($theme['header_ink'] ?? '#111111'))
                ->setBackgroundColor(self::excelHex($theme['color'] ?? '#111111')),
            'columns' => $style
                ->setFontBold()
                ->setBackgroundColor(self::excelHex($theme['tint'] ?? '#F5F5F5')),
            'total' => $style
                ->setFontBold()
                ->setBackgroundColor(self::excelHex($theme['tint'] ?? '#F5F5F5')),
            'payment_heading' => $style->setFontBold()->setFontSize(12),
            default => $style,
        };
    }

    private static function excelHex(string $color): string
    {
        return strtoupper(ltrim($color, '#'));
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
