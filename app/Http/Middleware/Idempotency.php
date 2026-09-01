<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotent replay for state transitions (spec R12, E3).
 *
 * Usage: `->middleware('idempotent')` to replay when a key is supplied, or
 * `->middleware('idempotent:require')` to reject the request when the key is missing.
 *
 * Three cases are handled, and the third is the one that matters most in the field:
 *   1. First call with a key   → execute, store the response, return it
 *   2. Repeat with same key    → return the stored response, never execute twice
 *   3. Repeat WHILE the first is still in flight → 409, because a staff member on a bad
 *      connection who taps twice must not create two refill requests. A lock, not a check,
 *      is what makes this safe: two simultaneous requests would both miss a plain cache read.
 */
class Idempotency
{
    private const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $key = $request->header('Idempotency-Key');

        if (blank($key)) {
            if ($mode === 'require') {
                return new JsonResponse([
                    'message' => 'Header Idempotency-Key wajib dikirim untuk aksi ini.',
                ], 422);
            }

            return $next($request);
        }

        $userId = $request->user()?->id ?? 'guest';
        $cacheKey = sprintf('idem:%s:%s', $userId, hash('sha256', (string) $key));
        $fingerprint = hash('sha256', $request->fullUrl().'|'.$request->getContent());

        $stored = Cache::get($cacheKey);

        if (is_array($stored)) {
            return $this->replay($stored, $fingerprint);
        }

        $lock = Cache::lock($cacheKey.':lock', 30);

        if (! $lock->get()) {
            return new JsonResponse([
                'message' => 'Permintaan yang sama sedang diproses. Mohon tunggu.',
            ], 409);
        }

        try {
            // Re-check inside the lock: the holder may have finished between our read and the
            // lock acquisition.
            $stored = Cache::get($cacheKey);
            if (is_array($stored)) {
                return $this->replay($stored, $fingerprint);
            }

            $response = $next($request);

            // Only successful outcomes are cached. A failed attempt must be retryable — caching
            // a 500 would permanently poison that key for the client.
            if ($response->getStatusCode() < 400) {
                Cache::put($cacheKey, [
                    'status' => $response->getStatusCode(),
                    'body' => $response->getContent(),
                    'fingerprint' => $fingerprint,
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{status:int, body:string, fingerprint:string}  $stored
     */
    private function replay(array $stored, string $fingerprint): Response
    {
        // Same key, different payload: a client bug worth surfacing rather than silently
        // returning an unrelated stored response.
        if ($stored['fingerprint'] !== $fingerprint) {
            return new JsonResponse([
                'message' => 'Idempotency-Key sudah dipakai untuk permintaan yang berbeda.',
            ], 422);
        }

        return new Response(
            $stored['body'],
            $stored['status'],
            ['Content-Type' => 'application/json', 'Idempotency-Replayed' => 'true'],
        );
    }
}
