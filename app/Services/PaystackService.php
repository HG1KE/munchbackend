<?php

namespace App\Services;

use App\Exceptions\PaystackException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaystackService
{
    public function __construct(
        private readonly ?string $publicKey = null,
        private readonly ?string $secretKey = null,
        private readonly string $baseUrl = 'https://api.paystack.co',
        private readonly ?string $merchantEmail = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $baseUrl = $config['paymentUrl']
            ?? $config['payment_url']
            ?? config('paystack.payment_url', 'https://api.paystack.co');

        return new self(
            publicKey: self::stringOrNull($config['publicKey'] ?? $config['public_key'] ?? config('paystack.public_key')),
            secretKey: self::stringOrNull($config['secretKey'] ?? $config['secret_key'] ?? config('paystack.secret_key')),
            baseUrl: rtrim((string) $baseUrl, '/'),
            merchantEmail: self::stringOrNull($config['merchantEmail'] ?? $config['merchant_email'] ?? config('paystack.merchant_email')),
        );
    }

    public function isConfigured(): bool
    {
        return filled($this->secretKey) && filled($this->publicKey);
    }

    public function getPublicKey(): ?string
    {
        return $this->publicKey;
    }

    public function getSecretKey(): ?string
    {
        return $this->secretKey;
    }

    public function getMerchantEmail(): ?string
    {
        return $this->merchantEmail;
    }

    public function generateTransactionReference(string $prefix = 'PSK'): string
    {
        return $prefix . '_' . time() . '_' . Str::lower(Str::random(10));
    }

    /**
     * Initialize a Paystack transaction and return the API response body.
     *
     * @param  array{email: string, amount: int, reference: string, callback_url: string, currency?: string, metadata?: array<string, mixed>}  $params
     * @return array<string, mixed>
     *
     * @throws PaystackException
     */
    public function initializeTransaction(array $params): array
    {
        $this->assertConfigured();

        $payload = [
            'email' => $params['email'],
            'amount' => (int) $params['amount'],
            'reference' => $params['reference'],
            'callback_url' => $params['callback_url'],
            'currency' => $params['currency'] ?? 'NGN',
            'metadata' => $params['metadata'] ?? [],
        ];

        try {
            $response = Http::withToken($this->secretKey)
                ->acceptJson()
                ->timeout(30)
                ->post($this->baseUrl . '/transaction/initialize', $payload);
        } catch (RequestException $exception) {
            Log::error('paystack.initialize_request_failed', [
                'reference' => $params['reference'],
                'message' => $exception->getMessage(),
            ]);
            throw new PaystackException('Unable to reach Paystack. Please try again.', 0, $exception);
        }

        $body = $response->json();

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            Log::warning('paystack.initialize_failed', [
                'reference' => $params['reference'],
                'http_status' => $response->status(),
                'message' => $body['message'] ?? $response->body(),
            ]);
            throw new PaystackException($body['message'] ?? 'Paystack payment initialization failed.');
        }

        return $body;
    }

    /**
     * Build Paystack Inline JS popup payload from initialize API response.
     *
     * @param  array<string, mixed>  $initializeBody  Full Paystack /transaction/initialize response
     * @return array<string, mixed>
     *
     * @throws PaystackException
     */
    public function buildInlineCheckoutPayload(array $initializeBody): array
    {
        $data = $initializeBody['data'] ?? null;
        if (! is_array($data)) {
            throw new PaystackException('Paystack initialize response is missing transaction data.');
        }

        $accessCode = $data['access_code'] ?? null;
        $reference = $data['reference'] ?? null;

        if (! is_string($accessCode) || $accessCode === '' || ! is_string($reference) || $reference === '') {
            Log::warning('paystack.inline_payload_incomplete', [
                'has_access_code' => is_string($accessCode) && $accessCode !== '',
                'has_reference' => is_string($reference) && $reference !== '',
            ]);
            throw new PaystackException('Paystack did not return access_code and reference for inline checkout.');
        }

        return [
            'access_code' => $accessCode,
            'reference' => $reference,
            'authorization_url' => $data['authorization_url'] ?? null,
        ];
    }

    /**
     * Charge a reusable authorization (saved card) — no hosted checkout / SDK.
     *
     * @param  array{
     *   email: string,
     *   amount: int,
     *   authorization_code: string,
     *   reference: string,
     *   currency?: string,
     *   metadata?: array<string, mixed>
     * }  $params
     * @return array<string, mixed>
     *
     * @throws PaystackException
     */
    public function chargeAuthorization(array $params): array
    {
        $this->assertConfigured();

        $authorizationCode = trim((string) ($params['authorization_code'] ?? ''));
        $email = trim((string) ($params['email'] ?? ''));
        $reference = trim((string) ($params['reference'] ?? ''));
        $amount = (int) ($params['amount'] ?? 0);

        if ($authorizationCode === '' || $email === '' || $reference === '' || $amount <= 0) {
            throw new PaystackException('Invalid charge_authorization parameters.');
        }

        $payload = [
            'authorization_code' => $authorizationCode,
            'email' => $email,
            'amount' => $amount,
            'reference' => $reference,
            'currency' => $params['currency'] ?? 'NGN',
            'metadata' => $params['metadata'] ?? [],
        ];

        try {
            $response = Http::withToken($this->secretKey)
                ->acceptJson()
                ->timeout(30)
                ->post($this->baseUrl . '/transaction/charge_authorization', $payload);
        } catch (RequestException $exception) {
            Log::error('paystack.charge_authorization_request_failed', [
                'reference' => $reference,
                'message' => $exception->getMessage(),
            ]);
            throw new PaystackException('Unable to reach Paystack. Please try again.', 0, $exception);
        }

        $body = $response->json();

        if (! is_array($body)) {
            Log::warning('paystack.charge_authorization_invalid_body', [
                'reference' => $reference,
                'http_status' => $response->status(),
            ]);
            throw new PaystackException('Paystack charge authorization failed.');
        }

        // Decline / soft failure — return body for caller to interpret (do not throw
        // for gateway "status:false" so clients get a clean declined response).
        if (! $response->successful()) {
            Log::warning('paystack.charge_authorization_http_failed', [
                'reference' => $reference,
                'http_status' => $response->status(),
                'message' => $body['message'] ?? null,
            ]);
            throw new PaystackException($body['message'] ?? 'Paystack charge authorization failed.');
        }

        return $body;
    }

    /**
     * Verify a transaction by reference (callback / return URL).
     *
     * @return array<string, mixed> Same shape as legacy Paystack::getPaymentData()
     *
     * @throws PaystackException
     */
    public function verifyTransaction(string $reference): array
    {
        $this->assertConfigured();

        $encodedReference = rawurlencode($reference);

        try {
            $response = Http::withToken($this->secretKey)
                ->acceptJson()
                ->timeout(30)
                ->get($this->baseUrl . '/transaction/verify/' . $encodedReference);
        } catch (RequestException $exception) {
            Log::error('paystack.verify_request_failed', [
                'reference' => $reference,
                'message' => $exception->getMessage(),
            ]);
            throw new PaystackException('Unable to verify Paystack payment.', 0, $exception);
        }

        $body = $response->json();

        if (! $response->successful() || ! is_array($body)) {
            Log::warning('paystack.verify_failed', [
                'reference' => $reference,
                'http_status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new PaystackException('Paystack transaction verification failed.');
        }

        return $body;
    }

    /**
     * Non-throwing Paystack status probe for reconciliation / investigation tooling.
     *
     * @return array{
     *   ok: bool,
     *   successful: bool,
     *   gateway_status: ?string,
     *   amount_minor: ?int,
     *   error: ?string,
     *   details: ?array<string, mixed>
     * }
     */
    public function inspectTransaction(string $reference): array
    {
        try {
            $body = $this->verifyTransaction($reference);
            $gatewayStatus = isset($body['data']['status']) ? (string) $body['data']['status'] : null;
            $successful = ($body['status'] ?? false) === true && $gatewayStatus === 'success';

            return [
                'ok' => true,
                'successful' => $successful,
                'gateway_status' => $gatewayStatus,
                'amount_minor' => isset($body['data']['amount']) ? (int) $body['data']['amount'] : null,
                'error' => null,
                'details' => $body,
            ];
        } catch (PaystackException $exception) {
            return [
                'ok' => false,
                'successful' => false,
                'gateway_status' => null,
                'amount_minor' => null,
                'error' => $exception->getMessage(),
                'details' => null,
            ];
        }
    }

    /**
     * @param  mixed  $value
     */
    private static function stringOrNull($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @throws PaystackException
     */
    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new PaystackException('Paystack is not configured. Set public and secret keys in admin payment settings or .env.');
        }
    }

    /**
     * List refunds for a Paystack transaction reference (duplicate-protection lookup).
     *
     * @return array{ok: bool, transient: bool, http_status: int, refunds: list<array<string, mixed>>, error: ?string, body: ?array<string, mixed>}
     */
    public function listRefundsForReference(string $reference): array
    {
        $this->assertConfigured();

        try {
            $response = Http::withToken($this->secretKey)
                ->acceptJson()
                ->timeout(30)
                ->get($this->baseUrl.'/refund', ['reference' => $reference]);
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('paystack.refund_lookup_request_failed', [
                'reference' => $reference,
                'message' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'transient' => true,
                'http_status' => 0,
                'refunds' => [],
                'error' => $exception->getMessage(),
                'body' => null,
            ];
        }

        $body = $response->json();
        $status = $response->status();
        $transient = $this->isTransientHttpStatus($status);

        if (! $response->successful() || ! is_array($body)) {
            return [
                'ok' => false,
                'transient' => $transient,
                'http_status' => $status,
                'refunds' => [],
                'error' => is_array($body) ? (string) ($body['message'] ?? 'lookup_failed') : 'lookup_failed',
                'body' => is_array($body) ? $body : null,
            ];
        }

        $data = $body['data'] ?? [];
        $refunds = [];
        if (isset($data[0]) && is_array($data)) {
            $refunds = $data;
        } elseif (is_array($data) && $data !== [] && ! isset($data[0])) {
            $refunds = [$data];
        }

        return [
            'ok' => true,
            'transient' => false,
            'http_status' => $status,
            'refunds' => $refunds,
            'error' => null,
            'body' => $body,
        ];
    }

    /**
     * Initiate a Paystack refund for a captured transaction reference.
     *
     * @return array{
     *   ok: bool,
     *   processed: bool,
     *   pending: bool,
     *   already_refunded: bool,
     *   transient: bool,
     *   http_status: int,
     *   refund_reference: ?string,
     *   error: ?string,
     *   body: ?array<string, mixed>
     * }
     */
    public function refundTransaction(string $reference, int $amountKobo): array
    {
        $this->assertConfigured();

        $payload = [
            'transaction' => $reference,
            'amount' => $amountKobo,
        ];

        try {
            $response = Http::withToken($this->secretKey)
                ->acceptJson()
                ->timeout(30)
                ->post($this->baseUrl.'/refund', $payload);
        } catch (ConnectionException|RequestException $exception) {
            Log::warning('paystack.refund_request_failed', [
                'reference' => $reference,
                'message' => $exception->getMessage(),
            ]);

            return [
                'ok' => false,
                'processed' => false,
                'pending' => false,
                'already_refunded' => false,
                'transient' => true,
                'http_status' => 0,
                'refund_reference' => null,
                'error' => $exception->getMessage(),
                'body' => null,
            ];
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $status = $response->status();
        $message = strtolower((string) ($body['message'] ?? ''));
        $already = $this->isAlreadyRefundedMessage($message);
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $gatewayStatus = strtolower((string) ($data['status'] ?? ''));
        $processed = $response->successful() && in_array($gatewayStatus, ['processed', 'success'], true);
        $pending = $response->successful() && ! $processed && ($body['status'] ?? false) === true;

        return [
            'ok' => $response->successful() && ($body['status'] ?? false) === true,
            'processed' => $processed,
            'pending' => $pending,
            'already_refunded' => $already,
            'transient' => ! $already && $this->isTransientHttpStatus($status),
            'http_status' => $status,
            'refund_reference' => isset($data['id']) ? (string) $data['id'] : ($data['refund_reference'] ?? null),
            'error' => $response->successful() ? null : (string) ($body['message'] ?? 'refund_failed'),
            'body' => $body,
        ];
    }

    private function isTransientHttpStatus(int $status): bool
    {
        return in_array($status, [0, 429, 500, 502, 503], true);
    }

    private function isAlreadyRefundedMessage(string $message): bool
    {
        return str_contains($message, 'already')
            && (str_contains($message, 'refund') || str_contains($message, 'revers'));
    }
}
