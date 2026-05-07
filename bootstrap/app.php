<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ─── CORS must run first (before routing & auth) ──────────────────────
        // This ensures browser preflight OPTIONS requests receive proper
        // CORS headers instead of being blocked with 403 Forbidden.
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        $middleware->alias([
            'token.auth' => \App\Http\Middleware\TokenAuthMiddleware::class,
            'token.2fa.pending' => \App\Http\Middleware\PendingTwoFactorTokenMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
