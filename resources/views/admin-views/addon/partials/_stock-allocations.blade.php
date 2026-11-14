@php
    $stockItems = $stockItems ?? collect();
    $existingAllocations = isset($addon) ? ($addon->stockAllocations ?? collect()) : collect();
@endphp
<div class="col-12">
    <div class="card h-100">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h4 class="mb-0 d-flex gap-2 align-items-center">
                <i class="tio-cube"></i>
                {{ translate('Stock allocation') }}
            </h4>
            <button type="button" class="btn btn-sm btn-outline-primary" id="add-stock-allocation-row">
                <i class="tio-add"></i> {{ translate('Add item') }}
            </button>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                {{ translate('Allocate stock items deducted when this addon is sold (qty per addon unit).') }}
            </p>
            <div id="stock-allocation-rows">
                @forelse($existingAllocations as $allocation)
                    <div class="row g-2 align-items-end mb-2 stock-allocation-row">
                        <div class="col-sm-7">
                            <label class="input-label">{{ translate('Stock item') }}</label>
                            <select name="stock_allocation_item_id[]" class="form-control">
                                <option value="">{{ translate('Select stock item') }}</option>
                                @foreach($stockItems as $item)
                                    <option value="{{ $item->id }}" {{ (int)$allocation->stock_item_id === (int)$item->id ? 'selected' : '' }}>
                                        {{ $item->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <label class="input-label">{{ translate('Qty per sale') }}</label>
                            <input type="number" step="0.001" min="0.001" name="stock_allocation_qty[]"
                                   class="form-control" value="{{ $allocation->quantity }}" placeholder="1">
                        </div>
                        <div class="col-sm-2">
                            <button type="button" class="btn btn-outline-danger btn-block remove-stock-allocation-row">
                                <i class="tio-clear"></i>
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="row g-2 align-items-end mb-2 stock-allocation-row">
                        <div class="col-sm-7">
                            <label class="input-label">{{ translate('Stock item') }}</label>
                            <select name="stock_allocation_item_id[]" class="form-control">
                                <option value="">{{ translate('Select stock item') }}</option>
                                @foreach($stockItems as $item)
                                    <option value="{{ $item->id }}">{{ $item->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-3">
                            <label class="input-label">{{ translate('Qty per sale') }}</label>
                            <input type="number" step="0.001" min="0.001" name="stock_allocation_qty[]"
                                   class="form-control" value="" placeholder="1">
                        </div>
                        <div class="col-sm-2">
                            <button type="button" class="btn btn-outline-danger btn-block remove-stock-allocation-row">
                                <i class="tio-clear"></i>
                            </button>
                        </div>
                    </div>
                @endforelse
            </div>
            <template id="stock-allocation-row-template">
                <div class="row g-2 align-items-end mb-2 stock-allocation-row">
                    <div class="col-sm-7">
                        <label class="input-label">{{ translate('Stock item') }}</label>
                        <select name="stock_allocation_item_id[]" class="form-control">
                            <option value="">{{ translate('Select stock item') }}</option>
                            @foreach($stockItems as $item)
                                <option value="{{ $item->id }}">{{ $item->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-3">
                        <label class="input-label">{{ translate('Qty per sale') }}</label>
                        <input type="number" step="0.001" min="0.001" name="stock_allocation_qty[]"
                               class="form-control" value="" placeholder="1">
                    </div>
                    <div class="col-sm-2">
                        <button type="button" class="btn btn-outline-danger btn-block remove-stock-allocation-row">
                            <i class="tio-clear"></i>
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>

@push('script')
<script>
    "use strict";
    (function () {
        var addBtn = document.getElementById('add-stock-allocation-row');
        var rows = document.getElementById('stock-allocation-rows');
        var tpl = document.getElementById('stock-allocation-row-template');
        if (!addBtn || !rows || !tpl) return;

        addBtn.addEventListener('click', function () {
            rows.insertAdjacentHTML('beforeend', tpl.innerHTML);
        });

        rows.addEventListener('click', function (e) {
            var btn = e.target.closest('.remove-stock-allocation-row');
            if (!btn) return;
            var row = btn.closest('.stock-allocation-row');
            if (!row) return;
            if (rows.querySelectorAll('.stock-allocation-row').length <= 1) {
                row.querySelectorAll('select, input').forEach(function (el) { el.value = ''; });
                return;
            }
            row.remove();
        });
    })();
</script>
@endpush
