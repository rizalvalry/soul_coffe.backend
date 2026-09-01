<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // The channel-auth route must live under the same prefix and token guard as the rest of the
    // API: the mobile client holds a Sanctum bearer token and has no session cookie or CSRF
    // token, so the default web-guarded /broadcasting/auth would reject every subscription.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api/v1', 'middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Applied per-route rather than globally: only state transitions require a key, and a
        // global copy would run twice on routes that also declare it.
        $middleware->alias([
            'idempotent' => \App\Http\Middleware\Idempotency::class,
            // Role gate (docs/02 §2.1). Applied per-controller via the HasMiddleware
            // interface (e.g. AllocationController::middleware()) rather than in
            // routes/api.php, which is owned by another workstream.
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
