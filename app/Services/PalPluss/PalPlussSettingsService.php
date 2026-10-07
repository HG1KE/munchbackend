<?php

namespace App\Services\PalPluss;

use App\Exceptions\PalPlussException;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class PalPlussSettingsService
{
    public function __construct(
        private readonly PalPlussConfigResolver $resolver,
        private readonly PalPlussHttpClient $client,
    ) {
    }

    /**
     * @param  array{
     *     status?: int|bool,
     *     mode?: string,
     *     api_key?: string|null,
     *     channel_id?: string|null,
     *     channel_shortcode?: string|null,
     *     channel_type?: string|null,
     *     channel_name?: string|null,
     *     gateway_title?: string|null
     * }  $input
     */
    public function update(array $input): Setting
    {
        $existing = Setting::query()
            ->where('key_name', PalPlussConfigResolver::GATEWAY_KEY)
            ->where('settings_type', PalPlussConfigResolver::SETTINGS_TYPE)
            ->first();

        $currentValues = [];
        if ($existing !== null) {
            $raw = $existing->live_values;
            $currentValues = is_array($raw) ? $raw : [];
        }

        $status = ! empty($input['status']) ? 1 : 0;
        $modeInput = (string) ($input['mode'] ?? 'live');
        $mode = in_array($modeInput, ['live', 'test'], true) ? $modeInput : 'live';

        $apiKeyEncrypted = (string) ($currentValues['api_key_encrypted'] ?? '');
        $newKey = isset($input['api_key']) ? trim((string) $input['api_key']) : '';
        if ($newKey !== '') {
            $apiKeyEncrypted = $this->resolver->encryptApiKey($newKey);
        }

        if ($status === 1 && $apiKeyEncrypted === '') {
            throw new PalPlussException(
                'API key is required to enable PalPluss.',
                'API_KEY_REQUIRED',
                422
            );
        }

        $channelId = array_key_exists('channel_id', $input)
            ? trim((string) ($input['channel_id'] ?? ''))
            : (string) ($currentValues['channel_id'] ?? '');

        $channelType = array_key_exists('channel_type', $input)
            ? trim((string) ($input['channel_type'] ?? ''))
            : (string) ($currentValues['channel_type'] ?? '');

        if ($channelId !== '' && $channelType !== '' && ! PalPlussConfigResolver::isTillChannelType($channelType)) {
            throw new PalPlussException(
                'Selected channel must be a Till / TILL_NUMBER channel.',
                'INVALID_CHANNEL_TYPE',
                422
            );
        }

        if ($status === 1 && $channelId === '') {
            throw new PalPlussException(
                'Select a PalPluss Till channel before enabling.',
                'CHANNEL_REQUIRED',
                422
            );
        }

        $values = [
            'gateway' => PalPlussConfigResolver::GATEWAY_KEY,
            'mode' => $mode,
            'status' => $status,
            'api_key_encrypted' => $apiKeyEncrypted,
            // Never store plaintext api_key
            'channel_id' => $channelId,
            'channel_shortcode' => array_key_exists('channel_shortcode', $input)
                ? trim((string) ($input['channel_shortcode'] ?? ''))
                : (string) ($currentValues['channel_shortcode'] ?? ''),
            'channel_type' => $channelType,
            'channel_name' => array_key_exists('channel_name', $input)
                ? trim((string) ($input['channel_name'] ?? ''))
                : (string) ($currentValues['channel_name'] ?? ''),
        ];

        $title = trim((string) ($input['gateway_title'] ?? ''));
        if ($title === '') {
            $title = 'M-PESA (PalPluss)';
        }

        $additional = ['gateway_title' => $title, 'gateway_image' => ''];
        if ($existing !== null && $existing->additional_data) {
            $prev = is_string($existing->additional_data)
                ? json_decode($existing->additional_data, true)
                : (array) $existing->additional_data;
            if (is_array($prev)) {
                $additional['gateway_image'] = (string) ($prev['gateway_image'] ?? '');
            }
        }

        Log::info('palpluss.settings_updated', [
            'enabled' => $status === 1,
            'channel_id_set' => $channelId !== '',
            'api_key_updated' => $newKey !== '',
            // never log key
        ]);

        return Setting::updateOrCreate(
            [
                'key_name' => PalPlussConfigResolver::GATEWAY_KEY,
                'settings_type' => PalPlussConfigResolver::SETTINGS_TYPE,
            ],
            [
                'live_values' => $values,
                'test_values' => $values,
                'mode' => $mode,
                'is_active' => $status,
                'additional_data' => json_encode($additional),
            ]
        );
    }

    /**
     * @return list<array{id: string, type: string, shortcode: string, name: string, isDefault: bool, till_like: bool}>
     *
     * @throws PalPlussException
     */
    public function listChannels(?string $apiKeyOverride = null): array
    {
        $apiKey = $apiKeyOverride !== null && trim($apiKeyOverride) !== ''
            ? trim($apiKeyOverride)
            : $this->resolver->resolve()['api_key'];

        if ($apiKey === '') {
            throw new PalPlussException('PalPluss API key is not configured.', 'NOT_CONFIGURED', 422);
        }

        $data = $this->client->getWithApiKey($apiKey, '/payment-wallet/channels');
        $items = $data['items'] ?? null;
        if (! is_array($items)) {
            // Some responses may return the list at top level of data
            $items = array_is_list($data) ? $data : [];
        }

        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = (string) ($item['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $type = (string) ($item['type'] ?? '');
            $out[] = [
                'id' => $id,
                'type' => $type,
                'shortcode' => (string) ($item['shortcode'] ?? ''),
                'name' => (string) ($item['name'] ?? ''),
                'isDefault' => (bool) ($item['isDefault'] ?? false),
                'till_like' => PalPlussConfigResolver::isTillChannelType($type),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PalPlussException
     */
    public function verifyConnection(): array
    {
        $config = $this->resolver->resolve();
        if ($config['api_key'] === '') {
            throw new PalPlussException('PalPluss API key is not configured.', 'NOT_CONFIGURED', 422);
        }
        if ($config['channel_id'] === '') {
            throw new PalPlussException('PalPluss Till channel is not selected.', 'CHANNEL_REQUIRED', 422);
        }

        $channel = $this->client->getWithApiKey(
            $config['api_key'],
            '/payment-wallet/channels/'.$config['channel_id']
        );

        $type = (string) ($channel['type'] ?? '');
        $shortcode = (string) ($channel['shortcode'] ?? '');
        $name = (string) ($channel['name'] ?? '');
        $tillLike = PalPlussConfigResolver::isTillChannelType($type);

        $walletAvailable = null;
        $walletCurrency = 'KES';
        try {
            $balance = $this->client->getWithApiKey($config['api_key'], '/wallets/service/balance');
            $walletAvailable = $balance['availableBalance'] ?? $balance['balance'] ?? null;
            $walletCurrency = (string) ($balance['currency'] ?? 'KES');
        } catch (PalPlussException) {
            // Wallet query is best-effort for verification display.
        }

        // Persist refreshed channel metadata (no secrets).
        $this->update([
            'status' => $config['enabled'] ? 1 : 0,
            'mode' => $config['mode'],
            'channel_id' => $config['channel_id'],
            'channel_shortcode' => $shortcode,
            'channel_type' => $type,
            'channel_name' => $name,
        ]);

        return [
            'ok' => $tillLike,
            'channel_id' => $config['channel_id'],
            'channel_name' => $name,
            'channel_type' => $type,
            'channel_shortcode' => $shortcode,
            'till_like' => $tillLike,
            'wallet_available' => $walletAvailable,
            'wallet_currency' => $walletCurrency,
            'verified_at' => now()->toIso8601String(),
            'message' => $tillLike
                ? 'Connected successfully.'
                : 'Channel found but is not a Till / TILL_NUMBER channel.',
        ];
    }
}
