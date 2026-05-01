<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\ApiSecurityMiddleware;
use App\Http\Middleware\AdminAuthMiddleware;
use App\Http\Middleware\JsonApiMiddleware;
use App\Http\Middleware\EnsureActiveAccountMiddleware;
use App\Http\Middleware\VerifySignedRequestMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        channels: __DIR__.'/../routes/channels.php',
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(append: [
            ApiSecurityMiddleware::class,
        ]);

        $middleware->alias([
            'admin.auth' => AdminAuthMiddleware::class,
            'json.api' => JsonApiMiddleware::class,
            'account.active' => EnsureActiveAccountMiddleware::class,
            'signed.request' => VerifySignedRequestMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
