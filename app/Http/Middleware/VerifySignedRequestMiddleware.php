<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifySignedRequestMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $signature = $request->header('X-Signature');
        $secret = config('app.api_signing_secret') ?: env('API_SIGNING_SECRET');

        if (! $signature || ! $secret) {
            return response()->json([
                'message' => 'Missing request signature',
            ], 401);
        }

        $payload = $request->getContent();
        $expected = hash_hmac('sha256', $payload, $secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json([
                'message' => 'Invalid request signature',
            ], 401);
        }

        return $next($request);
    }
}
