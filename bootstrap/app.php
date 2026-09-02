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
        // This app has no web login route. Authenticate::unauthenticated() calls redirectTo()
        // to build the guest-redirect target whenever the request does not itself look like it
        // expects JSON (i.e. no Accept: application/json) - before the exception handler's
        // shouldRenderJsonWhen below ever runs. The default redirectTo() falls back to
        // route('login'), which does not exist here, so an unauthenticated api/* request
        // throws RouteNotFoundException (500) instead of returning 401. Returning null for
        // api/* keeps the redirect target empty so unauthenticated() constructs the
        // AuthenticationException without exploding, and the handler below then renders it
        // as JSON.
        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*') ? null : route('login'));

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
        // Everything under api/* is a JSON API and must answer in JSON, even when the caller
        // forgets `Accept: application/json`. Without this, Laravel treats a validation failure
        // as a web form error and answers 302 with an HTML redirect — a mobile client then sees
        // a redirect instead of the 422 it needs, and the real error message is lost.
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
