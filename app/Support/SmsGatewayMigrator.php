<?php

namespace App\Support;

/**
 * Safe upgrade from the three legacy TextSMS KE gateway cards
 * into TextSMS Transactional + TextSMS Promotional + a template store.
 */
class SmsGatewayMigrator
{
    /**
     * Prefer the first active config that has an API key, then any config with a key.
     *
     * @param  array<string, mixed>|null  $customerConfirm
     * @param  array<string, mixed>|null  $otp
     * @param  array<string, mixed>|null  $branch
     * @return array<string, mixed>|null
     */
    public static function pickTransactionalSource(?array $customerConfirm, ?array $otp, ?array $branch): ?array
    {
        $candidates = [
            $customerConfirm,
            $otp,
            $branch,
        ];

        foreach ($candidates as $cfg) {
            if (self::isActive($cfg) && self::hasApiKey($cfg)) {
                return $cfg;
            }
        }

        foreach ($candidates as $cfg) {
            if (self::hasApiKey($cfg)) {
                return $cfg;
            }
        }

        foreach ($candidates as $cfg) {
            if (is_array($cfg) && $cfg !== []) {
                return $cfg;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $customerConfirm
     * @param  array<string, mixed>|null  $otp
     * @param  array<string, mixed>|null  $branch
     */
    public static function transactionalShouldBeActive(?array $customerConfirm, ?array $otp, ?array $branch): bool
    {
        return self::isActive($customerConfirm) || self::isActive($otp) || self::isActive($branch);
    }

    /**
     * @param  array<string, mixed>|null  $source
     * @return array<string, mixed>
     */
    public static function buildTransactionalPayload(?array $source, int $status): array
    {
        $source = is_array($source) ? $source : [];

        return [
            'gateway' => SmsGatewayKeys::TRANSACTIONAL,
            'mode' => ((int) $status === 1) ? 'live' : 'test',
            'status' => $status === 1 ? 1 : 0,
            'api_key' => (string) ($source['api_key'] ?? ''),
            'partner_id' => (string) ($source['partner_id'] ?? ''),
            'sender_id' => (string) ($source['sender_id'] ?? ''),
            'endpoint' => self::normalizeEndpoint($source['endpoint'] ?? null, SmsGatewayKeys::DEFAULT_SENDSMS_ENDPOINT),
            'http_timeout_seconds' => (string) ($source['http_timeout_seconds'] ?? '30'),
            'is_otp_gateway' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    public static function buildPromotionalPayload(?array $existing, bool $createEmptyDisabled): array
    {
        if ($createEmptyDisabled || ! is_array($existing)) {
            return [
                'gateway' => SmsGatewayKeys::PROMOTIONAL,
                'mode' => 'test',
                'status' => 0,
                'api_key' => '',
                'partner_id' => '',
                'sender_id' => '',
                'endpoint' => SmsGatewayKeys::DEFAULT_SENDSMS_ENDPOINT,
                'http_timeout_seconds' => '30',
                'is_otp_gateway' => 0,
            ];
        }

        $merged = $existing;
        $merged['gateway'] = SmsGatewayKeys::PROMOTIONAL;
        $merged['endpoint'] = self::normalizeEndpoint($existing['endpoint'] ?? null, SmsGatewayKeys::DEFAULT_SENDSMS_ENDPOINT);
        if (! isset($merged['http_timeout_seconds']) || (string) $merged['http_timeout_seconds'] === '') {
            $merged['http_timeout_seconds'] = '30';
        }
        $merged['is_otp_gateway'] = 0;

        return $merged;
    }

    /**
     * @param  array<string, mixed>|null  $customerConfirm
     * @param  array<string, mixed>|null  $otp
     * @param  array<string, mixed>|null  $branch
     * @param  array<string, array<string, mixed>>  $existingTemplates
     * @return array<string, array<string, mixed>>
     */
    public static function seedTemplates(?array $customerConfirm, ?array $otp, ?array $branch, array $existingTemplates = []): array
    {
        $seeded = [];

        $seeded[SmsTemplateCatalog::CUSTOMER_OTP] = SmsTemplateCatalog::normalizeTemplate(SmsTemplateCatalog::CUSTOMER_OTP, [
            'status' => self::isActive($otp) ? 1 : 0,
            'message' => (string) ($otp['otp_template'] ?? ''),
            'gateway' => SmsGatewayKeys::ASSIGNMENT_TRANSACTIONAL,
        ]);

        $seeded[SmsTemplateCatalog::BRANCH_NEW_ORDER] = SmsTemplateCatalog::normalizeTemplate(SmsTemplateCatalog::BRANCH_NEW_ORDER, [
            'status' => self::isActive($branch) ? 1 : 0,
            'message' => (string) ($branch['notification_template'] ?? ''),
            'gateway' => SmsGatewayKeys::ASSIGNMENT_TRANSACTIONAL,
        ]);

        $placedMessage = '';
        if (is_array($customerConfirm)) {
            $placedMessage = (string) ($customerConfirm['order_placed_template'] ?? '');
            if ($placedMessage === '') {
                $placedMessage = (string) ($customerConfirm['notification_template'] ?? '');
            }
        }

        $seeded[SmsTemplateCatalog::ORDER_PLACED] = SmsTemplateCatalog::normalizeTemplate(SmsTemplateCatalog::ORDER_PLACED, [
            'status' => self::isActive($customerConfirm) ? 1 : 0,
            'message' => $placedMessage,
            'gateway' => SmsGatewayKeys::ASSIGNMENT_TRANSACTIONAL,
        ]);

        $seeded[SmsTemplateCatalog::PROCESSING] = SmsTemplateCatalog::normalizeTemplate(SmsTemplateCatalog::PROCESSING, [
            'status' => self::isActive($customerConfirm) ? 1 : 0,
            'message' => (string) ($customerConfirm['processing_template'] ?? ''),
            'gateway' => SmsGatewayKeys::ASSIGNMENT_TRANSACTIONAL,
        ]);

        foreach (SmsTemplateCatalog::keys() as $key) {
            if (isset($seeded[$key])) {
                continue;
            }
            $stored = isset($existingTemplates[$key]) && is_array($existingTemplates[$key])
                ? $existingTemplates[$key]
                : [];
            if ($stored === []) {
                $stored = [
                    'status' => 0,
                    'gateway' => SmsGatewayKeys::ASSIGNMENT_TRANSACTIONAL,
                ];
            } elseif (! isset($stored['gateway']) || ! SmsGatewayKeys::isAssignment((string) $stored['gateway'])) {
                $stored['gateway'] = SmsGatewayKeys::ASSIGNMENT_TRANSACTIONAL;
            }
            $seeded[$key] = SmsTemplateCatalog::normalizeTemplate($key, $stored);
        }

        foreach ($existingTemplates as $key => $stored) {
            if (! is_string($key) || isset($seeded[$key]) || ! is_array($stored)) {
                continue;
            }
            $seeded[$key] = $stored;
        }

        return $seeded;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function stripLegacyGatewayToCredentialsOnly(array $payload, string $legacyKey): array
    {
        $payload['status'] = 0;
        $payload['gateway'] = $legacyKey;
        unset(
            $payload['otp_template'],
            $payload['notification_template'],
            $payload['order_placed_template'],
            $payload['processing_template']
        );

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $cfg
     */
    public static function isActive(?array $cfg): bool
    {
        return is_array($cfg) && (int) ($cfg['status'] ?? 0) === 1;
    }

    /**
     * @param  array<string, mixed>|null  $cfg
     */
    public static function hasApiKey(?array $cfg): bool
    {
        return is_array($cfg) && trim((string) ($cfg['api_key'] ?? '')) !== '';
    }

    /**
     * @param  array<string, mixed>|null  $cfg
     */
    public static function hasCompleteCredentials(?array $cfg): bool
    {
        if (! is_array($cfg)) {
            return false;
        }
        foreach (['api_key', 'partner_id', 'sender_id'] as $k) {
            if (trim((string) ($cfg[$k] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    public static function normalizeEndpoint(mixed $endpoint, string $fallback): string
    {
        $value = trim((string) $endpoint);

        return $value !== '' ? $value : $fallback;
    }
}
