@extends('layouts.admin.app')

@section('title', translate('SMS Queue / Activity'))

@push('css_or_js')
    <style>
        .munch-sms-queue-table {
            table-layout: fixed;
            width: 100%;
        }

        .munch-sms-queue-table th,
        .munch-sms-queue-table td {
            white-space: normal;
            word-break: break-word;
            overflow-wrap: anywhere;
            line-height: 1.45;
            vertical-align: top;
        }

        .munch-sms-queue-table .munch-sms-col--id { width: 4%; }
        .munch-sms-queue-table .munch-sms-col--status { width: 7%; }
        .munch-sms-queue-table .munch-sms-col--phone { width: 10%; }
        .munch-sms-queue-table .munch-sms-col--campaign { width: 11%; }
        .munch-sms-queue-table .munch-sms-col--message { width: 22%; }
        .munch-sms-queue-table .munch-sms-col--created { width: 9%; }
        .munch-sms-queue-table .munch-sms-col--queued { width: 9%; }
        .munch-sms-queue-table .munch-sms-col--sent { width: 9%; }
        .munch-sms-queue-table .munch-sms-col--delay { width: 6%; }
        .munch-sms-queue-table .munch-sms-col--provider { width: 10%; }
        .munch-sms-queue-table .munch-sms-col--retries { width: 4%; }

        .munch-sms-queue-table .munch-sms-message-cell {
            max-width: 0;
        }
    </style>
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <span class="page-header-title">{{ translate('Marketing') }} — {{ translate('Abandoned Checkout Activity') }}</span>
            </h2>
        </div>

        @include('admin-views.marketing.partials.subnav')

        <p class="text-muted small mb-3">
            {{ translate('Abandoned checkout recovery SMS only') }} ({{ translate('sent') }} / {{ translate('failed') }}).
            {{ translate('Checkout rows with no SMS activity are hidden') }}.
            {{ translate('Message body is rendered from the current template and row data') }}.
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
                            <th class="munch-sms-col--campaign">{{ translate('Campaign') }}</th>
                            <th class="munch-sms-col--message">{{ translate('SMS message') }}</th>
                            <th class="munch-sms-col--created">{{ translate('created_at') }}</th>
                            <th class="munch-sms-col--queued">{{ translate('queued_at') }}</th>
                            <th class="munch-sms-col--sent">{{ translate('sent_at') }}</th>
                            <th class="munch-sms-col--delay">{{ translate('delay') }} (m)</th>
                            <th class="munch-sms-col--provider">{{ translate('provider') }}</th>
                            <th class="munch-sms-col--retries">{{ translate('retries') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($rows as $r)
                            @php
                                $st = $r->sms_transport_status ?? 'failed';
                                $badge = match ($st) {
                                    'sent' => 'success',
                                    'queued' => 'info',
                                    default => 'danger',
                                };
                                $provider = trim((string) ($r->last_provider_status ?? '').' '.(string) ($r->last_error ?? '').' '.(string) ($r->last_skip_reason ?? ''));
                                $body = (string) ($r->sms_rendered_body ?? '');
                            @endphp
                            <tr>
                                <td class="munch-sms-col--id">{{ $r->id }}</td>
                                <td class="munch-sms-col--status"><span class="badge badge-soft-{{ $badge }}">{{ $st }}</span></td>
                                <td class="munch-sms-col--phone">{{ $r->phone }}</td>
                                <td class="munch-sms-col--campaign">abandoned_checkout</td>
                                <td class="munch-sms-col--message munch-sms-message-cell">
                                    @if($body !== '')
                                        <span title="{{ e($body) }}">{{ $body }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="munch-sms-col--created">{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                                <td class="munch-sms-col--queued">{{ $r->sms_queued_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="munch-sms-col--sent">{{ $r->sms_sent_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td class="munch-sms-col--delay">{{ $r->sms_delay_from_created ?? '—' }}</td>
                                <td class="munch-sms-col--provider">{{ $provider !== '' ? $provider : '—' }}</td>
                                <td class="munch-sms-col--retries">{{ (int) $r->sms_attempts }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="11" class="text-center py-5 text-muted">{{ translate('no_data_found') }}</td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            @if($rows->hasPages())
                <div class="card-footer">
                    {!! $rows->links() !!}
                </div>
            @endif
        </div>
    </div>
@endsection
