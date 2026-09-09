<?php

namespace App\Services\Paystack;

use App\Services\PaystackService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Authoritative Paystack credentials: admin addon_settings (payment_config) with .env overrides.
 *
 * Used by payment-mobile inline init, paystack/verify, order protection, webhooks, and reconciliation.
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
        $dbConfig = $this->paymentGatewaySettings();

        if ($dbConfig === null) {
            return $config;
        }

        $values = $this->decodeGatewayValues($dbConfig);
        if ($values === null) {
            return $config;
        }

        return array_merge($config, [
            'public_key' => env('PAYSTACK_PUBLIC_KEY', $values->public_key ?? null),
            'secret_key' => env('PAYSTACK_SECRET_KEY', $values->secret_key ?? null),
            'payment_url' => env(
                'PAYSTACK_PAYMENT_URL',
                $values->callback_url ?? ($config['payment_url'] ?? 'https://api.paystack.co')
            ),
            'merchant_email' => env('MERCHANT_EMAIL', $values->merchant_email ?? null),
        ]);
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

        $values = is_string($raw) ? json_decode($raw) : json_decode(json_encode($raw));

        return is_object($values) ? $values : null;
    }
}
