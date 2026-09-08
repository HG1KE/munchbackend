@extends('layouts.admin.app')

@section('title', translate('Loyalty Delivery SMS Activity'))

@push('css_or_js')
    <style>
        .munch-sms-queue-table { table-layout: fixed; width: 100%; }
        .munch-sms-queue-table th, .munch-sms-queue-table td {
            white-space: normal; word-break: break-word; overflow-wrap: anywhere;
            line-height: 1.45; vertical-align: top;
        }
        .munch-sms-queue-table .munch-sms-col--id { width: 4%; }
        .munch-sms-queue-table .munch-sms-col--status { width: 7%; }
        .munch-sms-queue-table .munch-sms-col--order { width: 6%; }
        .munch-sms-queue-table .munch-sms-col--phone { width: 9%; }
        .munch-sms-queue-table .munch-sms-col--customer { width: 9%; }
        .munch-sms-queue-table .munch-sms-col--points { width: 8%; }
        .munch-sms-queue-table .munch-sms-col--message { width: 22%; }
        .munch-sms-queue-table .munch-sms-col--skip { width: 9%; }
        .munch-sms-queue-table .munch-sms-col--created { width: 8%; }
        .munch-sms-queue-table .munch-sms-col--sent { width: 8%; }
    </style>
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-4">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <span class="page-header-title">{{ translate('Marketing') }} — {{ translate('Loyalty Delivery SMS Activity') }}</span>
            </h2>
        </div>

        @include('admin-views.marketing.partials.subnav')

        <p class="text-muted small mb-3">
            {{ translate('Transactional SMS after loyalty points are credited on delivered orders') }}.
        </p>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-borderless table-thead-bordered card-table w-100 mb-0 munch-sms-queue-table">
                        <thead class="thead-light">
                        <tr>
                            <th class="munch-sms-col--id">ID</th>
                            <th class="munch-sms-col--status">{{ translate('status') }}</th>
                            <th class="munch-sms-col--order">{{ translate('Order') }}</th>
                            <th class="munch-sms-col--phone">{{ translate('phone') }}</th>
                            <th class="munch-sms-col--customer">{{ translate('Customer') }}</th>
                            <th class="munch-sms-col--points">{{ translate('Points') }}</th>
                            <th class="munch-sms-col--message">{{ translate('SMS message') }}</th>
                            <th class="munch-sms-col--skip">{{ translate('skip reason') }}</th>
                            <th class="munch-sms-col--created">{{ translate('created_at') }}</th>
                            <th class="munch-sms-col--sent">{{ translate('sent_at') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse($rows as $r)
                            @php
                                $badge = match($r->status) {
                                    'sent' => 'success',
                                    'skipped' => 'secondary',
                                    'pending' => 'info',
                                    default => 'danger',
                                };
                            @endphp
                            <tr>
                                <td>{{ $r->id }}</td>
                                <td><span class="badge badge-soft-{{ $badge }}">{{ $r->status }}</span></td>
                                <td>#{{ $r->order_id }}</td>
                                <td>{{ $r->phone }}</td>
                                <td>{{ $r->customer_name ?? optional($r->customer)->f_name }}</td>
                                <td>+{{ $r->earned_points }} / {{ $r->points_balance }}</td>
                                <td class="munch-sms-message-cell">
                                    @if(!empty($r->message_body))
                                        <span title="{{ e($r->message_body) }}">{{ $r->message_body }}</span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td>{{ $r->skip_reason ?? ($r->last_error ?? '—') }}</td>
                                <td>{{ $r->created_at?->format('Y-m-d H:i') }}</td>
                                <td>{{ $r->sms_sent_at?->format('Y-m-d H:i') ?? '—' }}</td>
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
