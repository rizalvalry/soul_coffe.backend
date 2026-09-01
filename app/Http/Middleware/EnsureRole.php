<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route/controller-level role gate (docs/02 §2.1 permission matrix).
 *
 * Usage: `role:FINANCE,ADMINISTRATOR` — the authenticated user's role must be one of the
 * given values, compared against the Role enum's backing string. This only answers "is this
 * role allowed to reach this endpoint at all" — scoping WITHIN an allowed role (e.g. "only
 * your own cart") is a separate concern handled by policies or query-level scoping, never here.
 *
 * Must run after `auth:sanctum` so `$request->user()` is already resolved; when attached via a
 * controller's `HasMiddleware::middleware()` this is guaranteed by Laravel's route middleware
 * ordering (route/group middleware first, controller middleware appended after).
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role->value, $roles, true)) {
            abort(403, 'Anda tidak memiliki akses untuk aksi ini.');
        }

        return $next($request);
    }
}
