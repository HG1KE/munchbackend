<?php

namespace App\Services\PalPluss;

use App\Exceptions\PalPlussException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal PalPluss REST client (HTTP Basic, API key as username).
 * Credentials come from PalPlussConfigResolver (Admin settings).
 * Never logs the API key or Authorization header.
 */
class PalPlussHttpClient
{
    public function __construct(
        private readonly PalPlussConfigResolver $configResolver,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->configResolver->isEnabledAndConfigured();
    }

    public function isEnabled(): bool
    {
        return (bool) ($this->configResolver->resolve()['enabled'] ?? false);
    }

    public function apiKey(): string
    {
        return (string) ($this->configResolver->resolve()['api_key'] ?? '');
    }

    public function baseUrl(): string
    {
        return rtrim((string) ($this->configResolver->resolve()['base_url'] ?? 'https://api.palpluss.com/v1'), '/');
    }

    public function channelId(): string
    {
        return (string) ($this->configResolver->resolve()['channel_id'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     *
     * @throws PalPlussException
     */
    public function post(string $path, array $body): array
    {
        return $this->request('post', $path, $body, [], $this->requireRuntimeApiKey());
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws PalPlussException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('get', $path, null, $query, $this->requireRuntimeApiKey());
    }

    /**
     * Admin/ops call with an explicit key (e.g. freshly pasted, not yet saved).
     *
     * @return array<string, mixed>
     *
     * @throws PalPlussException
     */
    public function getWithApiKey(string $apiKey, string $path, array $query = []): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            throw new PalPlussException('PalPluss API key is required.', 'NOT_CONFIGURED', 422);
        }

        return $this->request('get', $path, null, $query, $apiKey);
    }

    /**
     * @throws PalPlussException
     */
    private function requireRuntimeApiKey(): string
    {
        $config = $this->configResolver->resolve();
        if (! $config['enabled']) {
            throw new PalPlussException('PalPluss payments are disabled.', 'DISABLED', 403);
        }
        if ($config['api_key'] === '') {
            throw new PalPlussException('PalPluss is not configured.', 'NOT_CONFIGURED', 500);
        }

        return $config['api_key'];
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws PalPlussException
     */
    private function request(string $method, string $path, ?array $body, array $query, string $apiKey): array
    {
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
