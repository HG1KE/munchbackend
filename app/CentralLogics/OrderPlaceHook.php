<?php

use App\Models\PaymentRequest;
use App\Services\Paystack\PaystackOrderProtectionService;

if (! function_exists('order_place')) {
    /**
     * Digital gateway success hook — place order from the payment session draft.
     *
     * @param  PaymentRequest|array<string, mixed>  $data
     */
    function order_place($data): void
    {
        $paymentRequest = $data instanceof PaymentRequest
            ? $data
            : PaymentRequest::query()->find($data['id'] ?? null);

        if ($paymentRequest === null) {
            return;
        }

        if ((string) $paymentRequest->payment_method !== 'paystack') {
            return;
        }

        app(PaystackOrderProtectionService::class)->completeAfterVerify($paymentRequest);
    }
}

if (! function_exists('order_cancel')) {
    /**
     * @param  PaymentRequest|array<string, mixed>  $data
     */
    function order_cancel($data): void
    {
        $paymentRequest = $data instanceof PaymentRequest
            ? $data
            : PaymentRequest::query()->find($data['id'] ?? null);

        if ($paymentRequest === null) {
            return;
        }

        if (! \Illuminate\Support\Facades\Schema::hasColumn('payment_requests', 'placement_status')) {
            return;
        }

        PaymentRequest::query()
            ->where('id', $paymentRequest->id)
            ->whereNull('placed_order_id')
            ->update([
                'placement_status' => PaymentRequest::PLACEMENT_CANCELLED,
            ]);
    }
}
