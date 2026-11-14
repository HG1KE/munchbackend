@extends('layouts.stocks.app')

@section('title', $branchName . ' — ' . translate('Stocks'))

@section('content')
    <div class="content container-fluid">
        <div class="munch-stocks-page-header">
            <div>
                <div class="munch-stocks-page-header__eyebrow">{{ translate('Daily counts') }}</div>
                <h1 class="munch-stocks-page-header__title">{{ $branchName }}</h1>
                <p class="munch-stocks-page-header__hint">
                    {{ translate('Enter opening, received, spoiled, and closing stock. Counts save automatically as you type.') }}
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
            @php
                $openingGaps = $openingGaps ?? [];
                $allocationGaps = $allocationGaps ?? ['products' => [], 'addons' => []];
                $soldDrilldown = $soldDrilldown ?? [];
                $sessionOpeningGaps = session('opening_gaps', []);
                if (!empty($sessionOpeningGaps) && empty($openingGaps)) {
                    $openingGaps = $sessionOpeningGaps;
                }
            @endphp

            @if(!empty($openingGaps))
                <div class="alert alert-warning">
                    <strong>{{ translate('Opening continuity') }}:</strong>
                    {{ translate('Today\'s opening does not match yesterday\'s closing for one or more items.') }}
                    <ul class="mb-2 mt-2">
                        @foreach($openingGaps as $gap)
                            <li>
                                {{ $gap['name'] ?? ('#'.$gap['stock_item_id']) }}:
                                {{ translate('yesterday closing') }} {{ $gap['yesterday_closing'] }},
                                {{ translate('today opening') }} {{ $gap['today_opening'] }}
                                ({{ $gap['delta'] > 0 ? '+' : '' }}{{ $gap['delta'] }})
                            </li>
                        @endforeach
                    </ul>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="munch-ack-opening-gap"
                               form="munch-stocks-counts-form" name="acknowledge_opening_gap">
                        <label class="form-check-label" for="munch-ack-opening-gap">
                            {{ translate('I acknowledge the opening gap and still want to save/export') }}
                        </label>
                    </div>
                </div>
            @endif

            @if(!empty($allocationGaps['products']) || !empty($allocationGaps['addons']))
                <div class="alert alert-danger">
                    <strong>{{ translate('Allocation coverage') }}:</strong>
                    {{ translate('POS sales exist for products/addons with no stock allocations — Sold may be understated (false missing).') }}
                    <ul class="mb-0 mt-2">
                        @foreach(($allocationGaps['products'] ?? []) as $gap)
                            <li>{{ translate('Product') }} #{{ $gap['product_id'] }} {{ $gap['name'] }} — qty {{ $gap['qty_sold'] }}</li>
                        @endforeach
                        @foreach(($allocationGaps['addons'] ?? []) as $gap)
                            <li>{{ translate('Addon') }} #{{ $gap['addon_id'] }} {{ $gap['name'] }} — qty {{ $gap['qty_sold'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @php
                $hiddenPdfItems = $hiddenPdfItems ?? [];
                if (session('omitted_appendix')) {
                    $hiddenPdfItems = session('omitted_appendix');
                }
            @endphp
            @if(!empty($hiddenPdfItems))
                <div class="alert alert-warning">
                    <strong>{{ translate('Hidden from PDF') }}:</strong>
                    {{ translate('Some active items are off for Show in Stock PDF. Acknowledge to export with an appendix listing them.') }}
                    <ul class="mb-2 mt-2">
                        @foreach($hiddenPdfItems as $omit)
                            <li>{{ $omit['name'] ?? '' }} — {{ $omit['variance_label'] ?? '—' }}</li>
                        @endforeach
                    </ul>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="1" id="munch-ack-hidden"
                               form="munch-stocks-counts-form" name="acknowledge_hidden_items">
                        <label class="form-check-label" for="munch-ack-hidden">
                            {{ translate('I acknowledge hidden items will appear only in the PDF appendix') }}
                        </label>
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                    <h5 class="mb-0">
                        {{ translate('Stock items') }}
                        <span class="text-muted">— {{ $date }}</span>
                    </h5>
                    @if(count($rows))
                        <button type="button"
                                id="munch-stocks-export-report"
                                class="btn btn-sm btn-outline-primary"
                                data-export-url="{{ route('stocks.export') }}"
                                data-export-message="{{ translate('Complete all fields for the branch and date selected before exporting the report.') }}"
                                data-opening-gap-message="{{ translate('Acknowledge the opening continuity gap before exporting.') }}"
                                data-hidden-ack-message="{{ translate('Acknowledge hidden PDF items before exporting.') }}"
                                data-requires-opening-ack="{{ !empty($openingGaps) ? '1' : '0' }}"
                                data-requires-hidden-ack="{{ !empty($hiddenPdfItems) ? '1' : '0' }}">
                            {{ translate('Export') }} {{ $branchName }} {{ translate('Report') }}
                        </button>
                    @endif
                </div>
                <form id="munch-stocks-counts-form"
                      method="POST"
                      action="{{ route('stocks.counts.save') }}"
                      data-branch-id="{{ $branchId }}"
                      data-date="{{ $date }}"
                      data-fill-message="{{ translate('Fill in all boxes before saving') }}"
                      data-export-message="{{ translate('Complete all fields for the branch and date selected before exporting the report.') }}">
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
                                <th style="min-width:120px">{{ translate('Spoiled') }}</th>
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
                                            $chipKind = 'missing';
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
                                               name="spoiled[{{ $row['stock_item_id'] }}]"
                                               value="{{ old('spoiled.'.$row['stock_item_id'], $row['spoiled_qty'] === null ? '' : $row['spoiled_qty']) }}"
                                               placeholder="0"
                                               data-stocks-spoiled
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
                                    <td colspan="7" class="text-center munch-stocks-empty">
                                        {{ translate('No active stock items. Ask admin to add stock items first.') }}
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if(count($rows))
                        <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2">
                            <div id="munch-stocks-save-status"
                                 class="munch-stocks-save-status munch-stocks-save-status--idle"
                                 data-status="idle"
                                 aria-live="polite"></div>
                            <button type="submit" class="btn btn-sm btn-outline-secondary" title="{{ translate('Flush all rows when every box is filled') }}">
                                {{ translate('Save counts') }}
                            </button>
                        </div>
                    @endif
                </form>
            </div>

            @if(!empty($soldDrilldown))
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0">{{ translate('Sold drill-down') }}</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-borderless table-thead-bordered card-table mb-0">
                            <thead class="thead-light">
                            <tr>
                                <th>{{ translate('Stock item') }}</th>
                                <th>{{ translate('Order') }}</th>
                                <th>{{ translate('Product / Addon') }}</th>
                                <th class="text-right">{{ translate('Qty') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @php
                                $itemNames = collect($rows)->keyBy('stock_item_id');
                            @endphp
                            @foreach($soldDrilldown as $stockItemId => $lines)
                                @foreach($lines as $line)
                                    <tr>
                                        <td>{{ $itemNames[$stockItemId]['name'] ?? ('#'.$stockItemId) }}</td>
                                        <td>#{{ (int) ($line['order_id'] ?? 0) }}</td>
                                        <td>
                                            @if(!empty($line['addon_id']))
                                                {{ translate('Addon') }} #{{ (int) $line['addon_id'] }}
                                                {{ $line['addon_name'] ?? '' }}
                                            @else
                                                {{ translate('Product') }} #{{ (int) ($line['product_id'] ?? 0) }}
                                                {{ $line['product_name'] ?? '' }}
                                            @endif
                                        </td>
                                        <td class="text-right">{{ $line['quantity'] ?? 0 }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endif
    </div>
@endsection

@push('script')
    <script src="{{ asset('public/assets/admin/js/munch-stocks-dashboard.js') }}?v=1.7"></script>
@endpush
