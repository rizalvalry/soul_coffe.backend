<?php

namespace App\Services\Customer;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class GoogleIdTokenVerifier
{
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /** @return array{sub:string,email:string,name:string,avatar_url:?string} */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        abort_unless(count($parts) === 3, 401, 'Login Google gagal. Coba lagi ya.');

        $header = $this->decode($parts[0]);
        $claims = $this->decode($parts[1]);
        $signature = $this->base64UrlDecode($parts[2]);
        abort_unless(is_array($header) && is_array($claims) && is_string($signature)
            && ($header['alg'] ?? null) === 'RS256' && is_string($header['kid'] ?? null),
            401, 'Login Google gagal. Coba lagi ya.');

        $clients = array_filter(array_map('trim', explode(',', (string) config('services.google_client_ids'))));
        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        abort_unless($clients !== []
            && $audiences !== [] && count(array_filter($audiences, 'is_string')) === count($audiences)
            && in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            && array_intersect($clients, $audiences) !== []
            && is_int($claims['exp'] ?? null) && $claims['exp'] > now()->timestamp
            && in_array($claims['email_verified'] ?? null, [true, 'true'], true)
            && is_string($claims['sub'] ?? null) && $claims['sub'] !== ''
            && strlen($claims['sub']) <= 255
            && is_string($claims['email'] ?? null) && filter_var($claims['email'], FILTER_VALIDATE_EMAIL),
            401, 'Login Google gagal. Coba lagi ya.');

        $key = collect($this->keys())->first(fn ($key): bool => is_array($key)
            && ($key['kid'] ?? null) === $header['kid']
            && ($key['kty'] ?? null) === 'RSA'
            && in_array($key['alg'] ?? 'RS256', ['RS256'], true)
            && is_string($key['n'] ?? null) && is_string($key['e'] ?? null));
        abort_unless($key !== null, 401, 'Login Google gagal. Coba lagi ya.');

        $modulus = $this->base64UrlDecode($key['n']);
        $exponent = $this->base64UrlDecode($key['e']);
        abort_unless($modulus !== false && $exponent !== false, 401, 'Login Google gagal. Coba lagi ya.');
        $rsa = $this->asn1(0x30, $this->integer($modulus).$this->integer($exponent));
        $pem = "-----BEGIN RSA PUBLIC KEY-----\n".chunk_split(base64_encode($rsa), 64, "\n")
            ."-----END RSA PUBLIC KEY-----\n";
        $publicKey = openssl_pkey_get_public($pem);
        abort_unless($publicKey !== false
            && openssl_verify($parts[0].'.'.$parts[1], $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1,
            401, 'Login Google gagal. Coba lagi ya.');

        return [
            'sub' => $claims['sub'],
            'email' => $claims['email'],
            'name' => mb_substr(is_string($claims['name'] ?? null) ? $claims['name'] : $claims['email'], 0, 255),
            'avatar_url' => is_string($claims['picture'] ?? null) ? $claims['picture'] : null,
        ];
    }

    private function keys(): array
    {
        $cached = Cache::get('customer:google:jwks');
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(5)->get(self::JWKS_URL);
            abort_unless($response->successful() && is_array($response->json('keys')), 401,
                'Login Google belum bisa dicek. Coba lagi ya.');
            $keys = $response->json('keys');
            $cacheControl = $response->header('Cache-Control', '');
            preg_match('/(?:^|,)\s*max-age=(\d+)/', $cacheControl, $matches);
            $ttl = isset($matches[1]) ? max(1, (int) $matches[1] - (int) $response->header('Age', 0)) : 300;
            Cache::put('customer:google:jwks', $keys, $ttl);

            return $keys;
        } catch (Throwable) {
            abort(401, 'Login Google belum bisa dicek. Coba lagi ya.');
        }
    }

    private function decode(string $part): ?array
    {
        $json = $this->base64UrlDecode($part);
        if ($json === false) {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function base64UrlDecode(string $part): string|false
    {
        if ($part === '' || preg_match('/[^A-Za-z0-9_-]/', $part)) {
            return false;
        }

        return base64_decode(strtr($part, '-_', '+/'), true);
    }

    private function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
            $bytes = "\0".$bytes;
        }

        return $this->asn1(0x02, $bytes);
    }

    private function asn1(int $tag, string $contents): string
    {
        $length = strlen($contents);
        if ($length < 128) {
            return chr($tag).chr($length).$contents;
        }
        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xFF).$bytes;
            $length >>= 8;
        }

        return chr($tag).chr(0x80 | strlen($bytes)).$bytes.$contents;
    }
}
