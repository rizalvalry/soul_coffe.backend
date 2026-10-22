<?php

namespace App\Http\Middleware;

use App\Enums\PanelModule;
use App\Services\Access\PermissionMatrix;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $module, string $ability = 'view'): Response
    {
        $key = PanelModule::tryFrom($module);
        abort_unless($key !== null && PermissionMatrix::can($request->user(), $key, $ability), 403);

        return $next($request);
    }
}
