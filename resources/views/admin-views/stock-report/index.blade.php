@extends('layouts.admin.app')

@section('title', translate('Stock variance'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="tio-chart-bar-4"></i>
                <span class="page-header-title">{{ translate('Stock variance') }}</span>
            </h2>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="{{ route('admin.stock-report.index') }}" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="input-label">{{ translate('branch') }}</label>
                        <select name="branch_id" class="form-control" required>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" {{ (int)$branchId === (int)$branch->id ? 'selected' : '' }}>
                                    {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="input-label">{{ translate('date') }}</label>
                        <input type="date" name="date" class="form-control" value="{{ $date }}" required>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary">{{ translate('View') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    {{ $selectedBranch?->name ?? translate('Branch') }}
                    <span class="text-muted">— {{ $date }}</span>
                </h5>
            </div>
            <div class="table-responsive">
                <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table mb-0">
                    <thead class="thead-light">
                    <tr>
                        <th>{{ translate('Stock item') }}</th>
                        <th class="text-right">{{ translate('Opening') }}</th>
                        <th class="text-right">{{ translate('Closing') }}</th>
                        <th class="text-right">{{ translate('STOCK SOLD') }}</th>
                        <th class="text-right">{{ translate('Expected (sales)') }}</th>
                        <th class="text-right">{{ translate('Variance') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td class="text-right">{{ $row['opening_qty'] === null ? '—' : $row['opening_qty'] }}</td>
                            <td class="text-right">{{ $row['closing_qty'] === null ? '—' : $row['closing_qty'] }}</td>
                            <td class="text-right">{{ $row['physical_sold'] === null ? '—' : $row['physical_sold'] }}</td>
                            <td class="text-right">{{ $row['expected_sold'] }}</td>
                            <td class="text-right">
                                @if($row['variance'] !== null && abs($row['variance']) >= 0.0005)
                                    <span class="{{ $row['variance'] > 0 ? 'text-warning' : 'text-danger' }}">
                                        {{ $row['variance_label'] }}
                                    </span>
                                @else
                                    {{ $row['variance_label'] }}
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted">{{ translate('No stock items') }}</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
