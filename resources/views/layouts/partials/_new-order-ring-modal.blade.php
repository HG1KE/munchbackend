{{-- New-order ringing modal. $ctaMode: admin|branch — presentation only; same check_order hooks. --}}
<style>
    /* Critical chrome — travels with HTML so long-cached theme CSS cannot hide it. */
    #popup-modal.meatco-new-order-modal-root .modal-content.meatco-new-order-modal {
        border: 2px solid #E7032D !important;
        border-radius: 20px !important;
        overflow: visible !important;
    }
</style>
<div class="modal fade meatco-new-order-modal-root" id="popup-modal" tabindex="-1" role="dialog" aria-labelledby="popup-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content meatco-new-order-modal">
            <div class="modal-body meatco-new-order-modal__body">
                <div class="meatco-new-order-modal__icon-stage" aria-hidden="true">
                    <span class="meatco-new-order-modal__pulse"></span>
                    <span class="meatco-new-order-modal__pulse meatco-new-order-modal__pulse--delayed"></span>
                    <div class="meatco-new-order-modal__icon">
                        <i class="tio-shopping-cart-outlined"></i>
                    </div>
                </div>
                <h2 id="popup-modal-title" class="meatco-new-order-modal__title">
                    {{ translate('You have new order, Check Please.') }}
                </h2>
                @if(($ctaMode ?? 'admin') === 'branch')
                    <button type="button" class="btn btn-primary meatco-new-order-modal__btn check-order">
                        {{ translate('Ok, let me check') }}
                    </button>
                @else
                    <button type="button" onclick="check_order()" class="btn btn-primary meatco-new-order-modal__btn">
                        {{ translate('Ok, let me check') }}
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
