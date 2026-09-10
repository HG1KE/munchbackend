<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Laravel\Passport\Passport;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        'App\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        $minutes = (int) config('auth.remember_duration', 5256000);
        foreach (['admin', 'branch'] as $guardName) {
            $guard = $this->app['auth']->guard($guardName);
            if (method_exists($guard, 'setRememberDuration')) {
                $guard->setRememberDuration($minutes);
            }
        }
    }
}
