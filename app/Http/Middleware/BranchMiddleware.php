<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Auth;

class BranchMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (Auth::guard('branch')->check()) {
            return $next($request);
        }
        if ($request->expectsJson() || $request->header('X-Munch-POS') === '1') {
            return response()->json([
                'success' => 0,
                'code' => 'unauthenticated',
                'message' => 'Session expired. Please sign in again.',
            ], 401);
        }
        return redirect()->route('branch.auth.login');
    }
}
