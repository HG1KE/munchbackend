<?php

namespace App\Console\Commands;

use App\Exceptions\PalPlussException;
use App\Services\PalPluss\PalPlussHttpClient;
use Illuminate\Console\Command;

/**
 * Safe ops check: never prints API key. Reports channel type/shortcode/default + wallet balance.
 */
class PalPlussVerifyChannelCommand extends Command
{
    protected $signature = 'palpluss:verify-channel';

    protected $description = 'Verify PalPluss env channel exists and report Till/shortcode metadata (no secrets)';

    public function handle(PalPlussHttpClient $client): int
    {
        $this->line('api_key_set='.($client->apiKey() !== '' ? 'yes' : 'no'));
        $this->line('base_url='.$client->baseUrl());
        $this->line('channel_id_set='.($client->channelId() !== '' ? 'yes' : 'no'));

        if (! $client->isConfigured()) {
            $this->error('PalPluss not fully configured (need PALPLUSS_API_KEY, PALPLUSS_BASE_URL, PALPLUSS_CHANNEL_ID).');

            return self::FAILURE;
        }

        try {
            $channel = $client->get('/payment-wallet/channels/'.$client->channelId());
            $balance = $client->get('/wallets/service/balance');
        } catch (PalPlussException $e) {
            $this->error('PalPluss API error: '.($e->errorCode ?? 'ERROR').' — '.$e->getMessage());

            return self::FAILURE;
        }

        $type = (string) ($channel['type'] ?? '');
        $shortcode = (string) ($channel['shortcode'] ?? '');
        $isDefault = ! empty($channel['isDefault']);
        $name = (string) ($channel['name'] ?? '');

        $tillLike = in_array($type, ['TILL', 'TILL_NUMBER'], true);

        $this->line('channel_id='.$client->channelId());
        $this->line('channel_name='.$name);
        $this->line('channel_type='.$type);
        $this->line('channel_shortcode='.$shortcode);
        $this->line('channel_is_default='.($isDefault ? 'yes' : 'no'));
        $this->line('channel_till_like='.($tillLike ? 'yes' : 'no'));
        $this->line('service_wallet_available='.(string) ($balance['availableBalance'] ?? $balance['balance'] ?? 'unknown'));
        $this->line('service_wallet_currency='.(string) ($balance['currency'] ?? 'KES'));

        if (! $tillLike) {
            $this->warn('Channel type is not TILL/TILL_NUMBER — confirm this is your Buy Goods Till channel.');
        }

        return self::SUCCESS;
    }
}
