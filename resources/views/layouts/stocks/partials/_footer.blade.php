<div class="footer">
    <div class="row justify-content-between align-items-center">
        <div class="col">
            <p class="font-size-sm mb-0">
                {{ translate('Stocks') }} —
                {{\App\Model\BusinessSetting::where(['key'=>'footer_text'])->first()->value ?? ''}}
            </p>
        </div>
    </div>
</div>
