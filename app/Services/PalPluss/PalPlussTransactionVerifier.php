<?php

namespace App\Services\PalPluss;

use App\Exceptions\PalPlussException;
use App\Models\PalPlussPaymentAttempt;
use App\Models\PaymentRequest;

class PalPlussTransactionVerifier
{
    public function __construct(
        private readonly PalPlussHttpClient $client,
    ) {
    }

    /**
     * Fetch and normalize transaction from PalPluss (snake_case GET response).
     *
     * @return array{
     *     transaction_id: string,
     *     status: string,
     *     amount: float,
     *     currency: string,
     *     phone_number: ?string,
     *     external_reference: ?string,
     *     mpesa_receipt: ?string,
     *     result_code: ?string,
     *     result_desc: ?string,
     *     provider_request_id: ?string,
     *     provider_checkout_id: ?string,
     *     raw: array<string, mixed>
     * }
     *
     * @throws PalPlussException
     */
    public function fetch(string $transactionId): array
    {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            throw new PalPlussException('Transaction ID is required.', 'INVALID_UUID', 422);
        }

        $raw = $this->client->get('/transactions/'.$transactionId);

        $status = strtoupper((string) ($raw['status'] ?? ''));
        $amount = (float) ($raw['amount'] ?? 0);
        $currency = strtoupper((string) ($raw['currency'] ?? 'KES'));

        return [
            'transaction_id' => (string) ($raw['transaction_id'] ?? $transactionId),
            'status' => $status,
            'amount' => $amount,
            'currency' => $currency,
            'phone_number' => isset($raw['phone_number']) ? (string) $raw['phone_number'] : null,
            'external_reference' => isset($raw['external_reference']) ? (string) $raw['external_reference'] : null,
            'mpesa_receipt' => isset($raw['mpesa_receipt']) ? (string) $raw['mpesa_receipt'] : null,
            'result_code' => isset($raw['result_code']) ? (string) $raw['result_code'] : null,
            'result_desc' => isset($raw['result_desc']) ? (string) $raw['result_desc'] : null,
            'provider_request_id' => isset($raw['provider_request_id']) ? (string) $raw['provider_request_id'] : null,
            'provider_checkout_id' => isset($raw['provider_checkout_id']) ? (string) $raw['provider_checkout_id'] : null,
            'raw' => $raw,
        ];
    }

    /**
     * @param  array<string, mixed>  $verified
     *
     * @throws PalPlussException
     */
    public function assertSuccessfulForPayment(PaymentRequest $paymentRequest, PalPlussPaymentAttempt $attempt, array $verified): void
    {
        if (($verified['status'] ?? '') !== 'SUCCESS') {
            throw new PalPlussException(
                'PalPluss transaction is not SUCCESS (status='.($verified['status'] ?? '').').',
                'NOT_SUCCESS',
                402
            );
        }

        if (($verified['transaction_id'] ?? '') !== (string) $attempt->transaction_id) {
            throw new PalPlussException('Transaction ID mismatch.', 'TX_MISMATCH', 422);
        }

        $expectedRef = (string) $attempt->account_reference;
        $external = (string) ($verified['external_reference'] ?? '');
        if ($external !== '' && $expectedRef !== '' && $external !== $expectedRef) {
            throw new PalPlussException(
                'Account reference mismatch.',
                'REFERENCE_MISMATCH',
                422
            );
        }

        $currency = (string) ($verified['currency'] ?? 'KES');
        if ($currency !== '' && $currency !== (string) config('palpluss.currency', 'KES')) {
            throw new PalPlussException('Currency mismatch.', 'CURRENCY_MISMATCH', 422);
        }

        $tolerance = (float) config('palpluss.amount_tolerance_major', 1.0);
        $expected = (float) $paymentRequest->payment_amount;
        $actual = (float) ($verified['amount'] ?? 0);
        if (abs($expected - $actual) > $tolerance) {
            throw new PalPlussException(
                sprintf('Amount mismatch. Expected %.2f, received %.2f.', $expected, $actual),
                'AMOUNT_MISMATCH',
                402
            );
        }
    }

    public function mapApiStatusToAttemptStatus(string $apiStatus): string
    {
        return match (strtoupper($apiStatus)) {
            'SUCCESS' => PalPlussPaymentAttempt::STATUS_SUCCESS,
            'FAILED' => PalPlussPaymentAttempt::STATUS_FAILED,
            'CANCELLED' => PalPlussPaymentAttempt::STATUS_CANCELLED,
            'EXPIRED' => PalPlussPaymentAttempt::STATUS_EXPIRED,
            'REVERSED' => PalPlussPaymentAttempt::STATUS_REVERSED,
            'PROCESSING' => PalPlussPaymentAttempt::STATUS_PROCESSING,
            'PENDING' => PalPlussPaymentAttempt::STATUS_PENDING,
            default => PalPlussPaymentAttempt::STATUS_PENDING,
        };
    }
}
