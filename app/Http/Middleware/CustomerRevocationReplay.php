<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/** Replays logout and account deletion after their first call has revoked the bearer token. */
class CustomerRevocationReplay
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ($request->is('api/v1/customer/auth/logout') && $request->isMethod('POST'))
            && ! ($request->is('api/v1/customer/me') && $request->isMethod('DELETE'))) {
            return $next($request);
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return new JsonResponse(['message' => 'Header Idempotency-Key wajib dikirim untuk aksi ini.'], 422);
        }
        $bearer = $request->bearerToken();
        if (! is_string($bearer) || $bearer === '') {
            return $next($request);
        }

        $key = 'customer:revocation:'.hash('sha256', $bearer.'|'.$request->path().'|'.$idempotencyKey);
        $stored = Cache::get($key);
        if (is_array($stored)) {
            return $this->replay($request, $stored);
        }

        $lock = Cache::lock($key.':lock', 30);
        if (! $lock->get()) {
            return new JsonResponse(['message' => 'Permintaan ini masih diproses. Coba lagi ya.'], 409);
        }
        try {
            $stored = Cache::get($key);
            if (is_array($stored)) {
                return $this->replay($request, $stored);
            }
            $response = $next($request);
            if ($response->isSuccessful() && $response instanceof JsonResponse) {
                Cache::put($key, [
                    'customer_id' => $request->user()->id,
                    'body' => $response->getData(true),
                ], 86400);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function replay(Request $request, array $stored): JsonResponse
    {
        if ($request->is('api/v1/customer/auth/logout')
            && ! Customer::query()->whereKey($stored['customer_id'])->where('status', 'ACTIVE')->exists()) {
            return new JsonResponse(['message' => 'Akun kamu sedang nonaktif. Hubungi tim Soul Coffee ya.'], 403);
        }

        return new JsonResponse($stored['body'], 200, ['Idempotency-Replayed' => 'true']);
    }
}
