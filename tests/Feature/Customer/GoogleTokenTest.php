<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Tests\TestCase;

class GoogleTokenTest extends TestCase
{
    use RefreshDatabase;

    private $privateKey;

    private string $opensslConfig;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_client_ids' => 'accepted-client,second-client']);
        Cache::forget('customer:google:jwks');
        $this->opensslConfig = tempnam(sys_get_temp_dir(), 'customer-openssl-');
        file_put_contents($this->opensslConfig, "[req]\ndistinguished_name=req_distinguished_name\n[req_distinguished_name]\n");
        $this->privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'config' => $this->opensslConfig]);
        $rsa = openssl_pkey_get_details($this->privateKey)['rsa'];
        Http::fake(['https://www.googleapis.com/oauth2/v3/certs' => Http::response([
            'keys' => [['kid' => 'local-key', 'kty' => 'RSA', 'alg' => 'RS256',
                'n' => $this->base64Url($rsa['n']), 'e' => $this->base64Url($rsa['e'])]],
        ], 200, ['Cache-Control' => 'public, max-age=3600'])]);
    }

    protected function tearDown(): void
    {
        unlink($this->opensslConfig);
        parent::tearDown();
    }

    private function token(array $claims = [], bool $badSignature = false): string
    {
        $header = $this->encode(['alg' => 'RS256', 'kid' => 'local-key']);
        $body = $this->encode(array_replace([
            'sub' => 'google-123',
            'email' => 'pelanggan@example.test',
            'email_verified' => true,
            'name' => 'Pelanggan Uji',
            'iss' => 'https://accounts.google.com',
            'aud' => 'accepted-client',
            'exp' => time() + 3600,
        ], $claims));
        $signingKey = $badSignature
            ? openssl_pkey_new(['private_key_bits' => 2048, 'config' => $this->opensslConfig])
            : $this->privateKey;
        openssl_sign($header.'.'.$body, $signature, $signingKey, OPENSSL_ALGO_SHA256);

        return $header.'.'.$body.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    private function encode(array $value): string
    {
        return $this->base64Url(json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function login(string $token)
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/customer/auth/google', ['id_token' => $token]);
    }

    public function test_valid_google_token_reuses_google_sub_even_when_email_changes(): void
    {
        $first = $this->login($this->token())->assertOk()->json('data.customer.id');
        $second = $this->login($this->token(['email' => 'baru@example.test']))
            ->assertOk()->json('data.customer.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('customers', 1);
        $this->assertSame('baru@example.test', Customer::first()->email);
        Http::assertSentCount(1); // JWKS follows its cache header.
    }

    public function test_google_login_replays_only_a_live_token_and_keeps_it_encrypted_in_cache(): void
    {
        $googleToken = $this->token();
        $key = (string) Str::uuid();
        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/auth/google', ['id_token' => $googleToken])->assertOk();
        $second = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/auth/google', ['id_token' => $googleToken])->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('data.token'), $second->json('data.token'));
        $stored = Cache::get('customer:google:idem:'.hash('sha256', 'google-123|'.$key));
        $this->assertArrayHasKey('encrypted_token', $stored);
        $this->assertStringNotContainsString($first->json('data.token'), json_encode($stored));
        $this->assertStringNotContainsString('pelanggan@example.test', json_encode($stored));
    }

    public function test_invalid_google_claims_or_signature_get_401(): void
    {
        foreach ([
            [$this->token(['email_verified' => false]), false],
            [$this->token(['aud' => 'wrong-client']), false],
            [$this->token(['iss' => 'https://evil.example']), false],
            [$this->token(['exp' => time() - 1]), false],
            [$this->token([], true), false],
        ] as [$token]) {
            $this->login($token)->assertUnauthorized();
        }
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_disabled_customer_cannot_login_again(): void
    {
        $googleToken = $this->token();
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/auth/google', ['id_token' => $googleToken])->assertOk();
        Customer::first()->update(['status' => 'DISABLED']);
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/customer/auth/google', ['id_token' => $googleToken])->assertForbidden();
        $this->login($googleToken)->assertForbidden();
    }

    public function test_google_token_is_absent_from_test_log_handler(): void
    {
        $handler = new TestHandler;
        Log::getLogger()->pushHandler($handler);
        $googleToken = $this->token();
        $this->login($googleToken)->assertOk();
        foreach ($handler->getRecords() as $record) {
            $this->assertStringNotContainsString($googleToken, (string) $record->message);
            $this->assertStringNotContainsString($googleToken, json_encode($record->context));
        }
    }

    public function test_delete_anonymizes_revokes_and_allows_a_clean_new_account(): void
    {
        $googleToken = $this->token();
        $loginKey = (string) Str::uuid();
        $token = $this->withHeader('Idempotency-Key', $loginKey)
            ->postJson('/api/v1/customer/auth/google', ['id_token' => $googleToken])
            ->assertOk()->json('data.token');
        $original = Customer::first();
        $key = (string) Str::uuid();
        $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->deleteJson('/api/v1/customer/me')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/customer/me')->assertUnauthorized();
        $this->withToken($token)->withHeader('Idempotency-Key', $key)
            ->deleteJson('/api/v1/customer/me')->assertOk();

        $deleted = Customer::withTrashed()->findOrFail($original->id);
        $this->assertNull($deleted->email);
        $this->assertNull($deleted->name);
        $this->assertNull($deleted->avatar_url);
        $this->assertNotSame('google-123', $deleted->google_sub);
        $this->assertNotNull($deleted->deleted_at);
        $newId = $this->withHeaders(['Authorization' => '', 'Idempotency-Key' => $loginKey])
            ->postJson('/api/v1/customer/auth/google', ['id_token' => $googleToken])
            ->assertOk()->json('data.customer.id');
        $this->assertNotSame($original->id, $newId);
    }

    public function test_privacy_acceptance_and_logout_are_idempotent(): void
    {
        $token = $this->login($this->token())->assertOk()->json('data.token');
        $privacyKey = (string) Str::uuid();
        $accepted = $this->withToken($token)->withHeader('Idempotency-Key', $privacyKey)
            ->postJson('/api/v1/customer/privacy/accept')->assertOk()->json('data.privacy_accepted_at');
        $this->assertNotNull($accepted);
        $this->withToken($token)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/customer/privacy/accept')->assertOk()
            ->assertJsonPath('data.privacy_accepted_at', $accepted);

        $logoutKey = (string) Str::uuid();
        $this->withToken($token)->withHeader('Idempotency-Key', $logoutKey)
            ->postJson('/api/v1/customer/auth/logout')->assertOk();
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/v1/customer/me')->assertUnauthorized();
        $this->withToken($token)->withHeader('Idempotency-Key', $logoutKey)
            ->postJson('/api/v1/customer/auth/logout')->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');
    }

    public function test_google_login_is_limited_per_source_and_identity(): void
    {
        $token = $this->token();
        for ($i = 0; $i < 10; $i++) {
            $this->login($token)->assertOk();
        }
        $this->login($token)->assertStatus(429);
        $this->login($this->token(['sub' => 'another-google-sub']))->assertOk();
    }
}
