<?php

use App\Support\SmsGatewayKeys;
use App\Support\SmsGatewayMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $confirm = $this->readSmsConfig(SmsGatewayKeys::LEGACY_CUSTOMER_CONFIRM);
        $otp = $this->readSmsConfig(SmsGatewayKeys::LEGACY_OTP);
        $branch = $this->readSmsConfig(SmsGatewayKeys::LEGACY_BRANCH);
        $promo = $this->readSmsConfig(SmsGatewayKeys::PROMOTIONAL);
        $existingTransactional = $this->readSmsConfig(SmsGatewayKeys::TRANSACTIONAL);
        $existingTemplates = $this->readTemplates();

        if (! is_array($existingTransactional) || ! SmsGatewayMigrator::hasApiKey($existingTransactional)) {
            $source = SmsGatewayMigrator::pickTransactionalSource($confirm, $otp, $branch);
            $status = SmsGatewayMigrator::transactionalShouldBeActive($confirm, $otp, $branch) ? 1 : 0;
            $payload = SmsGatewayMigrator::buildTransactionalPayload($source, $status);
            $this->upsertSmsConfig(SmsGatewayKeys::TRANSACTIONAL, $payload);
        } else {
            $keep = $existingTransactional;
            $keep['endpoint'] = SmsGatewayMigrator::normalizeEndpoint(
                $keep['endpoint'] ?? null,
                SmsGatewayKeys::DEFAULT_SENDSMS_ENDPOINT
            );
            $keep['gateway'] = SmsGatewayKeys::TRANSACTIONAL;
            $this->upsertSmsConfig(SmsGatewayKeys::TRANSACTIONAL, $keep);
        }

        $promoExistsWithKey = is_array($promo) && SmsGatewayMigrator::hasApiKey($promo);
        $promoPayload = SmsGatewayMigrator::buildPromotionalPayload($promo, ! $promoExistsWithKey && ! is_array($promo));
        if ($promoExistsWithKey) {
            $promoPayload = SmsGatewayMigrator::buildPromotionalPayload($promo, false);
        } elseif (is_array($promo)) {
            $promoPayload = SmsGatewayMigrator::buildPromotionalPayload($promo, false);
            if (! SmsGatewayMigrator::hasApiKey($promoPayload)) {
                $promoPayload['status'] = 0;
            }
        }
        $this->upsertSmsConfig(SmsGatewayKeys::PROMOTIONAL, $promoPayload);

        $templates = SmsGatewayMigrator::seedTemplates($confirm, $otp, $branch, $existingTemplates);
        $this->upsertTemplates($templates);

        foreach (SmsGatewayKeys::legacyGatewayKeys() as $legacyKey) {
            $row = $this->readSmsConfig($legacyKey);
            if (! is_array($row)) {
                continue;
            }
            $this->upsertSmsConfig($legacyKey, SmsGatewayMigrator::stripLegacyGatewayToCredentialsOnly($row, $legacyKey));
        }
    }

    public function down(): void
    {
        // Keep migrated rows. Legacy cards stay deactivated.
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readSmsConfig(string $key): ?array
    {
        $row = DB::table('addon_settings')
            ->where('key_name', $key)
            ->where('settings_type', 'sms_config')
            ->first();

        if (! $row || $row->live_values === null) {
            return null;
        }

        $decoded = json_decode((string) $row->live_values, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readTemplates(): array
    {
        $row = DB::table('addon_settings')
            ->where('key_name', SmsGatewayKeys::TEMPLATES)
            ->where('settings_type', SmsGatewayKeys::TEMPLATES_TYPE)
            ->first();

        if (! $row || $row->live_values === null) {
            return [];
        }

        $decoded = json_decode((string) $row->live_values, true);
        if (! is_array($decoded)) {
            return [];
        }

        $templates = $decoded['templates'] ?? $decoded;

        return is_array($templates) ? $templates : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertSmsConfig(string $key, array $payload): void
    {
        $json = json_encode($payload);
        $active = (int) ($payload['status'] ?? 0) === 1 ? 1 : 0;
        $mode = $active === 1 ? 'live' : 'test';

        $existing = DB::table('addon_settings')
            ->where('key_name', $key)
            ->where('settings_type', 'sms_config')
            ->first();

        $values = [
            'key_name' => $key,
            'live_values' => $json,
            'test_values' => $json,
            'settings_type' => 'sms_config',
            'mode' => $mode,
            'is_active' => $active,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('addon_settings')
                ->where('key_name', $key)
                ->where('settings_type', 'sms_config')
                ->update($values);

            return;
        }

        $values['id'] = (string) Str::uuid();
        $values['created_at'] = now();
        DB::table('addon_settings')->insert($values);
    }

    /**
     * @param  array<string, array<string, mixed>>  $templates
     */
    private function upsertTemplates(array $templates): void
    {
        $payload = ['templates' => $templates];
        $json = json_encode($payload);

        $existing = DB::table('addon_settings')
            ->where('key_name', SmsGatewayKeys::TEMPLATES)
            ->where('settings_type', SmsGatewayKeys::TEMPLATES_TYPE)
            ->first();

        $values = [
            'key_name' => SmsGatewayKeys::TEMPLATES,
            'live_values' => $json,
            'test_values' => $json,
            'settings_type' => SmsGatewayKeys::TEMPLATES_TYPE,
            'mode' => 'live',
            'is_active' => 1,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('addon_settings')
                ->where('key_name', SmsGatewayKeys::TEMPLATES)
                ->where('settings_type', SmsGatewayKeys::TEMPLATES_TYPE)
                ->update($values);

            return;
        }

        $values['id'] = (string) Str::uuid();
        $values['created_at'] = now();
        DB::table('addon_settings')->insert($values);
    }
};
