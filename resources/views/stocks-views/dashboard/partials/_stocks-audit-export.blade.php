<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report['filename_base'] ?? 'Munch Stocks Audit' }}</title>
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
            margin: 0;
            background: {{ \App\Support\StocksAuditExport::COLOR_MUNCH }};
            color: #fff;
        }
        table.report-table { width: 100%; border-collapse: collapse; margin: 0 0 6px; }
        table.report-table th, table.report-table td {
            text-align: left;
            padding: 5px 7px;
            border: 1px solid #cfcfcf;
            color: #111;
            vertical-align: middle;
        }
        table.report-table th {
            font-size: 11px;
            text-transform: uppercase;
            background: {{ \App\Support\StocksAuditExport::TINT_MUNCH }};
            color: #111;
        }
        .num { text-align: right; white-space: nowrap; }
        .item-name { font-weight: bold; }
        .result-chip {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: bold;
            white-space: nowrap;
        }
        .empty { color: #555; font-style: italic; padding: 6px 8px; border: 1px solid #cfcfcf; }
    </style>
</head>
<body>
    <h1>{{ $report['brand'] ?? 'MUNCH' }}</h1>
    <p class="meta"><strong>Branch:</strong> {{ $report['branch_name'] ?? '' }}</p>
    <p class="meta"><strong>Date:</strong> {{ $report['date_label'] ?? '' }}</p>

    <div class="section">
        <div class="section-head">STOCKS AUDIT</div>
        @if(empty($report['rows']))
            <p class="empty">No stock items</p>
        @else
            <table class="report-table">
                <thead>
                <tr>
                    <th>Stock item</th>
                    <th class="num">Opening Stock</th>
                    <th class="num">Received</th>
                    <th class="num">Closing Stock</th>
                    <th class="num">Sold</th>
                    <th class="num">Result</th>
                </tr>
                </thead>
                <tbody>
                @foreach($report['rows'] as $row)
                    @php
                        $colors = \App\Support\StocksAuditExport::resultColors($row['result_kind'] ?? 'empty');
                    @endphp
                    <tr>
                        <td class="item-name">{{ $row['name'] ?? '' }}</td>
                        <td class="num">{{ $row['opening_display'] ?? '—' }}</td>
                        <td class="num">{{ $row['received_display'] ?? '—' }}</td>
                        <td class="num">{{ $row['closing_display'] ?? '—' }}</td>
                        <td class="num">{{ $row['sold_display'] ?? '0' }}</td>
                        <td class="num">
                            <span class="result-chip"
                                  style="background: {{ $colors['bg'] }}; color: {{ $colors['text'] }};">
                                {{ $row['variance_label'] ?? '—' }}
                            </span>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
</body>
</html>
