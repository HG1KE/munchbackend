<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class StocksMiddleware
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (Auth::guard('stocks')->check()) {
            $manager = Auth::guard('stocks')->user();
            if (isset($manager->status) && ! $manager->status) {
                Auth::guard('stocks')->logout();
                return redirect()->route('stocks.auth.login')
                    ->withErrors(translate('You have been blocked'));
            }

            return $next($request);
        }

        return redirect()->route('stocks.auth.login');
    }
}
