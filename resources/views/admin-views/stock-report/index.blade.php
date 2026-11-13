@extends('layouts.admin.app')

@section('title', translate('Stock Report'))

@push('css_or_js')
    <style>
        .munch-admin-stock-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 1.6rem;
            padding: 0.2rem 0.65rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
            line-height: 1.2;
        }
        .munch-admin-stock-chip--excess {
            background: {{ \App\Support\StocksAuditExport::COLOR_EXCESS_BG }};
            color: {{ \App\Support\StocksAuditExport::COLOR_EXCESS_TEXT }};
        }
        .munch-admin-stock-chip--short {
            background: {{ \App\Support\StocksAuditExport::COLOR_SHORT_BG }};
            color: {{ \App\Support\StocksAuditExport::COLOR_SHORT_TEXT }};
        }
        .munch-admin-stock-chip--balanced {
            background: {{ \App\Support\StocksAuditExport::COLOR_BALANCED_BG }};
            color: {{ \App\Support\StocksAuditExport::COLOR_BALANCED_TEXT }};
        }
        .munch-admin-stock-chip--empty {
            background: transparent;
            color: #64748b;
            font-weight: 600;
            padding-left: 0;
            padding-right: 0;
        }
        .munch-admin-stock-day + .munch-admin-stock-day {
            margin-top: 1rem;
        }
    </style>
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="tio-chart-bar-4"></i>
                <span class="page-header-title">{{ translate('Stock Report') }}</span>
            </h2>
            @if($branchId > 0)
                <a class="btn btn-outline-primary"
                   href="{{ route('admin.stock-report.export', [
                        'branch_id' => $branchId,
                        'from' => $from->toDateString(),
                        'to' => $to->toDateString(),
                   ]) }}">
                    {{ translate('Export PDF') }}
                </a>
            @endif
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.stock-report.index') }}" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="input-label">{{ translate('branch') }}</label>
                        <select name="branch_id" class="form-control" required>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" {{ (int)$branchId === (int)$branch->id ? 'selected' : '' }}>
                                    {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="input-label">{{ translate('From') }}</label>
                        <input type="date" name="from" class="form-control" value="{{ $from->toDateString() }}" required>
                    </div>
                    <div class="col-md-3">
                        <label class="input-label">{{ translate('To') }}</label>
                        <input type="date" name="to" class="form-control" value="{{ $to->toDateString() }}" required>
                    </div>
                    <div class="col-md-3 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary">{{ translate('View') }}</button>
                        @if($branchId > 0)
                            <button type="submit"
                                    class="btn btn-outline-primary"
                                    formaction="{{ route('admin.stock-report.export') }}">
                                {{ translate('Export PDF') }}
                            </button>
                        @endif
                    </div>
                </form>
            </div>
        </div>

        @forelse($sections as $section)
            <div class="card munch-admin-stock-day">
                <div class="card-header">
                    <h5 class="mb-0">
                        {{ $selectedBranch?->name ?? translate('Branch') }}
                        <span class="text-muted">— {{ $section['date_label'] }}</span>
                    </h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table mb-0">
                        <thead class="thead-light">
                        <tr>
                            <th>{{ translate('Stock item') }}</th>
                            <th class="text-right">{{ translate('Opening Stock') }}</th>
                            <th class="text-right">{{ translate('Received') }}</th>
                            <th class="text-right">{{ translate('Spoiled') }}</th>
                            <th class="text-right">{{ translate('Closing Stock') }}</th>
                            <th class="text-right">{{ translate('Sold') }}</th>
                            <th class="text-right">{{ translate('Result') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($section['rows'] as $row)
                            @php
                                $chipKind = 'empty';
                                if ($row['variance'] !== null) {
                                    if (abs($row['variance']) < 0.0005) {
                                        $chipKind = 'balanced';
                                    } elseif ($row['variance'] > 0) {
                                        $chipKind = 'excess';
                                    } else {
                                        $chipKind = 'short';
                                    }
                                }
                            @endphp
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td class="text-right">{{ $row['opening_qty'] === null ? '—' : $row['opening_qty'] }}</td>
                                <td class="text-right">{{ $row['received_qty'] === null ? '—' : $row['received_qty'] }}</td>
                                <td class="text-right">{{ $row['spoiled_qty'] === null ? '—' : $row['spoiled_qty'] }}</td>
                                <td class="text-right">{{ $row['closing_qty'] === null ? '—' : $row['closing_qty'] }}</td>
                                <td class="text-right">{{ $row['expected_sold'] }}</td>
                                <td class="text-right">
                                    <span class="munch-admin-stock-chip munch-admin-stock-chip--{{ $chipKind }}">
                                        {{ $row['variance_label'] }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted">{{ translate('No stock items') }}</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="card">
                <div class="card-body text-center text-muted">
                    {{ translate('No stock items') }}
                </div>
            </div>
        @endforelse
    </div>
@endsection
