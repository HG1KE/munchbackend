<?php

namespace App\Observers;

use App\CentralLogics\Helpers;
use App\CentralLogics\StorefrontConfigService;
use App\Model\BusinessSetting;


class BusinessSettingObserver
{
    /**
     * Handle the BusinessSetting "created" event.
     */
    public function created(BusinessSetting $businessSetting): void
    {
        $this->refreshBusinessSettingsCache();
    }

    /**
     * Handle the BusinessSetting "updated" event.
     */
    public function updated(BusinessSetting $businessSetting): void
    {
        $this->refreshBusinessSettingsCache();
    }

    /**
     * Handle the BusinessSetting "deleted" event.
     */
    public function deleted(BusinessSetting $businessSetting): void
    {
        $this->refreshBusinessSettingsCache();
    }

    /**
     * Handle the BusinessSetting "restored" event.
     */
    public function restored(BusinessSetting $businessSetting): void
    {
        $this->refreshBusinessSettingsCache();
    }

    /**
     * Handle the BusinessSetting "force deleted" event.
     */
    public function forceDeleted(BusinessSetting $businessSetting): void
    {
        $this->refreshBusinessSettingsCache();
    }

    private function refreshBusinessSettingsCache(): void
    {
        Helpers::forgetBusinessSettingsRuntimeCache();
        Helpers::forgetRestaurantSchedulesRuntimeCache();
        StorefrontConfigService::forgetCachedConfiguration();
    }
}
