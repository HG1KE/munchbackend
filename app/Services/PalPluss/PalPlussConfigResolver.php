<?php

namespace App\Services\PalPluss;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runtime PalPluss configuration.
 *
 * Source of truth: Admin Payment Settings (addon_settings key_name=palpluss).
 * Non-secret base_url may come from config/env default.
 * Env PALPLUSS_API_KEY / PALPLUSS_CHANNEL_ID are legacy fallback only when Admin is empty.
 */
class PalPlussConfigResolver
{
    public const GATEWAY_KEY = 'palpluss';

    public const SETTINGS_TYPE = 'payment_config';

    /**
     * @return array{
     *     enabled: bool,
     *     api_key: string,
     *     channel_id: string,
     *     channel_shortcode: ?string,
     *     channel_type: ?string,
     *     channel_name: ?string,
     *     base_url: string,
     *     mode: string,
     *     api_key_configured: bool,
     *     source: string
     * }
     */
    public function resolve(): array
    {
        $admin = $this->adminValues();
        $baseUrl = rtrim((string) (
            $admin['base_url']
            ?? config('palpluss.base_url')
            ?? 'https://api.palpluss.com/v1'
        ), '/');

        $apiKey = $this->resolveApiKey($admin);
        $channelId = $this->firstFilled(
            $admin['channel_id'] ?? null,
            config('palpluss.channel_id'),
            env('PALPLUSS_CHANNEL_ID'),
        ) ?? '';

        $enabled = (bool) ($admin['is_active'] ?? false);
        if ($admin === []) {
            // Legacy: treat non-empty env as enabled only when Admin row missing.
            $enabled = $apiKey !== '' && $channelId !== '';
        }

        $source = isset($admin['api_key_encrypted']) && $admin['api_key_encrypted'] !== ''
            ? 'admin'
            : (($apiKey !== '' && env('PALPLUSS_API_KEY')) ? 'env_fallback' : 'admin');

        return [
            'enabled' => $enabled,
            'api_key' => $apiKey,
            'channel_id' => $channelId,
            'channel_shortcode' => isset($admin['channel_shortcode']) ? (string) $admin['channel_shortcode'] : null,
            'channel_type' => isset($admin['channel_type']) ? (string) $admin['channel_type'] : null,
            'channel_name' => isset($admin['channel_name']) ? (string) $admin['channel_name'] : null,
            'base_url' => $baseUrl !== '' ? $baseUrl : 'https://api.palpluss.com/v1',
            'mode' => (string) ($admin['mode'] ?? 'live'),
            'api_key_configured' => $apiKey !== '',
            'source' => $source,
        ];
    }

    public function isEnabledAndConfigured(): bool
    {
        $c = $this->resolve();

        return $c['enabled'] && $c['api_key'] !== '' && $c['channel_id'] !== '';
    }

    /**
     * Safe view/API payload — never includes plaintext API key.
     *
     * @return array<string, mixed>
     */
    public function adminSafeView(): array
    {
        $c = $this->resolve();
        $setting = $this->settingRow();

        return [
            'enabled' => $c['enabled'],
            'api_key_configured' => $c['api_key_configured'],
            'api_key_masked' => $c['api_key_configured'] ? $this->maskSecret($c['api_key']) : '',
            'channel_id' => $c['channel_id'],
            'channel_shortcode' => $c['channel_shortcode'],
            'channel_type' => $c['channel_type'],
            'channel_name' => $c['channel_name'],
            'base_url' => $c['base_url'],
            'mode' => $c['mode'],
            'gateway_title' => $this->gatewayTitle($setting),
            'is_active' => (int) ($setting->is_active ?? 0),
        ];
    }

    public function encryptApiKey(string $plain): string
    {
        return Crypt::encryptString($plain);
    }

    public function decryptApiKey(string $encrypted): ?string
    {
        try {
            $plain = Crypt::decryptString($encrypted);

            return is_string($plain) && trim($plain) !== '' ? trim($plain) : null;
        } catch (DecryptException|Throwable $e) {
            Log::warning('palpluss.config_decrypt_failed', [
                'message' => 'Unable to decrypt stored PalPluss API key.',
            ]);

            return null;
        }
    }

    public function maskSecret(string $plain): string
    {
        $len = strlen($plain);
        if ($len <= 8) {
            return str_repeat('•', max(8, $len));
        }

        return substr($plain, 0, 4).str_repeat('•', min(16, $len - 8)).substr($plain, -4);
    }

    public static function isTillChannelType(?string $type): bool
    {
        $type = strtoupper(trim((string) $type));

        return in_array($type, ['TILL', 'TILL_NUMBER'], true);
    }

    /**
     * @param  array<string, mixed>  $admin
     */
    private function resolveApiKey(array $admin): string
    {
        if (! empty($admin['api_key_encrypted']) && is_string($admin['api_key_encrypted'])) {
            $decrypted = $this->decryptApiKey($admin['api_key_encrypted']);
            if ($decrypted !== null) {
                return $decrypted;
            }
        }

        // Never prefer plaintext in admin if encrypted field exists empty — fall back env.
        return $this->firstFilled(
            config('palpluss.api_key'),
            env('PALPLUSS_API_KEY'),
        ) ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private function adminValues(): array
    {
        $row = $this->settingRow();
        if ($row === null) {
            return [];
        }

        $raw = ($row->mode ?? 'live') === 'test'
            ? ($row->test_values ?? null)
            : ($row->live_values ?? null);

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
        } elseif (is_array($raw)) {
            $decoded = $raw;
        } else {
            $decoded = [];
        }

        if (! is_array($decoded)) {
            $decoded = [];
        }

        $decoded['is_active'] = (int) ($row->is_active ?? 0) === 1;
        $decoded['mode'] = (string) ($row->mode ?? 'live');

        return $decoded;
    }

    private function settingRow(): ?Setting
    {
        try {
            if (! $this->tableExists()) {
                return null;
            }

            return Setting::query()
                ->where('key_name', self::GATEWAY_KEY)
                ->where('settings_type', self::SETTINGS_TYPE)
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function tableExists(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('addon_settings');
        } catch (Throwable) {
            return false;
        }
    }

    private function gatewayTitle(?Setting $setting): string
    {
        if ($setting === null || $setting->additional_data === null) {
            return 'M-PESA (PalPluss)';
        }

        $extra = is_string($setting->additional_data)
            ? json_decode($setting->additional_data, true)
            : (array) $setting->additional_data;

        return is_array($extra) && ! empty($extra['gateway_title'])
            ? (string) $extra['gateway_title']
            : 'M-PESA (PalPluss)';
    }

    private function firstFilled(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === false) {
                continue;
            }
            $trimmed = trim((string) $candidate);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }
}
