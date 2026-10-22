<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

/** Keeps only an encrypted Sanctum credential, then reconstructs the live profile on replay. */
class CustomerGoogleIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return new JsonResponse(['message' => 'Header Idempotency-Key wajib dikirim untuk aksi ini.'], 422);
        }
        $identity = $request->attributes->get('customer_google_identity');
        $cacheKey = 'customer:google:idem:'.hash('sha256', $identity['sub'].'|'.$idempotencyKey);
        $fingerprint = hash('sha256', $request->getContent());

        $stored = Cache::get($cacheKey);
        if (is_array($stored)) {
            $replay = $this->replay($stored, $fingerprint, $identity['sub']);
            if ($replay !== null) {
                return $replay;
            }
        }

        $lock = Cache::lock($cacheKey.':lock', 30);
        if (! $lock->get()) {
            return new JsonResponse(['message' => 'Permintaan ini masih diproses. Coba lagi ya.'], 409);
        }
        try {
            $stored = Cache::get($cacheKey);
            if (is_array($stored)) {
                $replay = $this->replay($stored, $fingerprint, $identity['sub']);
                if ($replay !== null) {
                    return $replay;
                }
            }
            $response = $next($request);
            if ($response instanceof JsonResponse && $response->isSuccessful()) {
                $data = $response->getData(true)['data'];
                $token = $data['token'];
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'customer_id' => $data['customer']['id'],
                    'token_id' => (int) explode('|', $token, 2)[0],
                    'encrypted_token' => Crypt::encryptString($token),
                ], 86400);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function replay(array $stored, string $fingerprint, string $sub): ?Response
    {
        if ($stored['fingerprint'] !== $fingerprint) {
            return new JsonResponse(['message' => 'Idempotency-Key sudah dipakai untuk permintaan yang berbeda.'], 422);
        }

        $customer = Customer::query()->find($stored['customer_id']);
        if ($customer === null || $customer->status !== 'ACTIVE' || $customer->google_sub !== $sub
            || ! $customer->tokens()->whereKey($stored['token_id'])->exists()) {
            return null;
        }

        return new JsonResponse(['data' => [
            'token' => Crypt::decryptString($stored['encrypted_token']),
            'customer' => $customer->apiProfile(),
        ]], 200, ['Idempotency-Replayed' => 'true']);
    }
}
