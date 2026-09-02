<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The optional PIN sign-in (docs/04 §Auth).
 *
 * The tests that matter here are the ones about what a PIN must NOT become: a way to escalate
 * from a borrowed unlocked phone, a replacement for the delivery PIN, or a credential an attacker
 * can grind through six digits at a time.
 */
class LoginPinTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret123';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->role(Role::STAFF)->create([
            'password' => Hash::make(self::PASSWORD),
            'pin_hash' => Hash::make('123456'), // the delivery PIN — must stay unrelated
        ]);
    }

    private function setPin(string $pin, ?string $password = null)
    {
        return $this->actingAs($this->user)
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/me/login-pin', [
                'pin' => $pin,
                'password' => $password ?? self::PASSWORD,
            ]);
    }

    private function loginWithPin(string $pin, ?string $phone = null)
    {
        return $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/auth/login-pin', [
                'phone' => $phone ?? $this->user->phone_e164,
                'pin' => $pin,
                'device_name' => 'android',
            ]);
    }

    public function test_a_user_can_set_a_pin_and_sign_in_with_it(): void
    {
        $this->setPin('482915')->assertOk();

        $response = $this->loginWithPin('482915');

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertSame($this->user->id, $response->json('data.user.id'));
    }

    /** A token can be lifted from an unlocked phone; the password cannot. */
    public function test_setting_a_pin_requires_the_account_password(): void
    {
        $this->setPin('482915', 'wrong-password')->assertStatus(422);

        $this->assertNull($this->user->fresh()->login_pin_hash);
    }

    /**
     * The delivery PIN (R13/E7) is proof of presence held by the person being delivered TO. If it
     * doubled as a login credential, every Rider who ever took a PIN-fallback delivery could sign
     * in as that staff member.
     */
    public function test_the_delivery_pin_is_not_accepted_as_a_login_pin(): void
    {
        $this->loginWithPin('123456')->assertStatus(401);
    }

    public function test_it_rejects_easily_guessed_pins(): void
    {
        $this->setPin('111111')->assertStatus(422);
        $this->setPin('123456')->assertStatus(422);
        $this->setPin('654321')->assertStatus(422);

        $this->assertNull($this->user->fresh()->login_pin_hash);
    }

    public function test_an_account_without_a_pin_cannot_sign_in_with_one(): void
    {
        $this->loginWithPin('482915')->assertStatus(401);
    }

    /** Six digits is a small space, so wrong attempts must cost the attacker time. */
    public function test_repeated_wrong_pins_lock_the_pin_route(): void
    {
        $this->setPin('482915')->assertOk();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->loginWithPin('000001')->assertStatus(401);
        }

        // Even the CORRECT pin is refused while the lockout holds.
        $this->loginWithPin('482915')->assertStatus(429);
    }

    /** The lockout must never shut a user out of their own account entirely. */
    public function test_the_password_route_still_works_while_the_pin_is_locked(): void
    {
        $this->setPin('482915')->assertOk();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->loginWithPin('000001')->assertStatus(401);
        }

        $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/auth/login', [
                'phone' => $this->user->phone_e164,
                'password' => self::PASSWORD,
                'device_name' => 'android',
            ])
            ->assertOk();
    }

    public function test_a_successful_pin_login_clears_earlier_failures(): void
    {
        $this->setPin('482915')->assertOk();

        $this->loginWithPin('000001')->assertStatus(401);
        $this->loginWithPin('482915')->assertOk();

        $this->assertSame(0, $this->user->fresh()->login_pin_failures);
    }

    public function test_a_user_can_remove_their_pin(): void
    {
        $this->setPin('482915')->assertOk();

        $this->actingAs($this->user)
            ->withHeader('Accept', 'application/json')
            ->deleteJson('/api/v1/me/login-pin')
            ->assertNoContent();

        $this->loginWithPin('482915')->assertStatus(401);
    }

    public function test_me_reports_whether_a_pin_exists_but_never_the_pin(): void
    {
        $before = $this->actingAs($this->user)->getJson('/api/v1/me');
        $before->assertOk();
        $this->assertFalse($before->json('data.has_login_pin'));

        $this->setPin('482915')->assertOk();

        $after = $this->actingAs($this->user)->getJson('/api/v1/me');
        $this->assertTrue($after->json('data.has_login_pin'));
        $after->assertJsonMissingPath('data.login_pin_hash');
        $after->assertJsonMissingPath('data.pin_hash');
    }
}
