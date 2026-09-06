<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The PIN sign-in (docs/04 §Auth).
 *
 * The PIN REPLACES the password once it exists — that exclusivity is the behaviour most of these
 * tests pin down, alongside what a PIN must never become: a way to escalate from a borrowed
 * unlocked phone, a replacement for the delivery PIN, or a credential an attacker can grind
 * through six digits at a time.
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

    private function loginWithPassword(?string $password = null)
    {
        return $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/auth/login', [
                'phone' => $this->user->phone_e164,
                'password' => $password ?? self::PASSWORD,
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
        // A real login PIN is set first, so the delivery PIN is tested against an account that
        // genuinely accepts PIN sign-in — otherwise the refusal would only prove that no PIN
        // exists, which is a different rule.
        $this->setPin('482915')->assertOk();

        $this->loginWithPin('123456')->assertStatus(401);
    }

    public function test_it_rejects_easily_guessed_pins(): void
    {
        $this->setPin('111111')->assertStatus(422);
        $this->setPin('123456')->assertStatus(422);
        $this->setPin('654321')->assertStatus(422);

        $this->assertNull($this->user->fresh()->login_pin_hash);
    }

    /**
     * A PIN-less account answers 409 PIN_NOT_SET, not the generic 401 — the app needs to know to
     * show the password form rather than "PIN salah" forever after an administrator reset.
     */
    public function test_an_account_without_a_pin_is_told_to_use_the_password(): void
    {
        $this->loginWithPin('482915')
            ->assertStatus(409)
            ->assertJsonPath('code', 'PIN_NOT_SET');
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

    public function test_a_successful_pin_login_clears_earlier_failures(): void
    {
        $this->setPin('482915')->assertOk();

        $this->loginWithPin('000001')->assertStatus(401);
        $this->loginWithPin('482915')->assertOk();

        $this->assertSame(0, $this->user->fresh()->login_pin_failures);
    }

    // ── Exclusivity: the PIN replaces the password ───────────────────────────

    /** The core rule: once a PIN exists, the password no longer opens the app. */
    public function test_the_password_route_is_closed_once_a_pin_exists(): void
    {
        $this->setPin('482915')->assertOk();

        $this->loginWithPassword()
            ->assertStatus(409)
            ->assertJsonPath('code', 'PIN_REQUIRED');
    }

    /**
     * The 409 must only ever follow a CORRECT password. Answering it for a wrong password would
     * turn the login route into a free "does this account have a PIN" oracle.
     */
    public function test_a_wrong_password_still_returns_the_generic_401_when_a_pin_exists(): void
    {
        $this->setPin('482915')->assertOk();

        $this->loginWithPassword('wrong-password')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Nomor HP atau kata sandi salah.');
    }

    public function test_the_password_route_reopens_after_the_pin_is_removed(): void
    {
        $this->setPin('482915')->assertOk();
        $this->loginWithPassword()->assertStatus(409);

        // The token was revoked by setting the PIN, so a fresh session is needed to remove it.
        $token = $this->loginWithPin('482915')->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json')
            ->deleteJson('/api/v1/me/login-pin', ['password' => self::PASSWORD])
            ->assertNoContent();

        $this->loginWithPassword()->assertOk();
    }

    public function test_removing_a_pin_requires_the_account_password(): void
    {
        $this->setPin('482915')->assertOk();
        $token = $this->loginWithPin('482915')->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json')
            ->deleteJson('/api/v1/me/login-pin', ['password' => 'wrong-password'])
            ->assertStatus(422);

        $this->assertNotNull($this->user->fresh()->login_pin_hash);
    }

    // ── Sessions ────────────────────────────────────────────────────────────

    /**
     * Creating a PIN signs every device out, including the one that created it. Without this a
     * device could keep a live session on a credential path that no longer exists.
     */
    public function test_creating_a_pin_revokes_every_session(): void
    {
        $this->user->createToken('other-device');
        $this->assertSame(1, $this->user->tokens()->count());

        $this->setPin('482915')
            ->assertOk()
            ->assertJsonPath('data.sessions_revoked', true);

        $this->assertSame(0, $this->user->fresh()->tokens()->count());
    }

    /**
     * End to end over the wire, with no `actingAs` anywhere: that helper binds a user to the guard
     * for the rest of the test, which would authenticate the final request even with a revoked
     * token and turn this into a test that can never fail.
     */
    public function test_a_token_issued_before_the_pin_stops_working_after_it(): void
    {
        $token = $this->loginWithPassword()->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')
            ->assertOk();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/me/login-pin', ['pin' => '482915', 'password' => self::PASSWORD])
            ->assertOk();

        $this->assertSame(0, $this->user->tokens()->count());

        // Every request in one test shares this process's container, and the auth guard caches the
        // user it resolved on the first of them. A real phone makes its next call against a fresh
        // process; forgetting the guards is how the test reproduces that rather than re-asserting
        // a user that was resolved before the revocation happened.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->withHeader('Accept', 'application/json')
            ->getJson('/api/v1/me')
            ->assertStatus(401);
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
