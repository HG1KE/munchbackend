<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PROMO_KEY = 'textsms_ke_promotional';

    private const ABANDONED_KEY = 'textsms_ke_abandoned_cart';

    public function up(): void
    {
        $row = DB::table('addon_settings')
            ->where('key_name', self::ABANDONED_KEY)
            ->where('settings_type', 'sms_config')
            ->first();

        if (! $row) {
            return;
        }

        $live = json_decode($row->live_values, true) ?: [];
        $test = json_decode($row->test_values, true) ?: [];

        $promoLive = $this->buildPromotionalPayload($live);
        $promoTest = $this->buildPromotionalPayload($test);

        $existingPromo = DB::table('addon_settings')
            ->where('key_name', self::PROMO_KEY)
            ->where('settings_type', 'sms_config')
            ->first();

        if ($existingPromo) {
            DB::table('addon_settings')
                ->where('key_name', self::PROMO_KEY)
                ->where('settings_type', 'sms_config')
                ->update([
                    'live_values' => json_encode($promoLive),
                    'test_values' => json_encode($promoTest),
                    'mode' => $row->mode ?? 'test',
                    'is_active' => (int) ($promoLive['status'] ?? 0) === 1 ? 1 : 0,
                ]);
        } else {
            DB::table('addon_settings')->insert([
                'id' => (string) Str::uuid(),
                'key_name' => self::PROMO_KEY,
                'settings_type' => 'sms_config',
                'live_values' => json_encode($promoLive),
                'test_values' => json_encode($promoTest),
                'mode' => $row->mode ?? 'test',
                'is_active' => (int) ($promoLive['status'] ?? 0) === 1 ? 1 : 0,
            ]);
        }

        $liveStrip = $live;
        $testStrip = $test;
        unset($liveStrip['api_key'], $liveStrip['partner_id'], $liveStrip['sender_id']);
        unset($testStrip['api_key'], $testStrip['partner_id'], $testStrip['sender_id']);

        DB::table('addon_settings')
            ->where('key_name', self::ABANDONED_KEY)
            ->where('settings_type', 'sms_config')
            ->update([
                'live_values' => json_encode($liveStrip),
                'test_values' => json_encode($testStrip),
            ]);
    }

    public function down(): void
    {
        $promo = DB::table('addon_settings')
            ->where('key_name', self::PROMO_KEY)
            ->where('settings_type', 'sms_config')
            ->first();

        $ab = DB::table('addon_settings')
            ->where('key_name', self::ABANDONED_KEY)
            ->where('settings_type', 'sms_config')
            ->first();

        if (! $promo || ! $ab) {
            return;
        }

        $live = json_decode($ab->live_values, true) ?: [];
        $test = json_decode($ab->test_values, true) ?: [];
        $promoLive = json_decode($promo->live_values, true) ?: [];

        foreach (['api_key', 'partner_id', 'sender_id'] as $k) {
            if (! empty($promoLive[$k])) {
                $live[$k] = $promoLive[$k];
                $test[$k] = $promoLive[$k];
            }
        }

        DB::table('addon_settings')
            ->where('key_name', self::ABANDONED_KEY)
            ->where('settings_type', 'sms_config')
            ->update([
                'live_values' => json_encode($live),
                'test_values' => json_encode($test),
            ]);

        DB::table('addon_settings')
            ->where('key_name', self::PROMO_KEY)
            ->where('settings_type', 'sms_config')
            ->delete();
    }

    /**
     * @param  array<string,mixed>  $fromAbandoned
     * @return array<string,mixed>
     */
    private function buildPromotionalPayload(array $fromAbandoned): array
    {
        return [
            'gateway' => self::PROMO_KEY,
            'mode' => $fromAbandoned['mode'] ?? 'test',
            'status' => (int) ($fromAbandoned['status'] ?? 0),
            'api_key' => (string) ($fromAbandoned['api_key'] ?? ''),
            'partner_id' => (string) ($fromAbandoned['partner_id'] ?? ''),
            'sender_id' => (string) ($fromAbandoned['sender_id'] ?? ''),
            'http_timeout_seconds' => (string) ($fromAbandoned['http_timeout_seconds'] ?? '30'),
            'is_otp_gateway' => 0,
        ];
    }
};
