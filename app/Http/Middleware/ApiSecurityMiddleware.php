<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ApiSecurityMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // 1. Basic request size protection (optional security hardening)
        if ($request->header('Content-Length') > 5 * 1024 * 1024) {
            return response()->json([
                'message' => 'Payload too large'
            ], 413);
        }

        // 2. Block obviously malicious patterns (light check only)
        $uri = $request->getRequestUri();

        if (str_contains($uri, '..') || str_contains($uri, '<script')) {
            return response()->json([
                'message' => 'Invalid request'
            ], 400);
        }

        return $next($request);
    }
}
