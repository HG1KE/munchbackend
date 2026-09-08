@extends('layouts.admin.app')

@section('title', translate('Order Automation'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0">{{ translate('Order Automation') }}</h2>
        </div>

        <div class="alert alert-soft-info">
            {{ translate('Bulk and scheduled completions use the same delivered transition as manual admin status updates (loyalty points, loyalty SMS, customer notifications).') }}
        </div>

        @if($lastRun)
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="mb-2">{{ translate('Last automation run') }} #{{ $lastRun->id }}</h5>
                    <p class="mb-1 small text-muted">
                        {{ translate('Type') }}: {{ $lastRun->run_type }}
                        · {{ translate('Finished') }}: {{ $lastRun->finished_at?->format('Y-m-d H:i') ?? '—' }}
                        · {{ translate('Dry run') }}: {{ $lastRun->dry_run ? translate('Yes') : translate('No') }}
                    </p>
                    <p class="mb-0 small">
                        {{ translate('Completed') }}: {{ $lastRun->completed_count }},
                        {{ translate('Skipped') }}: {{ $lastRun->skipped_count }},
                        {{ translate('Failed') }}: {{ $lastRun->failed_count }}
                    </p>
                </div>
            </div>
        @endif

        <div class="row g-3">
            <div class="col-lg-5">
                <div class="card h-100">
                    <div class="card-header">
                        <h4 class="mb-0">{{ translate('Automatic completion') }}</h4>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('admin.order-automation.update') }}" method="POST">
                            @csrf
                            <div class="d-flex align-items-center gap-4 mb-3">
                                <div class="custom-radio">
                                    <input type="radio" id="oa-on" name="is_enabled" value="1" {{ $settings->is_enabled ? 'checked' : '' }}>
                                    <label for="oa-on">{{ translate('Enabled') }}</label>
                                </div>
                                <div class="custom-radio">
                                    <input type="radio" id="oa-off" name="is_enabled" value="0" {{ ! $settings->is_enabled ? 'checked' : '' }}>
                                    <label for="oa-off">{{ translate('Disabled') }}</label>
                                </div>
                            </div>

                            <div class="form-group mb-3">
                                <label class="form-label">{{ translate('Auto-complete after (hours)') }}</label>
                                <input type="number" class="form-control" name="auto_complete_hours" min="1" max="720"
                                       value="{{ old('auto_complete_hours', $settings->auto_complete_hours) }}">
                            </div>

                            @php
                                $allStatuses = ['pending','confirmed','processing','picked_up','out_for_delivery','delivered','canceled','failed','returned','completed','cooking','done','refunded','payment_failed'];
                                $eligible = old('eligible_statuses', $settings->eligible_statuses ?? []);
                                $excluded = old('excluded_statuses', $settings->excluded_statuses ?? []);
                            @endphp

                            <div class="form-group mb-3">
                                <label class="form-label">{{ translate('Eligible statuses') }}</label>
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach($allStatuses as $st)
                                        <label class="badge badge-soft-secondary px-2 py-1">
                                            <input type="checkbox" name="eligible_statuses[]" value="{{ $st }}"
                                                {{ in_array($st, $eligible, true) ? 'checked' : '' }}>
                                            {{ str_replace('_', ' ', $st) }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <div class="form-group mb-3">
                                <label class="form-label">{{ translate('Always exclude statuses') }}</label>
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach($allStatuses as $st)
                                        <label class="badge badge-soft-danger px-2 py-1">
                                            <input type="checkbox" name="excluded_statuses[]" value="{{ $st }}"
                                                {{ in_array($st, $excluded, true) ? 'checked' : '' }}>
                                            {{ str_replace('_', ' ', $st) }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>

                            <div class="form-group mb-3">
                                <label class="form-label">{{ translate('Branch IDs') }} <span class="text-muted">({{ translate('optional') }})</span></label>
                                <input type="text" class="form-control" name="branch_ids"
                                       value="{{ old('branch_ids', $settings->branch_ids) }}" placeholder="1, 2, 3">
                            </div>

                            <div class="form-group mb-3">
                                <label class="form-label">{{ translate('Scheduled dry-run mode') }}</label>
                                <select class="form-control" name="dry_run">
                                    <option value="0" {{ ! $settings->dry_run ? 'selected' : '' }}>{{ translate('Live (send updates)') }}</option>
                                    <option value="1" {{ $settings->dry_run ? 'selected' : '' }}>{{ translate('Dry run (log only)') }}</option>
                                </select>
                            </div>

                            <div class="form-group mb-3">
                                <label class="form-label d-block">{{ translate('Require assigned deliveryman before auto-complete') }}</label>
                                <p class="text-muted small mb-2">
                                    {{ translate('When off, delivery orders without a rider can still be auto-completed. Takeaway, dine-in, and POS orders never require a rider.') }}
                                </p>
                                <div class="d-flex align-items-center gap-4">
                                    <div class="custom-radio">
                                        <input type="radio" id="oa-dm-on" name="require_delivery_man" value="1"
                                               {{ ($settings->require_delivery_man ?? false) ? 'checked' : '' }}>
                                        <label for="oa-dm-on">{{ translate('On') }}</label>
                                    </div>
                                    <div class="custom-radio">
                                        <input type="radio" id="oa-dm-off" name="require_delivery_man" value="0"
                                               {{ ! ($settings->require_delivery_man ?? false) ? 'checked' : '' }}>
                                        <label for="oa-dm-off">{{ translate('Off') }}</label>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary demo_check">{{ translate('Save settings') }}</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card mb-3">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">{{ translate('Manual bulk completion') }}</h4>
                        <span class="badge badge-soft-primary" id="oa-eligible-badge">{{ translate('Eligible now') }}: {{ $preview['count'] }}</span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">
                            {{ translate('Marks all currently eligible active orders as delivered (no age filter). Uses the standard delivered pipeline.') }}
                        </p>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-outline-primary" id="oa-preview-btn">{{ translate('Refresh count') }}</button>
                            <button type="button" class="btn btn-warning" data-toggle="modal" data-target="#oa-manual-modal">
                                {{ translate('Mark Eligible Orders Delivered') }}
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h4 class="mb-0">{{ translate('Recent automation runs') }}</h4>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover table-borderless table-thead-bordered card-table mb-0">
                                <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>{{ translate('Type') }}</th>
                                    <th>{{ translate('Dry run') }}</th>
                                    <th>{{ translate('Eligible') }}</th>
                                    <th>{{ translate('Completed') }}</th>
                                    <th>{{ translate('Skipped') }}</th>
                                    <th>{{ translate('Failed') }}</th>
                                    <th>{{ translate('Finished') }}</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                @forelse($runs as $run)
                                    <tr>
                                        <td>{{ $run->id }}</td>
                                        <td>{{ $run->run_type }}</td>
                                        <td>{{ $run->dry_run ? translate('Yes') : translate('No') }}</td>
                                        <td>{{ $run->eligible_count }}</td>
                                        <td>{{ $run->completed_count }}</td>
                                        <td>{{ $run->skipped_count }}</td>
                                        <td>{{ $run->failed_count }}</td>
                                        <td>{{ $run->finished_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                        <td>
                                            @if(is_array($run->entries) && count($run->entries) > 0)
                                                <button type="button" class="btn btn-sm btn-outline-secondary oa-entries-toggle"
                                                        data-target="oa-entries-{{ $run->id }}">{{ translate('Details') }}</button>
                                            @endif
                                        </td>
                                    </tr>
                                    @if(is_array($run->entries) && count($run->entries) > 0)
                                        <tr id="oa-entries-{{ $run->id }}" class="d-none">
                                            <td colspan="9">
                                                <pre class="small mb-0 bg-light p-2 rounded" style="max-height:200px;overflow:auto;">{{ json_encode($run->entries, JSON_PRETTY_PRINT) }}</pre>
                                            </td>
                                        </tr>
                                    @endif
                                @empty
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">{{ translate('no_data_found') }}</td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="oa-manual-modal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ translate('Confirm bulk delivery') }}</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>{{ translate('This will mark') }} <strong id="oa-modal-count">{{ $preview['count'] }}</strong> {{ translate('eligible orders as delivered.') }}</p>
                    <p class="text-muted small mb-0">{{ translate('Loyalty points, loyalty delivery SMS, and customer notifications will run per order.') }}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">{{ translate('Cancel') }}</button>
                    <form action="{{ route('admin.order-automation.run-manual') }}" method="POST" class="d-inline">
                        @csrf
                        <input type="hidden" name="dry_run" value="0">
                        <button type="submit" class="btn btn-warning demo_check">{{ translate('Confirm') }}</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('script_2')
<script>
    document.getElementById('oa-preview-btn')?.addEventListener('click', function () {
        fetch('{{ route('admin.order-automation.preview') }}?manual=1', {headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(r => r.json())
            .then(data => {
                const count = data.count ?? 0;
                document.getElementById('oa-modal-count').textContent = count;
                const badge = document.getElementById('oa-eligible-badge');
                if (badge) {
                    badge.textContent = '{{ translate('Eligible now') }}: ' + count;
                }
            });
    });

    document.querySelectorAll('.oa-entries-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const row = document.getElementById(btn.getAttribute('data-target'));
            if (row) {
                row.classList.toggle('d-none');
            }
        });
    });
</script>
@endpush
