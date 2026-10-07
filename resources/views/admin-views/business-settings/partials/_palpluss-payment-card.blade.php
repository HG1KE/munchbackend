@php
    /** @var array<string, mixed> $palplussConfig */
    $palplussConfig = $palplussConfig ?? [];
    $enabled = ! empty($palplussConfig['enabled']);
    $apiKeyConfigured = ! empty($palplussConfig['api_key_configured']);
    $masked = (string) ($palplussConfig['api_key_masked'] ?? '');
    $channelId = (string) ($palplussConfig['channel_id'] ?? '');
    $channelShortcode = (string) ($palplussConfig['channel_shortcode'] ?? '');
    $channelType = (string) ($palplussConfig['channel_type'] ?? '');
    $channelName = (string) ($palplussConfig['channel_name'] ?? '');
    $gatewayTitle = (string) ($palplussConfig['gateway_title'] ?? 'M-PESA (PalPluss)');
    $mode = (string) ($palplussConfig['mode'] ?? 'live');
@endphp

<div class="col-md-6 mb-5">
    <div class="card" id="palpluss-payment-card">
        <form action="{{ config('app.mode') != 'demo' ? route('admin.business-settings.web-app.palpluss-update') : 'javascript:' }}"
              method="POST" id="palpluss-form">
            @csrf
            <div class="card-header d-flex flex-wrap align-content-around">
                <h5>
                    <span class="text-uppercase">M-PESA (PalPluss)</span>
                </h5>
                <label class="switch--custom-label toggle-switch toggle-switch-sm d-inline-flex">
                    <span class="mr-2 switch--custom-label-text text-primary on text-uppercase">on</span>
                    <span class="mr-2 switch--custom-label-text off text-uppercase">off</span>
                    <input type="checkbox" name="status" value="1"
                           class="toggle-switch-input" {{ $enabled ? 'checked' : '' }}>
                    <span class="toggle-switch-label text">
                        <span class="toggle-switch-indicator"></span>
                    </span>
                </label>
            </div>

            <div class="card-body">
                <p class="text-muted small mb-3">
                    {{ translate('Collect M-PESA via PalPluss STK Push into your Till channel. API key is stored encrypted. Leave the API key blank to keep the existing value.') }}
                </p>

                <div class="form-floating mb-3">
                    <select class="js-select form-control theme-input-style w-100" name="mode">
                        <option value="live" {{ $mode == 'live' ? 'selected' : '' }}>{{ translate('live') }}</option>
                        <option value="test" {{ $mode == 'test' ? 'selected' : '' }}>{{ translate('test') }}</option>
                    </select>
                </div>

                <div class="form-floating mb-3">
                    <label class="form-label">{{ translate('API Key') }}</label>
                    <input type="password"
                           class="form-control"
                           name="api_key"
                           id="palpluss-api-key"
                           autocomplete="new-password"
                           placeholder="{{ $apiKeyConfigured ? translate('Leave blank to keep existing key') : translate('Paste PalPluss API key') }}"
                           value="">
                    @if($apiKeyConfigured)
                        <small class="text-muted d-block mt-1">{{ translate('Current key') }}: {{ $masked }}</small>
                    @endif
                </div>

                <div class="form-floating mb-3">
                    <label class="form-label">{{ translate('payment_gateway_title') }}</label>
                    <input type="text" class="form-control" name="gateway_title"
                           value="{{ $gatewayTitle }}">
                </div>

                <div class="mb-3">
                    <label class="form-label">{{ translate('Till channel') }}</label>
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" id="palpluss-load-channels">
                            {{ translate('Load channels') }}
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="palpluss-verify">
                            {{ translate('Test connection') }}
                        </button>
                    </div>
                    <select class="form-control" name="channel_id" id="palpluss-channel-select">
                        <option value="">{{ translate('Select Till channel') }}</option>
                        @if($channelId !== '')
                            <option value="{{ $channelId }}" selected>
                                {{ $channelShortcode !== '' ? $channelShortcode.' — ' : '' }}{{ $channelName !== '' ? $channelName.' — ' : '' }}{{ $channelType }} ({{ $channelId }})
                            </option>
                        @endif
                    </select>
                    <input type="hidden" name="channel_shortcode" id="palpluss-channel-shortcode" value="{{ $channelShortcode }}">
                    <input type="hidden" name="channel_type" id="palpluss-channel-type" value="{{ $channelType }}">
                    <input type="hidden" name="channel_name" id="palpluss-channel-name" value="{{ $channelName }}">
                    <small class="text-muted d-block mt-1" id="palpluss-channel-help">
                        {{ translate('Only Till / TILL_NUMBER channels can be selected.') }}
                    </small>
                </div>

                <div class="alert alert-soft-info d-none" id="palpluss-verify-result"></div>

                <div class="text-right mt-4">
                    <button type="{{ config('app.mode') != 'demo' ? 'submit' : 'button' }}"
                            class="btn btn-primary px-5 call-demo">{{ translate('save') }}</button>
                </div>
            </div>
        </form>
    </div>
</div>

@push('script_2')
<script>
(function () {
    const listUrl = @json(route('admin.business-settings.web-app.palpluss-list-channels'));
    const verifyUrl = @json(route('admin.business-settings.web-app.palpluss-verify'));
    const csrf = @json(csrf_token());

    function selectedMeta($opt) {
        return {
            shortcode: $opt.data('shortcode') || '',
            type: $opt.data('type') || '',
            name: $opt.data('name') || ''
        };
    }

    $('#palpluss-channel-select').on('change', function () {
        const $opt = $(this).find('option:selected');
        const meta = selectedMeta($opt);
        $('#palpluss-channel-shortcode').val(meta.shortcode);
        $('#palpluss-channel-type').val(meta.type);
        $('#palpluss-channel-name').val(meta.name);
    });

    $('#palpluss-load-channels').on('click', function () {
        const $btn = $(this);
        $btn.prop('disabled', true).text(@json(translate('Loading...')));
        const apiKey = ($('#palpluss-api-key').val() || '').trim();

        $.ajax({
            url: listUrl,
            method: 'POST',
            data: {_token: csrf, api_key: apiKey},
            success: function (res) {
                if (!res.success) {
                    alert(res.message || 'Failed to load channels');
                    return;
                }
                const $select = $('#palpluss-channel-select');
                const current = $select.val();
                $select.empty().append($('<option>', {value: '', text: @json(translate('Select Till channel'))}));
                (res.channels || []).forEach(function (ch) {
                    if (!ch.till_like) {
                        return;
                    }
                    const label = (ch.shortcode ? ch.shortcode + ' — ' : '') +
                        (ch.name ? ch.name + ' — ' : '') +
                        ch.type + (ch.isDefault ? ' (default)' : '');
                    $select.append(
                        $('<option>', {value: ch.id, text: label})
                            .attr('data-shortcode', ch.shortcode || '')
                            .attr('data-type', ch.type || '')
                            .attr('data-name', ch.name || '')
                    );
                });
                if (current) {
                    $select.val(current).trigger('change');
                }
                if ($select.find('option').length <= 1) {
                    alert(@json(translate('No Till channels found on this PalPluss account.')));
                }
            },
            error: function (xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Failed to load channels';
                alert(msg);
            },
            complete: function () {
                $btn.prop('disabled', false).text(@json(translate('Load channels')));
            }
        });
    });

    $('#palpluss-verify').on('click', function () {
        const $btn = $(this);
        const $box = $('#palpluss-verify-result');
        $btn.prop('disabled', true);
        $box.removeClass('d-none alert-soft-success alert-soft-danger').addClass('alert-soft-info')
            .text(@json(translate('Verifying...')));

        $.ajax({
            url: verifyUrl,
            method: 'POST',
            data: {_token: csrf},
            success: function (res) {
                const r = res.result || {};
                if (res.success) {
                    $box.removeClass('alert-soft-info alert-soft-danger').addClass('alert-soft-success');
                    $box.html(
                        '<strong>' + (r.message || 'Connected successfully') + '</strong><br>' +
                        'Till: ' + (r.channel_shortcode || '—') + '<br>' +
                        'Channel: ' + (r.channel_type || '—') + '<br>' +
                        'Wallet: ' + (r.wallet_available != null ? (r.wallet_currency || 'KES') + ' ' + r.wallet_available : '—') + '<br>' +
                        'Verified: ' + (r.verified_at || '')
                    );
                    if (r.channel_id) {
                        $('#palpluss-channel-shortcode').val(r.channel_shortcode || '');
                        $('#palpluss-channel-type').val(r.channel_type || '');
                        $('#palpluss-channel-name').val(r.channel_name || '');
                    }
                } else {
                    $box.removeClass('alert-soft-info alert-soft-success').addClass('alert-soft-danger')
                        .text((r.message || res.message || 'Verification failed'));
                }
            },
            error: function (xhr) {
                const msg = (xhr.responseJSON && xhr.responseJSON.message) ? xhr.responseJSON.message : 'Verification failed';
                $box.removeClass('alert-soft-info alert-soft-success').addClass('alert-soft-danger').text(msg);
            },
            complete: function () {
                $btn.prop('disabled', false);
            }
        });
    });
})();
</script>
@endpush
