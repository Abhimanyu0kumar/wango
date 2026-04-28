<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class JsonApiMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $method = $request->method();
        $content = $request->getContent();
        $contentType = $request->header('Content-Type', '');

        if ($method === 'GET' || $method === 'HEAD') {
            return $next($request);
        }

        if ($contentType && ! str_contains($contentType, 'application/json')) {
            return response()->json([
                'message' => 'Content-Type must be application/json',
            ], 415);
        }

        if ($request->isJson() || $request->expectsJson()) {
            return $next($request);
        }

        if (trim($content) === '') {
            return $next($request);
        }

        return response()->json([
            'message' => 'Expected JSON request',
        ], 406);
    }
}
