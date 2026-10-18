<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Retry a small outbox batch after an authenticated API response on hosts without a scheduler. */
class RecoverOutboxAfterResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (config('outbox.publish_mode') !== 'inline' || $request->user() === null) {
            return;
        }

        try {
            // Atomic across PHP workers. The work runs in Kernel::terminate, after HTTP send.
            if (Cache::add('outbox.recovery.lazy', true, now()->addMinute())) {
                Artisan::call('outbox:recover', ['--limit' => 2]);
            }
        } catch (Throwable $e) {
            Log::warning('broadcast: lazy outbox recovery failed', ['error' => $e->getMessage()]);
        }
    }
}
