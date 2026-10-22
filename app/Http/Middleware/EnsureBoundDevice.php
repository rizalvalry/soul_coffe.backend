<?php

namespace App\Http\Middleware;

use App\Models\UserDevice;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureBoundDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('soul.device_binding_enforce') && $request->user()?->currentAccessToken() instanceof PersonalAccessToken) {
            $token = $request->user()->currentAccessToken();
            abort_unless($token->user_device_id !== null && UserDevice::query()
                ->whereKey($token->user_device_id)
                ->where('user_id', $request->user()->id)
                ->where('status', UserDevice::ACTIVE)->exists(), 401,
                'Sesi HP ini sudah berakhir. Coba login lagi ya.');
        }

        return $next($request);
    }
}
