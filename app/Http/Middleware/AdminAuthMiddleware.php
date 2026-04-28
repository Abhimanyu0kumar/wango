<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Token;
use Symfony\Component\HttpFoundation\Response;

class AdminAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (!$bearerToken) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        try {
            // For JWT tokens, we need to decode and find the token
            $tokenId = $this->getTokenIdFromJwt($bearerToken);

            if (!$tokenId) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $accessToken = Token::find($tokenId);

            if (!$accessToken || !$accessToken->client || $accessToken->client->provider !== 'admins') {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            // Check if token is revoked or expired
            if ($accessToken->revoked || ($accessToken->expires_at && $accessToken->expires_at->isPast())) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            // Authenticate the admin user
            $admin = $accessToken->user;
            if (!$admin) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            Auth::guard('admin')->setUser($admin);
            Auth::shouldUse('admin');
        } catch (\Exception $e) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }

    /**
     * Extract token ID from JWT payload
     */
    private function getTokenIdFromJwt(string $token): ?string
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);

        return $payload['jti'] ?? null;
    }
}
