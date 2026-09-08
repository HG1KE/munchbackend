{{-- Include in admin product add/edit Blade (e.g. after available time fields).
     Uses APP_TIMEZONE for interpretation of submitted datetimes.
     Send clear_storefront_visibility_schedule=1 to clear both columns. --}}
<div class="form-group">
    <label class="input-label">{{ translate('One-time visibility window') }} <small class="text-muted">({{ translate('optional') }})</small></label>
    <div class="row">
        <div class="col-md-6 mb-2">
            <label class="input-label">{{ translate('Visible from') }}</label>
            <input type="datetime-local" name="visible_from" class="form-control"
                   value="{{ old('visible_from', isset($product) && $product->visible_from ? $product->visible_from->format('Y-m-d\TH:i') : '') }}">
        </div>
        <div class="col-md-6 mb-2">
            <label class="input-label">{{ translate('Visible until') }}</label>
            <input type="datetime-local" name="visible_until" class="form-control"
                   value="{{ old('visible_until', isset($product) && $product->visible_until ? $product->visible_until->format('Y-m-d\TH:i') : '') }}">
        </div>
    </div>
    <small class="text-muted d-block mb-2">
        {{ translate('Leave both empty for normal visibility when the product is active. End time is exclusive at the exact timestamp.') }}
    </small>
    <div class="custom-control custom-checkbox">
        <input type="checkbox" class="custom-control-input" id="clear_storefront_visibility_schedule" name="clear_storefront_visibility_schedule" value="1" {{ old('clear_storefront_visibility_schedule') ? 'checked' : '' }}>
        <label class="custom-control-label" for="clear_storefront_visibility_schedule">{{ translate('Clear storefront visibility schedule') }}</label>
    </div>
</div>

<hr class="my-4">

@php
    $recurringInitial = old('recurring_rules');
    if ($recurringInitial === null) {
        $recurringInitial = isset($product) ? ($product->recurring_visibility_rules ?? []) : [];
    }
    if (! is_array($recurringInitial)) {
        $recurringInitial = [];
    }
    $recurringInitial = array_values(array_filter($recurringInitial, 'is_array'));
    if (count($recurringInitial) === 0) {
        $recurringInitial = [['day' => '', 'start' => '', 'end' => '']];
    }
@endphp

<div class="border rounded-lg p-3 mb-3 bg-light">
    <h6 class="mb-2 font-weight-bold">Weekly schedule <small class="text-muted font-weight-normal">(optional)</small></h6>
    <p class="text-muted small mb-3">
        Show this product only during these hours each week. Uses the store’s time zone. Same-day windows only: closing time must be after opening time. Leave rows empty or remove them if you don’t need a weekly pattern.
    </p>

    <div id="recurring-rules-list" class="mb-3">
        @foreach ($recurringInitial as $i => $rule)
            <div class="recurring-rule-row border rounded bg-white p-3 mb-3">
                <div class="row align-items-end">
                    <div class="col-lg-4 col-md-6 mb-2 mb-lg-0">
                        <label class="input-label mb-1">Day of week</label>
                        <select name="recurring_rules[{{ $i }}][day]" class="form-control recurring-rule-day">
                            <option value="">Choose day…</option>
                            @foreach (\App\Support\StorefrontVisibilitySchedule::WEEKDAYS as $wd)
                                <option value="{{ $wd }}" {{ (isset($rule['day']) && strtolower((string) $rule['day']) === $wd) ? 'selected' : '' }}>{{ ucfirst($wd) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-3 mb-2 mb-lg-0">
                        <label class="input-label mb-1">Opens at</label>
                        <input type="time" name="recurring_rules[{{ $i }}][start]" class="form-control" step="60"
                               value="{{ old("recurring_rules.$i.start", $rule['start'] ?? '') }}">
                    </div>
                    <div class="col-lg-3 col-md-3 mb-2 mb-lg-0">
                        <label class="input-label mb-1">Closes at</label>
                        <input type="time" name="recurring_rules[{{ $i }}][end]" class="form-control" step="60"
                               value="{{ old("recurring_rules.$i.end", $rule['end'] ?? '') }}">
                    </div>
                    <div class="col-lg-2 col-md-12 text-lg-right">
                        <button type="button" class="btn btn-sm btn-outline-danger recurring-rule-remove" title="Remove this rule">Remove</button>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <button type="button" class="btn btn-sm btn-outline-primary mb-3" id="recurring-rule-add">
        + Add rule
    </button>

    <div class="custom-control custom-checkbox mb-0">
        <input type="checkbox" class="custom-control-input" id="clear_recurring_visibility_rules" name="clear_recurring_visibility_rules" value="1" {{ old('clear_recurring_visibility_rules') ? 'checked' : '' }}>
        <label class="custom-control-label" for="clear_recurring_visibility_rules">Clear all weekly rules</label>
    </div>
</div>

<template id="recurring-rule-template">
    <div class="recurring-rule-row border rounded bg-white p-3 mb-3">
        <div class="row align-items-end">
            <div class="col-lg-4 col-md-6 mb-2 mb-lg-0">
                <label class="input-label mb-1">Day of week</label>
                <select name="recurring_rules[__INDEX__][day]" class="form-control recurring-rule-day">
                    <option value="">Choose day…</option>
                    @foreach (\App\Support\StorefrontVisibilitySchedule::WEEKDAYS as $wd)
                        <option value="{{ $wd }}">{{ ucfirst($wd) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-3 col-md-3 mb-2 mb-lg-0">
                <label class="input-label mb-1">Opens at</label>
                <input type="time" name="recurring_rules[__INDEX__][start]" class="form-control" step="60" value="">
            </div>
            <div class="col-lg-3 col-md-3 mb-2 mb-lg-0">
                <label class="input-label mb-1">Closes at</label>
                <input type="time" name="recurring_rules[__INDEX__][end]" class="form-control" step="60" value="">
            </div>
            <div class="col-lg-2 col-md-12 text-lg-right">
                <button type="button" class="btn btn-sm btn-outline-danger recurring-rule-remove" title="Remove this rule">Remove</button>
            </div>
        </div>
    </div>
</template>

<script>
(function () {
    var list = document.getElementById('recurring-rules-list');
    var tpl = document.getElementById('recurring-rule-template');
    var addBtn = document.getElementById('recurring-rule-add');
    if (!list || !tpl || !addBtn) return;

    function reindexRecurringRules() {
        var rows = list.querySelectorAll('.recurring-rule-row');
        rows.forEach(function (row, i) {
            row.querySelectorAll('[name^="recurring_rules["]').forEach(function (el) {
                el.name = el.name.replace(/recurring_rules\[\d+\]/, 'recurring_rules[' + i + ']');
            });
        });
    }

    function appendBlankRow() {
        var idx = list.querySelectorAll('.recurring-rule-row').length;
        var html = tpl.innerHTML.replace(/__INDEX__/g, String(idx));
        var wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        var row = wrap.firstElementChild;
        if (row) list.appendChild(row);
        reindexRecurringRules();
    }

    addBtn.addEventListener('click', appendBlankRow);

    list.addEventListener('click', function (e) {
        var btn = e.target.closest('.recurring-rule-remove');
        if (!btn) return;
        var row = btn.closest('.recurring-rule-row');
        if (row) row.remove();
        if (list.querySelectorAll('.recurring-rule-row').length === 0) appendBlankRow();
        else reindexRecurringRules();
    });
})();
</script>
