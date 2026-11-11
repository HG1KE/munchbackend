@extends('layouts.stocks.app')

@section('title', $branchName . ' — ' . translate('Stocks'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-4">
            <h2 class="h1 mb-0">{{ $branchName }}</h2>
            <form method="GET" action="{{ route('stocks.dashboard') }}" class="d-flex gap-2 align-items-center">
                <label class="mb-0 mr-2">{{ translate('date') }}</label>
                <input type="date" name="date" class="form-control" value="{{ $date }}" onchange="this.form.submit()">
            </form>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">{{ translate('Stock items sold') }} — {{ $date }}</h5>
            </div>
            <form method="POST" action="{{ route('stocks.counts.save') }}">
                @csrf
                <input type="hidden" name="date" value="{{ $date }}">
                <div class="table-responsive">
                    <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table mb-0">
                        <thead class="thead-light">
                        <tr>
                            <th>{{ translate('Stock item') }}</th>
                            <th style="min-width:120px">{{ translate('Opening') }}</th>
                            <th style="min-width:120px">{{ translate('Closing') }}</th>
                            <th class="text-right">{{ translate('STOCK SOLD') }}</th>
                            <th class="text-right">{{ translate('Expected (sales)') }}</th>
                            <th class="text-right">{{ translate('Variance') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($rows as $row)
                            <tr>
                                <td class="font-weight-bold">{{ $row['name'] }}</td>
                                <td>
                                    <input type="number" step="0.001" min="0" class="form-control"
                                           name="opening[{{ $row['stock_item_id'] }}]"
                                           value="{{ $row['opening_qty'] === null ? '' : $row['opening_qty'] }}"
                                           placeholder="0">
                                </td>
                                <td>
                                    <input type="number" step="0.001" min="0" class="form-control"
                                           name="closing[{{ $row['stock_item_id'] }}]"
                                           value="{{ $row['closing_qty'] === null ? '' : $row['closing_qty'] }}"
                                           placeholder="0">
                                </td>
                                <td class="text-right">
                                    {{ $row['physical_sold'] === null ? '—' : $row['physical_sold'] }}
                                </td>
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
                                <td colspan="6" class="text-center text-muted py-4">
                                    {{ translate('No active stock items. Ask admin to add stock items first.') }}
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if(count($rows))
                    <div class="card-footer d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">{{ translate('Save counts') }}</button>
                    </div>
                @endif
            </form>
        </div>
    </div>
@endsection
