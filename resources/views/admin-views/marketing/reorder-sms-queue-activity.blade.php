@extends('layouts.admin.app')

@section('title', translate('Reorder Reminder Activity'))

@push('css_or_js')
    <style>
        .munch-sms-queue-table { table-layout: fixed; width: 100%; }
        .munch-sms-queue-table th, .munch-sms-queue-table td {
            white-space: normal; word-break: break-word; overflow-wrap: anywhere;
            line-height: 1.45; vertical-align: top;
        }
        .munch-sms-queue-table .munch-sms-col--id { width: 4%; }
        .munch-sms-queue-table .munch-sms-col--status { width: 8%; }
        .munch-sms-queue-table .munch-sms-col--phone { width: 10%; }
        .munch-sms-queue-table .munch-sms-col--customer { width: 10%; }
        .munch-sms-queue-table .munch-sms-col--campaign { width: 10%; }
        .munch-sms-queue-table .munch-sms-col--message { width: 24%; }
        .munch-sms-queue-table .munch-sms-col--skip { width: 10%; }
        .munch-sms-queue-table .munch-sms-col--created { width: 9%; }
        .munch-sms-queue-table .munch-sms-col--sent { width: 9%; }
        .munch-sms-queue-table .munch-sms-col--attempt { width: 6%; }
    </style>
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <span class="page-header-title">{{ translate('Marketing') }} — {{ translate('Reorder Reminder Activity') }}</span>
            </h2>
        </div>

        @include('admin-views.marketing.partials.subnav')

        <p class="text-muted small mb-3">
            {{ translate('Reorder reminder SMS queue') }}: {{ translate('queued') }}, {{ translate('sent') }}, {{ translate('skipped') }}, {{ translate('failed') }}.
        </p>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-borderless table-thead-bordered card-table w-100 mb-0 munch-sms-queue-table">
                        <thead class="thead-light">
                        <tr>
                            <th class="munch-sms-col--id">ID</th>
                            <th class="munch-sms-col--status">{{ translate('status') }}</th>
                            <th class="munch-sms-col--phone">{{ translate('phone') }}</th>
                            <th class="munch-sms-col--customer">{{ translate('Customer') }}</th>
                            <th class="munch-sms-col--campaign">{{ translate('Campaign') }}</th>
                            <th class="munch-sms-col--message">{{ translate('SMS message') }}</th>
                            <th class="munch-sms-col--skip">{{ translate('skip reason') }}</th>
                            <th class="munch-sms-col--created">{{ translate('created_at') }}</th>
                            <th class="munch-sms-col--sent">{{ translate('sent_at') }}</th>
                            <th class="munch-sms-col--attempt">{{ translate('attempt') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($rows as $r)
                            @php
                                $st = $r->sms_transport_status ?? 'failed';
                                $badge = match($st) {
                                    'sent' => 'success',
                                    'queued' => 'info',
                                    'skipped' => 'secondary',
                                    default => 'danger',
                                };
                                $body = (string) ($r->sms_rendered_body ?? '');
                            @endphp
                            <tr>
                                <td>{{ $r->id }}</td>
                                <td><span class="badge badge-soft-{{ $badge }}">{{ $st }}</span></td>
                                <td>{{ $r->phone }}</td>
                                <td>{{ $r->customer_name ?? optional($r->customer)->f_name }}</td>
                                <td>reorder_reminder</td>
                                <td class="munch-sms-message-cell">
                                    @if($body !== '')
                                        <span title="{{ e($body) }}">{{ $body }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>{{ $r->skip_reason ?? '—' }}</td>
                                <td>{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                                <td>{{ $r->sms_sent_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td>{{ (int) $r->attempt_number }} / {{ (int) $r->sms_attempts }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center py-5 text-muted">{{ translate('no_data_found') }}</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($rows->hasPages())
                <div class="card-footer">{!! $rows->links() !!}</div>
            @endif
        </div>
    </div>
@endsection
