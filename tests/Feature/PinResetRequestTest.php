<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\PinResetRequest;
use App\Models\User;
use App\Services\PinResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * "Lupa PIN" — the only exit from an account whose PIN has been forgotten (docs/04 §Auth).
 *
 * Two properties matter more than the happy path: the endpoint must reveal nothing to an
 * unauthenticated caller, and resolving must leave the account in a state the person can actually
 * sign into — new password AND cleared PIN AND revoked sessions, never a subset.
 */
class PinResetRequestTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'secret123';

    private User $user;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->role(Role::STAFF)->create([
            'password' => Hash::make(self::PASSWORD),
            'login_pin_hash' => Hash::make('482915'),
        ]);

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
    }

    private function submit(array $overrides = [])
    {
        return $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/auth/pin-reset-requests', array_merge([
                'phone' => $this->user->phone_e164,
                'email' => 'staff@example.com',
                'password' => self::PASSWORD,
            ], $overrides));
    }

    public function test_a_request_is_recorded_and_marks_a_matching_password_as_verified(): void
    {
        $this->submit()->assertStatus(202)->assertJsonPath('data.status', 'ACCEPTED');

        $reset = PinResetRequest::query()->firstOrFail();

        $this->assertSame($this->user->id, $reset->user_id);
        $this->assertSame('staff@example.com', $reset->email);
        $this->assertTrue($reset->password_verified);
        $this->assertSame(PinResetRequest::STATUS_PENDING, $reset->status);
    }

    /** A wrong password still reaches a human — it is recorded as unverified, not refused. */
    public function test_a_wrong_password_is_recorded_as_unverified_rather_than_rejected(): void
    {
        $this->submit(['password' => 'not-my-password'])->assertStatus(202);

        $this->assertFalse(PinResetRequest::query()->firstOrFail()->password_verified);
    }

    /** The caller is not signed in, so the response must not distinguish real from fake accounts. */
    public function test_an_unknown_phone_gets_the_same_answer_and_creates_nothing(): void
    {
        $this->submit(['phone' => '081199999999'])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'ACCEPTED');

        $this->assertSame(0, PinResetRequest::query()->count());
    }

    public function test_an_inactive_account_creates_nothing(): void
    {
        $this->user->forceFill(['is_active' => false])->save();

        $this->submit()->assertStatus(202);

        $this->assertSame(0, PinResetRequest::query()->count());
    }

    /** One open request per person: a repeat refreshes it instead of flooding the admin queue. */
    public function test_a_repeat_request_updates_the_pending_row_instead_of_adding_one(): void
    {
        $this->submit(['password' => 'wrong-first-time'])->assertStatus(202);
        $this->submit(['email' => 'baru@example.com'])->assertStatus(202);

        $this->assertSame(1, PinResetRequest::query()->count());

        $reset = PinResetRequest::query()->firstOrFail();
        $this->assertSame(2, $reset->attempts);
        $this->assertSame('baru@example.com', $reset->email);
        // Verified once is verified: the second attempt proved the password.
        $this->assertTrue($reset->password_verified);
    }

    public function test_validation_requires_phone_email_and_password(): void
    {
        $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/auth/pin-reset-requests', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone', 'email', 'password']);

        $this->submit(['email' => 'bukan-email'])->assertStatus(422);
    }

    // ── Resolution, as performed by the panel action ─────────────────────────

    public function test_resolving_sets_the_new_password_clears_the_pin_and_revokes_sessions(): void
    {
        $this->submit()->assertStatus(202);
        $reset = PinResetRequest::query()->firstOrFail();

        $this->user->createToken('phone-in-the-field');
        $this->assertSame(1, $this->user->tokens()->count());

        app(PinResetService::class)->resolve($reset, $this->admin, 'SandiBaru123', 'diverifikasi via telepon');

        $fresh = $this->user->fresh();
        $this->assertTrue(Hash::check('SandiBaru123', $fresh->password));
        $this->assertNull($fresh->login_pin_hash);
        $this->assertSame(0, $fresh->login_pin_failures);
        $this->assertNull($fresh->login_pin_locked_until);
        $this->assertSame(0, $fresh->tokens()->count());

        $reset->refresh();
        $this->assertSame(PinResetRequest::STATUS_RESOLVED, $reset->status);
        $this->assertSame($this->admin->id, $reset->resolved_by);
        $this->assertNotNull($reset->resolved_at);
    }

    /** The point of the whole flow: the user can sign in again, with the password. */
    public function test_the_user_can_sign_in_with_the_new_password_after_resolution(): void
    {
        $this->submit()->assertStatus(202);
        app(PinResetService::class)->resolve(PinResetRequest::query()->firstOrFail(), $this->admin, 'SandiBaru123');

        $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/auth/login', [
                'phone' => $this->user->phone_e164,
                'password' => 'SandiBaru123',
                'device_name' => 'android',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.has_login_pin', false);
    }

    /** Two administrators on the queue at once must not both set a password. */
    public function test_resolving_an_already_resolved_request_changes_nothing(): void
    {
        $this->submit()->assertStatus(202);
        $reset = PinResetRequest::query()->firstOrFail();

        $service = app(PinResetService::class);
        $service->resolve($reset, $this->admin, 'SandiPertama1');
        $service->resolve($reset->fresh(), $this->admin, 'SandiKedua22');

        $this->assertTrue(Hash::check('SandiPertama1', $this->user->fresh()->password));
    }

    public function test_rejecting_leaves_every_credential_untouched(): void
    {
        $this->submit()->assertStatus(202);
        $reset = PinResetRequest::query()->firstOrFail();

        app(PinResetService::class)->reject($reset, $this->admin, 'identitas tidak dapat dipastikan');

        $fresh = $this->user->fresh();
        $this->assertTrue(Hash::check(self::PASSWORD, $fresh->password));
        $this->assertNotNull($fresh->login_pin_hash);

        $reset->refresh();
        $this->assertSame(PinResetRequest::STATUS_REJECTED, $reset->status);
        $this->assertSame('identitas tidak dapat dipastikan', $reset->resolution_note);
    }
}
