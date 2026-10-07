<?php

namespace App\Observers;

use App\CentralLogics\StorefrontConfigService;
use App\Models\Setting;

class AddonSettingObserver
{
    public function created(Setting $setting): void
    {
        $this->invalidateStorefrontConfigIfPayment($setting);
    }

    public function updated(Setting $setting): void
    {
        $this->invalidateStorefrontConfigIfPayment($setting);
    }

    public function deleted(Setting $setting): void
    {
        $this->invalidateStorefrontConfigIfPayment($setting);
    }

    public function restored(Setting $setting): void
    {
        $this->invalidateStorefrontConfigIfPayment($setting);
    }

    public function forceDeleted(Setting $setting): void
    {
        $this->invalidateStorefrontConfigIfPayment($setting);
    }

    private function invalidateStorefrontConfigIfPayment(Setting $setting): void
    {
        if ($setting->settings_type !== 'payment_config') {
            return;
        }

        StorefrontConfigService::invalidateAfterPaymentConfigChange();
    }
}
