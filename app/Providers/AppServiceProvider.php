<?php

namespace App\Providers;

use App\CentralLogics\Helpers;
use App\Model\BusinessSetting;
use App\Model\Category;
use App\Model\Order;
use App\Models\LoginSetup;
use App\Observers\BusinessSettingObserver;
use App\Observers\CategoryObserver;
use App\Observers\LoginSetupObserver;
use App\Observers\OrderObserver;
use App\Traits\SystemAddonTrait;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;

class AppServiceProvider extends ServiceProvider
{
    use SystemAddonTrait;

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $memoryLimit = (string) config('app.memory_limit', '512M');
        if ($memoryLimit !== '' && $memoryLimit !== '-1') {
            @ini_set('memory_limit', $memoryLimit);
        }

        BusinessSetting::observe(BusinessSettingObserver::class);
        LoginSetup::observe(LoginSetupObserver::class);
        Category::observe(CategoryObserver::class);
        Order::observe(OrderObserver::class);

        //for system addon
        Config::set('addon_admin_routes',$this->get_addon_admin_routes());
        Config::set('get_payment_publish_status',$this->get_payment_publish_status());

        try {
            $timezone = Helpers::get_business_settings('time_zone');
            if (is_string($timezone) && $timezone !== '') {
                config(['app.timezone' => $timezone]);
                date_default_timezone_set($timezone);
            }
        } catch (\Exception $exception) {
        }

        Paginator::useBootstrap();
    }
}
