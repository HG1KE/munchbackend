<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report['filename_base'] ?? 'Munch Sales' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        h1 { font-size: 22px; margin: 0 0 6px; letter-spacing: 2px; }
        .meta { margin: 0 0 4px; font-size: 13px; }
        .section { margin: 16px 0 10px; }
        .section-head {
            font-size: 13px;
            letter-spacing: 0.6px;
            font-weight: bold;
            padding: 7px 10px;
            margin: 0 0 0;
        }
        table.report-table { width: 100%; border-collapse: collapse; margin: 0 0 6px; }
        table.report-table th, table.report-table td {
            text-align: left;
            padding: 5px 7px;
            border: 1px solid #cfcfcf;
            color: #111;
        }
        table.report-table th { font-size: 11px; text-transform: uppercase; }
        .num { text-align: right; white-space: nowrap; }
        .total-wrap { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .total-wrap td { padding: 6px 8px; font-weight: bold; border: 1px solid #cfcfcf; }
        .empty { color: #555; font-style: italic; padding: 6px 8px; border: 1px solid #cfcfcf; }
        .payments h2 { font-size: 13px; margin: 18px 0 8px; border-bottom: 1px solid #222; padding-bottom: 4px; }
        .payments table { width: 100%; border-collapse: collapse; }
        .payments td { padding: 4px 6px; border-bottom: 1px solid #ddd; }
        @php $themes = \App\Support\AdminSaleReportExport::sectionThemes(); @endphp
        @foreach($themes as $key => $theme)
        .section-{{ $key }} .section-head {
            background: {{ $theme['color'] }};
            color: {{ $theme['header_ink'] }};
        }
        .section-{{ $key }} table.report-table th {
            background: {{ $theme['tint'] }};
            color: #111;
        }
        .section-{{ $key }} .total-wrap td {
            background: {{ $theme['tint'] }};
            color: #111;
            border-top: 2px solid {{ $theme['color'] }};
        }
        @endforeach
    </style>
</head>
<body>
    <h1>MUNCH</h1>
    <p class="meta"><strong>Branch:</strong> {{ $report['branch_name'] ?? '' }}</p>
    <p class="meta"><strong>Sales Date:</strong> {{ $report['sales_date_label'] ?? '' }}</p>

    @php
        $totals = $report['totals'] ?? [];
        $payments = $report['payment_totals'] ?? [];
    @endphp

    @foreach(\App\Support\AdminSaleReportExport::SECTION_KEYS as $sectionKey)
        @php $section = $report['sections'][$sectionKey] ?? []; @endphp
        <div class="section section-{{ $sectionKey }}">
            <div class="section-head">{{ $section['heading'] ?? strtoupper($sectionKey) }}</div>
            @include('admin-views.report.partials._sale-report-export-orders', [
                'orders' => \App\Support\AdminSaleReportExport::sectionOrders($section),
                'sectionKey' => $sectionKey,
            ])
            <table class="total-wrap">
                <tr>
                    <td>{{ \App\Support\AdminSaleReportExport::formatSectionOrderCount($sectionKey, \App\Support\AdminSaleReportExport::sectionOrderCount($section)) }}</td>
                    <td class="num">{{ $section['total_label'] ?? (($section['label'] ?? $sectionKey).' Total') }}: {{ \App\Support\AdminSaleReportExport::formatAmount($totals[$sectionKey] ?? ($section['total'] ?? 0)) }}</td>
                </tr>
            </table>
        </div>
    @endforeach

    @php
        $cancelledKey = \App\Support\AdminSaleReportExport::CANCELLED_SECTION;
        $cancelled = $report['sections'][$cancelledKey] ?? [];
        $cancelledTotal = $report['cancelled']['total'] ?? ($cancelled['total'] ?? 0);
    @endphp
    <div class="section section-{{ $cancelledKey }}">
        <div class="section-head">{{ $cancelled['heading'] ?? 'CANCELLED' }}</div>
        <p class="empty" style="font-style:normal;border:0;padding:4px 0 8px;">Not included in sales</p>
        @include('admin-views.report.partials._sale-report-export-orders', [
            'orders' => \App\Support\AdminSaleReportExport::sectionOrders($cancelled),
            'sectionKey' => $cancelledKey,
        ])
        <table class="total-wrap">
            <tr>
                <td>{{ \App\Support\AdminSaleReportExport::formatSectionOrderCount($cancelledKey, \App\Support\AdminSaleReportExport::sectionOrderCount($cancelled)) }}</td>
                <td class="num">{{ $cancelled['total_label'] ?? 'Cancelled Total' }}: {{ \App\Support\AdminSaleReportExport::formatAmount($cancelledTotal) }}</td>
            </tr>
        </table>
    </div>

    <div class="payments">
        <h2>PAYMENT METHODS</h2>
        <table>
            @foreach(\App\Support\AdminSaleReportExport::paymentMethodLabels() as $key => $label)
                <tr>
                    <td>{{ $label }}</td>
                    <td class="num">{{ \App\Support\AdminSaleReportExport::formatAmount($payments[$key] ?? 0) }}</td>
                </tr>
            @endforeach
        </table>
    </div>
</body>
</html>
