<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report['filename_base'] ?? 'Munch Sales' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 22px; margin: 0 0 6px; letter-spacing: 2px; }
        .meta { margin: 0 0 4px; font-size: 13px; }
        h2 { font-size: 14px; margin: 18px 0 8px; border-bottom: 1px solid #222; padding-bottom: 4px; }
        h3 { font-size: 12px; margin: 12px 0 6px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #ddd; }
        th { font-size: 11px; text-transform: uppercase; }
        .num { text-align: right; white-space: nowrap; }
        .total-row td { font-weight: bold; border-top: 1px solid #222; border-bottom: none; }
        .grand-row td { font-weight: bold; font-size: 13px; border-top: 2px solid #111; }
        .empty { color: #666; font-style: italic; }
    </style>
</head>
<body>
    <h1>MUNCH</h1>
    <p class="meta"><strong>Branch:</strong> {{ $report['branch_name'] ?? '' }}</p>
    <p class="meta"><strong>Sales Date:</strong> {{ $report['sales_date_label'] ?? '' }}</p>

    @php
        $munch = $report['sections']['munch_sales'] ?? [];
        $totals = $report['totals'] ?? [];
        $payments = $report['payment_totals'] ?? [];
    @endphp

    <h2>MUNCH SALES</h2>
    @foreach(\App\Support\AdminSaleReportExport::MUNCH_CATEGORIES as $category)
        @php $block = $munch['categories'][$category] ?? ['label' => $category, 'orders' => []]; @endphp
        <h3>{{ $block['label'] ?? $category }}</h3>
        @include('admin-views.report.partials._sale-report-export-orders', ['orders' => $block['orders'] ?? []])
    @endforeach
    <table>
        <tr class="total-row">
            <td>Munch Sales Total</td>
            <td class="num">{{ \App\Support\AdminSaleReportExport::formatAmount($totals['munch_sales'] ?? 0) }}</td>
        </tr>
    </table>

    <h2>MARKETPLACE SALES</h2>
    @foreach(\App\Support\AdminSaleReportExport::MARKETPLACE_CATEGORIES as $category)
        @php $section = $report['sections'][$category] ?? []; @endphp
        <h3>{{ $section['label'] ?? $category }}</h3>
        @include('admin-views.report.partials._sale-report-export-orders', ['orders' => $section['categories'][$category]['orders'] ?? []])
        <table>
            <tr class="total-row">
                <td>{{ $section['total_label'] ?? (($section['label'] ?? $category).' Total') }}</td>
                <td class="num">{{ \App\Support\AdminSaleReportExport::formatAmount($totals[$category] ?? 0) }}</td>
            </tr>
        </table>
    @endforeach

    <table>
        <tr class="grand-row">
            <td>TOTAL SALES</td>
            <td class="num">{{ \App\Support\AdminSaleReportExport::formatAmount($totals['total_sales'] ?? 0) }}</td>
        </tr>
    </table>

    <h2>PAYMENT METHODS</h2>
    <table>
        @foreach(\App\Support\AdminSaleReportExport::paymentMethodLabels() as $key => $label)
            <tr>
                <td>{{ $label }}</td>
                <td class="num">{{ \App\Support\AdminSaleReportExport::formatAmount($payments[$key] ?? 0) }}</td>
            </tr>
        @endforeach
    </table>
</body>
</html>
