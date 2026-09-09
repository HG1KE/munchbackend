<?php

namespace App\Services\Auth;

use App\Model\EmergencyOtpModeAuditLog;
use App\Models\LoginSetup;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class EmergencyOtpModeService
{
    public const SETTING_KEY = 'emergency_otp_mode';
    public const REASON_KEY = 'emergency_otp_mode_reason';

    /**
     * Presence of a timestamp here means the CURRENT ON state was turned on by
     * automation and is subject to the 8-hour automatic disable. Any manual
     * admin toggle clears this marker, permanently transferring ownership to the
     * admin so automation can never disable a manual enable.
     */
    public const AUTO_ENABLED_AT_KEY = 'emergency_otp_mode_auto_enabled_at';

    public function enabled(): bool
    {
        try {
            return (int) (LoginSetup::query()->where('key', self::SETTING_KEY)->value('value') ?? 0) === 1;
        } catch (Throwable $exception) {
            Log::warning('emergency_otp_mode.read_failed', [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function reason(): ?string
    {
        try {
            $reason = LoginSetup::query()->where('key', self::REASON_KEY)->value('value');

            return $reason !== null && $reason !== '' ? (string) $reason : null;
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * Manual admin toggle. Any explicit admin action takes manual ownership of
     * the ON/OFF state and clears the automatic marker, so the 8-hour
     * automatic disable can never turn OFF a manually-owned enable.
     */
    public function set(bool $enabled, ?string $reason, ?int $adminId, ?string $ipAddress): void
    {
        $wasEnabled = $this->enabled();

        $this->upsert(self::SETTING_KEY, $enabled ? '1' : '0');
        $this->upsert(self::REASON_KEY, trim((string) $reason));
        // Manual ownership: clear any automatic marker regardless of prior state.
        $this->upsert(self::AUTO_ENABLED_AT_KEY, '');

        if ($wasEnabled === $enabled) {
            return;
        }

        $this->logToggle($enabled, $reason, $adminId, $ipAddress);
    }

    /**
     * Timestamp automation turned Emergency OTP on, or null when the current ON
     * state is not automation-owned (manual, or off).
     */
    public function autoEnabledAt(): ?Carbon
    {
        try {
            $value = LoginSetup::query()->where('key', self::AUTO_ENABLED_AT_KEY)->value('value');
        } catch (Throwable $exception) {
            return null;
        }

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable $exception) {
            return null;
        }
    }

    /**
     * True only when Emergency OTP is ON and that ON state is owned by automation.
     */
    public function isAutoEnabled(): bool
    {
        return $this->enabled() && $this->autoEnabledAt() !== null;
    }

    /**
     * Automation enable. Sets the authoritative setting ON and records automatic
     * ownership + enable timestamp. Only transitions/logs when currently OFF.
     */
    public function autoEnable(string $reason): bool
    {
        if ($this->enabled()) {
            return false;
        }

        $this->upsert(self::SETTING_KEY, '1');
        $this->upsert(self::REASON_KEY, trim($reason));
        $this->upsert(self::AUTO_ENABLED_AT_KEY, now()->toDateTimeString());

        $this->logAutomationToggle(true, $reason);

        return true;
    }

    /**
     * Automation disable. Only disables when the current ON state is automation
     * owned; a manual admin enable is never disabled here. Clears the marker.
     */
    public function autoDisable(string $reason): bool
    {
        if (! $this->isAutoEnabled()) {
            return false;
        }

        $this->upsert(self::SETTING_KEY, '0');
        $this->upsert(self::REASON_KEY, trim($reason));
        $this->upsert(self::AUTO_ENABLED_AT_KEY, '');

        $this->logAutomationToggle(false, $reason);

        return true;
    }

    private function upsert(string $key, string $value): void
    {
        LoginSetup::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    private function logToggle(bool $enabled, ?string $reason, ?int $adminId, ?string $ipAddress): void
    {
        $payload = [
            'admin_id' => $adminId,
            'action' => $enabled ? 'enabled' : 'disabled',
            'enabled' => $enabled,
            'ip_address' => $ipAddress,
            'reason' => $reason,
        ];

        Log::warning('auth.emergency_otp_mode_'.$payload['action'], $payload);

        if (! Schema::hasTable('emergency_otp_mode_audit_logs')) {
            return;
        }

        EmergencyOtpModeAuditLog::query()->create(array_merge($payload, [
            'metadata' => [
                'source' => 'admin_login_setup',
            ],
            'created_at' => now(),
        ]));
    }

    private function logAutomationToggle(bool $enabled, ?string $reason): void
    {
        $action = $enabled ? 'enabled' : 'disabled';

        Log::warning('auth.emergency_otp_mode_'.$action.'_automation', [
            'action' => $action,
            'enabled' => $enabled,
            'reason' => $reason,
            'source' => 'automation',
        ]);

        if (! Schema::hasTable('emergency_otp_mode_audit_logs')) {
            return;
        }

        EmergencyOtpModeAuditLog::query()->create([
            'admin_id' => null,
            'action' => $action,
            'enabled' => $enabled,
            'ip_address' => null,
            'reason' => $reason,
            'metadata' => [
                'source' => 'automation',
            ],
            'created_at' => now(),
        ]);
    }
}
