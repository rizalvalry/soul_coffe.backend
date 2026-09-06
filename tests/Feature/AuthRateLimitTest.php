<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The three unauthenticated auth routes must not share one rate-limit bucket.
 *
 * This is not hypothetical: with the inline `throttle:n,m` form they did, because
 * `ThrottleRequests` keys on the route's domain and the client IP and nothing else. Three failed
 * password attempts exhausted the "lupa PIN" allowance and answered 429 to a user who had never
 * touched that endpoint. Found by probing the deployed API, so it is pinned here.
 */
class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->role(Role::STAFF)->create([
            'password' => Hash::make('secret123'),
        ]);
    }

    private function attemptLogin(string $phone)
    {
        return $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/auth/login', [
                'phone' => $phone,
                'password' => 'wrong-password',
                'device_name' => 'phpunit',
            ]);
    }

    private function attemptReset(string $phone)
    {
        return $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/auth/pin-reset-requests', [
                'phone' => $phone,
                'email' => 'staff@example.com',
                'password' => 'secret123',
            ]);
    }

    public function test_failed_logins_do_not_consume_the_pin_reset_allowance(): void
    {
        $phone = $this->user->phone_e164;

        // Enough to blow past the reset route's limit of three, had they shared a counter.
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $this->attemptLogin($phone)->assertStatus(401);
        }

        $this->attemptReset($phone)->assertStatus(202);
    }

    /** One account's attempts must not throttle a different account. */
    public function test_one_accounts_attempts_do_not_throttle_another(): void
    {
        $other = User::factory()->role(Role::BARISTA)->create();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->attemptReset($this->user->phone_e164)->assertStatus(202);
        }

        $this->attemptReset($this->user->phone_e164)->assertStatus(429);

        $this->attemptReset($other->phone_e164)->assertStatus(202);
    }

    /**
     * The three formats a staff member may type all normalise to one account, so they must all
     * count against the same allowance — otherwise the limit is three times what it says.
     */
    public function test_alternating_phone_formats_does_not_reset_the_counter(): void
    {
        $local = '081100000123';
        User::factory()->role(Role::STAFF)->create(['phone_e164' => $local]);

        $this->attemptReset($local)->assertStatus(202);
        $this->attemptReset('6281100000123')->assertStatus(202);
        $this->attemptReset('+6281100000123')->assertStatus(202);

        $this->attemptReset($local)->assertStatus(429);
    }
}
