<div id="munch-receipt-root" class="munch-receipt-app">
    <div class="card">
        <div class="card-body">
            <div class="munch-receipt-toolbar">
                @if(!empty($scopeSelect))
                    <div>
                        <label for="receipt-scope">{{ translate('Template scope') }}</label>
                        <select id="receipt-scope" class="custom-select">
                            <option value="">{{ translate('Company default') }}</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" @selected(($payload['branch_id'] ?? null) == $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <div id="receipt-mode-wrap" @if(($payload['scope'] ?? '') !== 'branch') hidden @endif>
                    <label>{{ translate('Branch template') }}</label>
                    <div class="munch-receipt-checks">
                        <label><input type="radio" name="receipt-mode" id="receipt-mode-company" value="company" @checked($payload['use_company'] ?? true)> {{ translate('Use Company Template') }}</label>
                        <label><input type="radio" name="receipt-mode" id="receipt-mode-custom" value="custom" @checked(!($payload['use_company'] ?? true))> {{ translate('Custom Branch Template') }}</label>
                    </div>
                </div>
                <div>
                    <label for="receipt-preview-channel">{{ translate('Preview as') }}</label>
                    <select id="receipt-preview-channel" class="custom-select">
                        <option value="glovo">Glovo</option>
                        <option value="uber">Uber</option>
                        <option value="bolt_food">Bolt Food</option>
                        <option value="delivery">Delivery</option>
                        <option value="takeaway">Take Away</option>
                        <option value="dine_in">Dine In</option>
                        <option value="pos">POS</option>
                    </select>
                </div>
            </div>
            <p id="receipt-lock-hint" class="munch-receipt-hint mt-3 mb-0" hidden></p>
        </div>
    </div>

    <div class="munch-receipt-tabs mt-2">
        <button type="button" class="is-active" data-receipt-tab="customer">{{ translate('Customer Receipt') }}</button>
        <button type="button" data-receipt-tab="kitchen">{{ translate('Kitchen Ticket') }}</button>
        <button type="button" data-receipt-tab="printer">{{ translate('Printer') }}</button>
    </div>

    <div class="munch-receipt-layout mt-3">
        <div>
            <div id="receipt-editor" class="munch-receipt-editor"></div>
            <div class="munch-receipt-actions mb-4">
                <button type="button" class="btn btn-primary" id="receipt-save">{{ translate('Save Template') }}</button>
                <button type="button" class="btn btn-outline-primary" id="receipt-test-print">{{ translate('Print Test Receipt') }}</button>
                <button type="button" class="btn btn-outline-primary" id="receipt-test-kitchen">{{ translate('Print Test Kitchen Ticket') }}</button>
                <button type="button" class="btn btn-outline-secondary" id="receipt-reset-company">{{ translate('Restore Company Default') }}</button>
                <button type="button" class="btn btn-outline-secondary" id="receipt-reset-branch">{{ translate('Restore Branch Default') }}</button>
                <button type="button" class="btn btn-outline-secondary" id="receipt-reset-section">{{ translate('Reset Section') }}</button>
            </div>
        </div>
        <div class="munch-receipt-preview-wrap">
            <div class="card">
                <div class="card-header"><h5 class="mb-0" id="receipt-preview-title">{{ translate('Live 80mm preview') }}</h5></div>
                <div class="card-body">
                    <div class="munch-receipt-paper" id="receipt-paper">
                        <iframe id="receipt-preview-frame" title="Receipt preview"></iframe>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <iframe id="receipt-print-frame" title="Receipt print" hidden></iframe>
</div>
