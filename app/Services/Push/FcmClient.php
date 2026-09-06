<?php

namespace App\Services\Push;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firebase Cloud Messaging, HTTP v1 API — with no SDK.
 *
 * The whole exchange is two HTTP calls: a service-account JWT swapped for a short-lived OAuth2
 * bearer token, then one `messages:send` per device. Both are small enough that pulling in the
 * Google auth library (and its dozen transitive packages) onto a shared host with no Composer
 * memory to spare was the wrong trade. `openssl_sign` does RS256 natively.
 *
 * Configuration (config/services.php → `fcm`):
 *   FCM_PROJECT_ID        the Firebase project id (the `project_id` field of the JSON below)
 *   FCM_CREDENTIALS_PATH  absolute path to the service-account JSON downloaded from the Firebase
 *                         console (Project settings → Service accounts → Generate new private key).
 *                         Keep it OUTSIDE public/ — storage/app/private/ is the intended spot.
 *
 * When either is missing, `isConfigured()` is false and PushNotifier degrades to a silent no-op,
 * so the rest of the API never depends on Firebase being set up.
 */
class FcmClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_CACHE_KEY = 'fcm.oauth.access_token';

    /** Google issues tokens for 3600 s; cache for less so we never send one that just expired. */
    private const TOKEN_CACHE_SECONDS = 50 * 60;

    /** @var array{client_email:string, private_key:string, token_uri:string}|null */
    private ?array $credentials = null;

    private ?string $projectId = null;

    public function isConfigured(): bool
    {
        return $this->projectId() !== null && $this->credentials() !== null;
    }

    /**
     * Send the same notification to many devices concurrently.
     *
     * @param  array<int,string>  $tokens
     * @param  array<string,string>  $data
     * @return array<string,FcmResult> keyed by device token
     */
    public function sendMany(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));

        if ($tokens === []) {
            return [];
        }

        $accessToken = $this->accessToken();
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId()}/messages:send";
        $timeout = (int) config('services.fcm.timeout', 5);

        $responses = Http::pool(function (Pool $pool) use ($tokens, $accessToken, $url, $timeout, $title, $body, $data): void {
            foreach ($tokens as $token) {
                $pool->as($token)
                    ->withToken($accessToken)
                    ->acceptJson()
                    ->timeout($timeout)
                    ->connectTimeout(3)
                    ->post($url, ['message' => $this->message($token, $title, $body, $data)]);
            }
        });

        $results = [];

        foreach ($tokens as $token) {
            $results[$token] = $this->classify($responses[$token] ?? null, $token);
        }

        // One expired-token surprise (clock skew, cache flushed mid-flight) is retried once with a
        // fresh bearer. Anything still failing after that is reported as-is.
        $unauthorized = array_keys(array_filter($results, fn (FcmResult $r) => $r === FcmResult::Unauthorized));

        if ($unauthorized !== []) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $retry = $this->sendManyOnce($unauthorized, $title, $body, $data);

            foreach ($retry as $token => $result) {
                $results[$token] = $result === FcmResult::Unauthorized ? FcmResult::Failed : $result;
            }
        }

        return $results;
    }

    /**
     * @param  array<int,string>  $tokens
     * @param  array<string,string>  $data
     * @return array<string,FcmResult>
     */
    private function sendManyOnce(array $tokens, string $title, string $body, array $data): array
    {
        $accessToken = $this->accessToken();
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId()}/messages:send";
        $timeout = (int) config('services.fcm.timeout', 5);

        $responses = Http::pool(function (Pool $pool) use ($tokens, $accessToken, $url, $timeout, $title, $body, $data): void {
            foreach ($tokens as $token) {
                $pool->as($token)
                    ->withToken($accessToken)
                    ->acceptJson()
                    ->timeout($timeout)
                    ->connectTimeout(3)
                    ->post($url, ['message' => $this->message($token, $title, $body, $data)]);
            }
        });

        $results = [];
        foreach ($tokens as $token) {
            $results[$token] = $this->classify($responses[$token] ?? null, $token);
        }

        return $results;
    }

    /**
     * @param  array<string,string>  $data
     * @return array<string,mixed>
     */
    private function message(string $token, string $title, string $body, array $data): array
    {
        // FCM rejects non-string data values, so everything is coerced here rather than trusting
        // every caller to remember.
        $stringData = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $stringData[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        return [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => $stringData,
            'android' => [
                // High priority wakes a dozing device; these are operational events people act on
                // within minutes, not a marketing digest.
                'priority' => 'high',
                'notification' => [
                    'channel_id' => 'default',
                    'sound' => 'default',
                    'default_vibrate_timings' => true,
                ],
            ],
        ];
    }

    private function classify(Response|\Throwable|null $response, string $token): FcmResult
    {
        if ($response === null || $response instanceof \Throwable) {
            Log::warning('fcm: transport failure', [
                'token_suffix' => substr($token, -8),
                'error' => $response instanceof \Throwable ? $response->getMessage() : 'no response',
            ]);

            return FcmResult::Failed;
        }

        if ($response->successful()) {
            return FcmResult::Sent;
        }

        if ($response->status() === 401) {
            return FcmResult::Unauthorized;
        }

        $errorCode = null;
        foreach ((array) $response->json('error.details', []) as $detail) {
            if (is_array($detail) && isset($detail['errorCode'])) {
                $errorCode = $detail['errorCode'];
            }
        }
        $status = $response->json('error.status');

        // UNREGISTERED: app uninstalled or token rotated. INVALID_ARGUMENT on a 400 with a
        // registration-token message: the stored token is malformed. Both mean "stop sending to
        // this row", which is the only outcome the caller acts on.
        if ($errorCode === 'UNREGISTERED' || $status === 'NOT_FOUND') {
            return FcmResult::Unregistered;
        }

        if ($response->status() === 400 && $status === 'INVALID_ARGUMENT'
            && str_contains(strtolower((string) $response->json('error.message')), 'registration token')) {
            return FcmResult::Unregistered;
        }

        Log::warning('fcm: send rejected', [
            'token_suffix' => substr($token, -8),
            'http' => $response->status(),
            'status' => $status,
            'error_code' => $errorCode,
            'message' => $response->json('error.message'),
        ]);

        return FcmResult::Failed;
    }

    private function accessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $credentials = $this->credentials() ?? throw new RuntimeException('FCM credentials are not configured.');

        $now = time();
        $jwt = $this->encodeJwt([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $credentials['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ], $credentials['private_key']);

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.fcm.timeout', 5))
                ->connectTimeout(3)
                ->post($credentials['token_uri'], [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('FCM OAuth token endpoint unreachable: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('FCM OAuth token exchange failed: HTTP '.$response->status().' '.$response->body());
        }

        $token = $response->json('access_token');
        $ttl = min((int) $response->json('expires_in', 3600) - 300, self::TOKEN_CACHE_SECONDS);
        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, $ttl));

        return $token;
    }

    /** @param array<string,mixed> $claims */
    private function encodeJwt(array $claims, string $privateKeyPem): string
    {
        $segments = [
            $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64url(json_encode($claims)),
        ];

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false) {
            throw new RuntimeException('FCM service-account private key could not be read.');
        }

        $signature = '';
        if (! openssl_sign(implode('.', $segments), $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('FCM JWT signing failed.');
        }

        $segments[] = $this->base64url($signature);

        return implode('.', $segments);
    }

    private function base64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function projectId(): ?string
    {
        if ($this->projectId === null) {
            $configured = trim((string) config('services.fcm.project_id', ''));
            $this->projectId = $configured !== '' ? $configured : null;
        }

        return $this->projectId;
    }

    /** @return array{client_email:string, private_key:string, token_uri:string}|null */
    private function credentials(): ?array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $path = (string) config('services.fcm.credentials_path', '');
        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            Log::error('fcm: credentials file is not a service-account JSON', ['path' => $path]);

            return null;
        }

        return $this->credentials = [
            'client_email' => (string) $json['client_email'],
            'private_key' => (string) $json['private_key'],
            'token_uri' => (string) ($json['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        ];
    }
}
