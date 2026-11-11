@extends('layouts.stocks.app')

@section('title', $branchName . ' — ' . translate('Stocks'))

@section('content')
    <div class="content container-fluid">
        <div class="munch-stocks-page-header">
            <div>
                <div class="munch-stocks-page-header__eyebrow">{{ translate('Daily counts') }}</div>
                <h1 class="munch-stocks-page-header__title">{{ $branchName }}</h1>
                <p class="munch-stocks-page-header__hint">
                    {{ translate('Enter opening, received, and closing stock. Result updates as you type.') }}
                </p>
            </div>

            <form method="GET" action="{{ route('stocks.dashboard') }}" class="munch-stocks-filters">
                @if($showBranchPicker)
                    <div class="munch-stocks-filters__field">
                        <label for="stocks-branch">{{ translate('Branch') }}</label>
                        <select id="stocks-branch" name="branch_id" class="form-control" onchange="this.form.submit()">
                            @forelse($accessible as $branch)
                                <option value="{{ $branch->id }}" {{ (int) $branchId === (int) $branch->id ? 'selected' : '' }}>
                                    {{ $branch->name }}
                                </option>
                            @empty
                                <option value="">{{ translate('No branches assigned') }}</option>
                            @endforelse
                        </select>
                    </div>
                @elseif($branchId)
                    <input type="hidden" name="branch_id" value="{{ $branchId }}">
                @endif
                <div class="munch-stocks-filters__field">
                    <label for="stocks-date">{{ translate('Date') }}</label>
                    <input id="stocks-date" type="date" name="date" class="form-control" value="{{ $date }}" onchange="this.form.submit()">
                </div>
            </form>
        </div>

        @if($branchId <= 0)
            <div class="alert alert-warning mb-0">
                {{ translate('No branches assigned. Ask an admin to allocate branches to your account.') }}
            </div>
        @else
            <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <h5 class="mb-0">
                        {{ translate('Stock items') }}
                        <span class="text-muted">— {{ $date }}</span>
                    </h5>
                </div>
                <form id="munch-stocks-counts-form"
                      method="POST"
                      action="{{ route('stocks.counts.save') }}"
                      data-branch-id="{{ $branchId }}"
                      data-date="{{ $date }}"
                      data-fill-message="{{ translate('Fill in all boxes before saving') }}">
                    @csrf
                    <input type="hidden" name="date" value="{{ $date }}">
                    <input type="hidden" name="branch_id" value="{{ $branchId }}">
                    <div class="table-responsive">
                        <table id="munch-stocks-counts-table"
                               class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table mb-0"
                               data-branch-id="{{ $branchId }}"
                               data-date="{{ $date }}">
                            <thead class="thead-light">
                            <tr>
                                <th>{{ translate('Stock item') }}</th>
                                <th style="min-width:120px">{{ translate('Opening Stock') }}</th>
                                <th style="min-width:120px">{{ translate('Received') }}</th>
                                <th style="min-width:120px">{{ translate('Closing Stock') }}</th>
                                <th class="text-right">{{ translate('Sold') }}</th>
                                <th class="text-right">{{ translate('Result') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse($rows as $row)
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
                                <tr data-stocks-row data-expected="{{ $row['expected_sold'] }}">
                                    <td class="munch-stocks-item-name">{{ $row['name'] }}</td>
                                    <td>
                                        <input type="number" step="0.001" min="0" class="form-control"
                                               name="opening[{{ $row['stock_item_id'] }}]"
                                               value="{{ old('opening.'.$row['stock_item_id'], $row['opening_qty'] === null ? '' : $row['opening_qty']) }}"
                                               placeholder="0"
                                               data-stocks-opening
                                               inputmode="decimal">
                                    </td>
                                    <td>
                                        <input type="number" step="0.001" min="0" class="form-control"
                                               name="received[{{ $row['stock_item_id'] }}]"
                                               value="{{ old('received.'.$row['stock_item_id'], $row['received_qty'] === null ? '' : $row['received_qty']) }}"
                                               placeholder="0"
                                               data-stocks-received
                                               inputmode="decimal">
                                    </td>
                                    <td>
                                        <input type="number" step="0.001" min="0" class="form-control"
                                               name="closing[{{ $row['stock_item_id'] }}]"
                                               value="{{ old('closing.'.$row['stock_item_id'], $row['closing_qty'] === null ? '' : $row['closing_qty']) }}"
                                               placeholder="0"
                                               data-stocks-closing
                                               inputmode="decimal">
                                    </td>
                                    <td class="text-right">
                                        <span class="munch-stocks-qty" data-stocks-expected>
                                            {{ $row['expected_sold'] }}
                                        </span>
                                    </td>
                                    <td class="text-right">
                                        <span class="munch-stocks-chip munch-stocks-chip--{{ $chipKind }}"
                                              data-stocks-result>
                                            {{ $row['variance_label'] }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center munch-stocks-empty">
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
        @endif
    </div>
@endsection

@push('script')
    <script src="{{ asset('public/assets/admin/js/munch-stocks-dashboard.js') }}?v=1.3"></script>
@endpush
