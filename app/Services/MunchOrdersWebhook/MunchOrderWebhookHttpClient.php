<?php

namespace App\Services\MunchOrdersWebhook;

use App\Models\MunchOrderWebhookOutbox;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MunchOrderWebhookHttpClient
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, retryable: bool, http_status: ?int, error: ?string}
     */
    public function post(MunchOrderWebhookOutbox $outbox, array $payload): array
    {
        $url = (string) config('munch_orders_webhook.url');
        if ($url === '') {
            return [
                'success' => false,
                'retryable' => true,
                'http_status' => null,
                'error' => 'webhook_url_not_configured',
            ];
        }

        $authorization = trim((string) config('munch_orders_webhook.authorization'));
        if ($authorization === '') {
            return [
                'success' => false,
                'retryable' => true,
                'http_status' => null,
                'error' => 'webhook_auth_not_configured',
            ];
        }

        $timeout = (int) config('munch_orders_webhook.timeout_seconds', 15);
        $connectTimeout = (int) config('munch_orders_webhook.connect_timeout_seconds', 5);

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Idempotency-Key' => $outbox->event_key,
        ];

        $headers['Authorization'] = $authorization;

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->withHeaders($headers)
                ->post($url, $payload);

            $status = $response->status();

            if ($response->successful()) {
                return [
                    'success' => true,
                    'retryable' => false,
                    'http_status' => $status,
                    'error' => null,
                ];
            }

            $retryable = $this->isRetryableHttpStatus($status);

            return [
                'success' => false,
                'retryable' => $retryable,
                'http_status' => $status,
                'error' => 'http_'.$status,
            ];
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'retryable' => true,
                'http_status' => null,
                'error' => 'connection_error',
            ];
        } catch (RequestException $e) {
            $status = $e->response?->status();
            $retryable = $status === null || $this->isRetryableHttpStatus($status);

            return [
                'success' => false,
                'retryable' => $retryable,
                'http_status' => $status,
                'error' => $status !== null ? 'http_'.$status : 'request_error',
            ];
        }
    }

    public function isRetryableHttpStatus(int $status): bool
    {
        if ($status === 408 || $status === 429) {
            return true;
        }

        if ($status >= 500) {
            return true;
        }

        return false;
    }

    /**
     * @param  array{success: bool, retryable: bool, http_status: ?int, error: ?string}  $result
     */
    public function logAttempt(MunchOrderWebhookOutbox $outbox, array $result, int $attemptNumber): void
    {
        $context = [
            'order_id' => $outbox->order_id,
            'branch' => $outbox->payload['branch'] ?? null,
            'event_key' => $outbox->event_key,
            'attempt' => $attemptNumber,
            'http_status' => $result['http_status'],
            'success' => $result['success'],
            'retryable' => $result['retryable'],
            'error' => $result['error'],
        ];

        if ($result['success']) {
            Log::info('munch_orders_webhook.delivered', $context);
        } elseif ($result['retryable']) {
            Log::warning('munch_orders_webhook.delivery_retryable', $context);
        } else {
            Log::error('munch_orders_webhook.delivery_failed_permanent', $context);
        }
    }
}
