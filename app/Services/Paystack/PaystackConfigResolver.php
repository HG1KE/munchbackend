<?php

namespace App\Services\Paystack;

use App\Services\PaystackService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Authoritative Paystack credentials for every checkout and fulfillment path.
 *
 * Precedence matches the legacy hosted constructor:
 * non-empty .env keys override admin payment settings; empty / false / missing
 * values never override a populated admin key.
 */
class PaystackConfigResolver
{
    public function resolve(): PaystackService
    {
        return PaystackService::fromConfig($this->resolveConfig());
    }

    /**
     * Absolute URL Paystack Dashboard must call for charge.success (and other) events.
     */
    public function webhookAbsoluteUrl(): string
    {
        $path = (string) config('paystack.webhook_path', '/api/v1/paystack/webhook');
        if ($path === '') {
            $path = '/api/v1/paystack/webhook';
        }
        if (! str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        return rtrim((string) config('app.url'), '/') . $path;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveConfig(): array
    {
        $config = config('paystack', []);
        if (! is_array($config)) {
            $config = [];
        }

        $admin = $this->adminGatewayValues();

        $publicKey = self::firstFilled(
            env('PAYSTACK_PUBLIC_KEY'),
            $config['public_key'] ?? null,
            $config['publicKey'] ?? null,
            $admin['public_key'] ?? null,
            $admin['publicKey'] ?? null,
        );
        $secretKey = self::firstFilled(
            env('PAYSTACK_SECRET_KEY'),
            $config['secret_key'] ?? null,
            $config['secretKey'] ?? null,
            $admin['secret_key'] ?? null,
            $admin['secretKey'] ?? null,
        );
        $paymentUrl = self::firstFilled(
            env('PAYSTACK_PAYMENT_URL'),
            $config['payment_url'] ?? null,
            $config['paymentUrl'] ?? null,
            'https://api.paystack.co',
        );
        $merchantEmail = self::firstFilled(
            env('MERCHANT_EMAIL'),
            $config['merchant_email'] ?? null,
            $config['merchantEmail'] ?? null,
            $admin['merchant_email'] ?? null,
            $admin['merchantEmail'] ?? null,
        );

        return array_merge($config, [
            'public_key' => $publicKey,
            'secret_key' => $secretKey,
            'publicKey' => $publicKey,
            'secretKey' => $secretKey,
            'payment_url' => $paymentUrl,
            'paymentUrl' => $paymentUrl,
            'merchant_email' => $merchantEmail,
            'merchantEmail' => $merchantEmail,
        ]);
    }

    /**
     * First usable credential / config string. null, false, and blank values are skipped.
     */
    public static function firstFilled(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $value = self::filledString($candidate);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    public static function filledString(mixed $value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            $trimmed = trim((string) $value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function adminGatewayValues(): array
    {
        $dbConfig = $this->paymentGatewaySettings();
        if ($dbConfig === null) {
            return [];
        }

        $values = $this->decodeGatewayValues($dbConfig);
        if ($values === null) {
            return [];
        }

        return get_object_vars($values);
    }

    private function paymentGatewaySettings(): ?object
    {
        try {
            if (! $this->addonSettingsTableExists()) {
                return null;
            }

            return DB::table('addon_settings')
                ->where('key_name', 'paystack')
                ->where('settings_type', 'payment_config')
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function addonSettingsTableExists(): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable('addon_settings');
        } catch (Throwable) {
            return false;
        }
    }

    private function decodeGatewayValues(object $dbConfig): ?object
    {
        $raw = ($dbConfig->mode ?? '') === 'live'
            ? ($dbConfig->live_values ?? '{}')
            : ($dbConfig->test_values ?? '{}');

        if (is_array($raw)) {
            $raw = json_encode($raw);
        }

        $values = is_string($raw) ? json_decode($raw) : json_decode(json_encode($raw));

        return is_object($values) ? $values : null;
    }
}
