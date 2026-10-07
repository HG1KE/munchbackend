<?php

namespace App\Console\Commands;

use App\Exceptions\PalPlussException;
use App\Services\PalPluss\PalPlussConfigResolver;
use App\Services\PalPluss\PalPlussSettingsService;
use Illuminate\Console\Command;

/**
 * Safe ops check using Admin Payment Settings. Never prints API key.
 */
class PalPlussVerifyChannelCommand extends Command
{
    protected $signature = 'palpluss:verify-channel';

    protected $description = 'Verify PalPluss Admin Payment Settings (Till channel + wallet; no secrets)';

    public function handle(PalPlussConfigResolver $resolver, PalPlussSettingsService $settings): int
    {
        $view = $resolver->adminSafeView();

        $this->line('enabled='.($view['enabled'] ? 'yes' : 'no'));
        $this->line('api_key_configured='.($view['api_key_configured'] ? 'yes' : 'no'));
        $this->line('channel_id_set='.(($view['channel_id'] ?? '') !== '' ? 'yes' : 'no'));
        $this->line('base_url='.$view['base_url']);
        $this->line('config_source='.$resolver->resolve()['source']);

        if (! $view['api_key_configured'] || ($view['channel_id'] ?? '') === '') {
            $this->error('PalPluss not fully configured in Admin Payment Settings.');

            return self::FAILURE;
        }

        try {
            $result = $settings->verifyConnection();
        } catch (PalPlussException $e) {
            $this->error('PalPluss API error: '.($e->errorCode ?? 'ERROR').' — '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('channel_name='.($result['channel_name'] ?? ''));
        $this->line('channel_type='.($result['channel_type'] ?? ''));
        $this->line('channel_shortcode='.($result['channel_shortcode'] ?? ''));
        $this->line('channel_till_like='.(! empty($result['till_like']) ? 'yes' : 'no'));
        $this->line('service_wallet_available='.(string) ($result['wallet_available'] ?? 'unknown'));
        $this->line('service_wallet_currency='.(string) ($result['wallet_currency'] ?? 'KES'));
        $this->line('verified_at='.($result['verified_at'] ?? ''));

        if (empty($result['till_like'])) {
            $this->warn('Channel type is not TILL/TILL_NUMBER — confirm this is your Buy Goods Till channel.');

            return self::FAILURE;
        }

        $this->info('OK');

        return self::SUCCESS;
    }
}
