<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureActiveAccountMiddleware
{
    public function handle(Request $request, Closure $next, ?string $guard = null)
    {
        $guard = $guard ?: config('auth.defaults.guard');
        $user = Auth::guard($guard)->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        if (property_exists($user, 'status') && $user->status === false) {
            return response()->json([
                'message' => 'Account is inactive or suspended',
            ], 403);
        }

        return $next($request);
    }
}
