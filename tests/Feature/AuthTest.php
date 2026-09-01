<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * docs/04 §Auth, docs/02 §2. Covers: all 5 seeded roles can log in; a client-supplied `role`
 * is ignored; bad credentials return the exact Indonesian message; logout revokes only the
 * current token.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * @return array<string, array{0:string,1:string,2:string}>
     */
    public static function demoUsers(): array
    {
        return [
            'administrator' => ['+6281100000001', 'admin123', 'ADMINISTRATOR'],
            'finance' => ['+6281100000002', 'finance123', 'FINANCE'],
            'barista' => ['+6281100000003', 'barista123', 'BARISTA'],
            'rider' => ['+6281100000004', 'rider123', 'RIDER'],
            'staff' => ['+6281100000005', 'staff123', 'STAFF'],
        ];
    }

    #[DataProvider('demoUsers')]
    public function test_login_succeeds_for_seeded_demo_user_and_returns_correct_role(
        string $phone,
        string $password,
        string $expectedRole,
    ): void {
        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => $phone,
            'password' => $password,
            'device_name' => 'phpunit',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.role', $expectedRole)
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'user' => ['id', 'name', 'role', 'cart_code', 'cart_id', 'kitchen_name', 'kitchen_id'],
                ],
            ]);
    }

    public function test_login_ignores_client_supplied_role_and_normalises_local_phone_format(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => '081100000005', // local 08... format, server must normalise to +62
            'password' => 'staff123',
            'device_name' => 'phpunit',
            'role' => 'ADMINISTRATOR', // must be silently ignored — server decides the role
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.user.role', 'STAFF');
    }

    public function test_bad_password_returns_401_with_exact_indonesian_message(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => '+6281100000005',
            'password' => 'wrong-password',
            'device_name' => 'phpunit',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Nomor HP atau kata sandi salah.');
    }

    public function test_unknown_phone_returns_the_same_generic_message_as_bad_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => '+6281199999999',
            'password' => 'whatever',
            'device_name' => 'phpunit',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Nomor HP atau kata sandi salah.');
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::query()->where('phone_e164', '+6281100000005')->firstOrFail();
        $tokenA = $user->createToken('device-a')->plainTextToken;
        $tokenB = $user->createToken('device-b')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(204);

        $remaining = $user->tokens()->get();

        $this->assertCount(1, $remaining);
        $this->assertSame('device-b', $remaining->first()->name);
    }
}
