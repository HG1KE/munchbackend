<?php

namespace App\Services\PalPluss;

use App\Exceptions\PalPlussException;
use App\Models\PalPlussPaymentAttempt;
use App\Models\PaymentRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PalPlussStkInitiator
{
    public function __construct(
        private readonly PalPlussHttpClient $client,
        private readonly PalPlussPhoneNormalizer $phoneNormalizer,
    ) {
    }

    /**
     * @return array{
     *     payment_id: string,
     *     transaction_id: string,
     *     status: string,
     *     phone: string,
     *     amount: float,
     *     checkout_mode: string,
     *     message: string
     * }
     *
     * @throws PalPlussException
     */
    public function initiate(PaymentRequest $paymentRequest, string $rawPhone): array
    {
        if (! $this->client->isEnabled()) {
            throw new PalPlussException(
                'PalPluss M-PESA payments are disabled.',
                'DISABLED',
                403
            );
        }

        if (! $this->client->isConfigured()) {
            throw new PalPlussException(
                'PalPluss is not configured. Set API key and Till channel in Admin Payment Settings.',
                'NOT_CONFIGURED',
                503
            );
        }

        if ((int) $paymentRequest->is_paid === 1) {
            throw new PalPlussException('Payment session is already paid.', 'ALREADY_PAID', 409);
        }

        $phone = $this->phoneNormalizer->normalize($rawPhone);
        if ($phone === null) {
            throw new PalPlussException(
                'Invalid Kenyan phone number. Use 07XXXXXXXX, 01XXXXXXXX, or 254XXXXXXXXX.',
                'INVALID_PHONE',
                422
            );
        }

        $amount = round((float) $paymentRequest->payment_amount, 2);
        if ($amount < 1) {
            throw new PalPlussException('Payment amount must be at least 1 KES.', 'INVALID_AMOUNT', 422);
        }

        $accountReference = $this->accountReferenceFor($paymentRequest);
        $callbackUrl = $this->callbackUrl();
        $channelId = $this->client->channelId();

        $attempt = DB::transaction(function () use ($paymentRequest, $phone, $amount, $accountReference, $channelId) {
            $existing = PalPlussPaymentAttempt::query()
                ->where('payment_request_id', $paymentRequest->id)
                ->whereIn('status', [
                    PalPlussPaymentAttempt::STATUS_PENDING,
                    PalPlussPaymentAttempt::STATUS_PROCESSING,
                    PalPlussPaymentAttempt::STATUS_SUCCESS,
                ])
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->isSuccessful()) {
                throw new PalPlussException('Payment already succeeded for this session.', 'ALREADY_SUCCESS', 409);
            }

            if ($existing !== null && in_array($existing->status, [
                PalPlussPaymentAttempt::STATUS_PENDING,
                PalPlussPaymentAttempt::STATUS_PROCESSING,
            ], true)) {
                return $existing;
            }

            return PalPlussPaymentAttempt::query()->create([
                'payment_request_id' => $paymentRequest->id,
                'account_reference' => $accountReference,
                'phone' => $phone,
                'amount' => $amount,
                'currency' => (string) config('palpluss.currency', 'KES'),
                'channel_id' => $channelId,
                'status' => PalPlussPaymentAttempt::STATUS_INITIATED,
            ]);
        });

        if ($attempt->transaction_id && in_array($attempt->status, [
            PalPlussPaymentAttempt::STATUS_PENDING,
            PalPlussPaymentAttempt::STATUS_PROCESSING,
        ], true)) {
            return $this->responsePayload($paymentRequest, $attempt, 'STK already pending for this payment session.');
        }

        // No credential_id — platform Daraja + Till channelId only.
        $body = [
            'amount' => $amount,
            'phone' => $phone,
            'accountReference' => $accountReference,
            'transactionDesc' => 'Munch order',
            'channelId' => $channelId,
            'callbackUrl' => $callbackUrl,
        ];

        try {
            $data = $this->client->post('/payments/stk', $body);
        } catch (PalPlussException $e) {
            $attempt->status = PalPlussPaymentAttempt::STATUS_FAILED;
            $attempt->last_error = $e->errorCode ?? $e->getMessage();
            $attempt->terminal_at = now();
            $attempt->save();

            throw $e;
        }

        $transactionId = (string) ($data['transactionId'] ?? '');
        if ($transactionId === '') {
            $attempt->status = PalPlussPaymentAttempt::STATUS_FAILED;
            $attempt->last_error = 'missing_transaction_id';
            $attempt->terminal_at = now();
            $attempt->save();

            throw new PalPlussException('PalPluss STK response missing transactionId.', 'INVALID_RESPONSE', 502);
        }

        $attempt->transaction_id = $transactionId;
        $attempt->status = strtolower((string) ($data['status'] ?? 'PENDING')) === 'pending'
            ? PalPlussPaymentAttempt::STATUS_PENDING
            : PalPlussPaymentAttempt::STATUS_PENDING;
        $attempt->provider_request_id = isset($data['providerRequestId']) ? (string) $data['providerRequestId'] : null;
        $attempt->provider_checkout_id = isset($data['providerCheckoutId']) ? (string) $data['providerCheckoutId'] : null;
        $attempt->stk_initiated_at = now();
        $attempt->last_error = null;
        $attempt->save();

        PaymentRequest::query()
            ->where('id', $paymentRequest->id)
            ->update([
                'payment_method' => 'palpluss',
                'transaction_id' => $transactionId,
            ]);

        Log::info('palpluss.stk_initiated', [
            'payment_request_id' => $paymentRequest->id,
            'transaction_id' => $transactionId,
            'channel_id' => $channelId,
            'amount' => $amount,
            // phone intentionally omitted from logs
        ]);

        return $this->responsePayload($paymentRequest, $attempt->fresh() ?? $attempt, 'Check your phone and enter your M-PESA PIN.');
    }

    public function accountReferenceFor(PaymentRequest $paymentRequest): string
    {
        $compact = str_replace('-', '', (string) $paymentRequest->id);

        return substr($compact, 0, 12);
    }

    public function callbackUrl(): string
    {
        $path = (string) config('palpluss.webhook_path', '/api/v1/palpluss/webhook');
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) config('app.url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * @return array{
     *     payment_id: string,
     *     transaction_id: string,
     *     status: string,
     *     phone: string,
     *     amount: float,
     *     checkout_mode: string,
     *     message: string
     * }
     */
    private function responsePayload(PaymentRequest $paymentRequest, PalPlussPaymentAttempt $attempt, string $message): array
    {
        return [
            'checkout_mode' => 'palpluss_stk',
            'payment_id' => (string) $paymentRequest->id,
            'transaction_id' => (string) $attempt->transaction_id,
            'status' => (string) $attempt->status,
            'phone' => (string) $attempt->phone,
            'amount' => (float) $attempt->amount,
            'message' => $message,
        ];
    }
}
