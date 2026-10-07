<?php

namespace App\Services\PalPluss;

use App\Exceptions\PalPlussException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal PalPluss REST client (HTTP Basic, API key as username).
 * Never logs the API key or Authorization header.
 */
class PalPlussHttpClient
{
    public function isConfigured(): bool
    {
        return $this->apiKey() !== '' && $this->baseUrl() !== '' && $this->channelId() !== '';
    }

    public function apiKey(): string
    {
        return trim((string) config('palpluss.api_key', ''));
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('palpluss.base_url', 'https://api.palpluss.com/v1'), '/');
    }

    public function channelId(): string
    {
        return trim((string) config('palpluss.channel_id', ''));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws PalPlussException
     */
    public function post(string $path, array $body): array
    {
        return $this->request('post', $path, $body);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws PalPlussException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('get', $path, null, $query);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws PalPlussException
     */
    private function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            throw new PalPlussException('PalPluss is not configured.', 'NOT_CONFIGURED', 500);
        }

        $url = $this->baseUrl().'/'.ltrim($path, '/');
        $timeout = max(5, (int) config('palpluss.timeout_seconds', 30));

        $pending = Http::withBasicAuth($apiKey, '')
            ->acceptJson()
            ->asJson()
            ->timeout($timeout);

        /** @var Response $response */
        $response = $method === 'get'
            ? $pending->get($url, $query)
            : $pending->post($url, $body ?? []);

        $json = $response->json();
        if (! is_array($json)) {
            Log::warning('palpluss.http_non_json', [
                'path' => $path,
                'http_status' => $response->status(),
            ]);

            throw new PalPlussException(
                'PalPluss returned a non-JSON response.',
                'INVALID_RESPONSE',
                $response->status()
            );
        }

        $requestId = is_string($json['requestId'] ?? null) ? $json['requestId'] : null;

        if (! $response->successful() || ($json['success'] ?? false) !== true) {
            $error = is_array($json['error'] ?? null) ? $json['error'] : [];
            $code = is_string($error['code'] ?? null) ? $error['code'] : 'PALPLUSS_ERROR';
            $message = is_string($error['message'] ?? null)
                ? $error['message']
                : 'PalPluss request failed.';

            Log::warning('palpluss.http_error', [
                'path' => $path,
                'http_status' => $response->status(),
                'error_code' => $code,
                'request_id' => $requestId,
            ]);

            throw new PalPlussException($message, $code, $response->status(), $requestId);
        }

        $data = $json['data'] ?? null;
        if (! is_array($data)) {
            throw new PalPlussException(
                'PalPluss response missing data envelope.',
                'INVALID_RESPONSE',
                $response->status(),
                $requestId
            );
        }

        return $data;
    }
}
