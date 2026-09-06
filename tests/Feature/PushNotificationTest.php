<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Services\EventPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Device registration and the push leg of the event pipeline.
 *
 * FCM is faked at the HTTP layer rather than mocked at the client: the request Google actually
 * receives is the thing worth asserting, and a mocked client would keep passing if the message
 * body drifted out of shape.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'fcm-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = User::factory()->role(Role::STAFF)->create();
    }

    /** Point the client at a fake project so isConfigured() is true inside the test. */
    private function configureFcm(): void
    {
        $credentials = storage_path('framework/testing/fcm-service-account.json');
        @mkdir(dirname($credentials), 0o777, true);

        // A throwaway RSA key committed under tests/Fixtures. The OAuth exchange is faked below,
        // but FcmClient still signs a real JWT before it gets there, so openssl needs a real key.
        // It is generated once and checked in rather than made here: openssl_pkey_new() needs an
        // openssl.cnf that a bare PHP-on-Windows install does not have, and a test that only runs
        // on machines with one configured is a test that stops running.
        file_put_contents($credentials, json_encode([
            'client_email' => 'test@example.iam.gserviceaccount.com',
            'private_key' => file_get_contents(base_path('tests/Fixtures/fcm-test-key.pem')),
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config([
            'services.fcm.project_id' => 'soul-test',
            'services.fcm.credentials_path' => $credentials,
        ]);
    }

    // ── Registration ────────────────────────────────────────────────────────

    public function test_a_device_registers_and_re_registration_is_not_a_duplicate(): void
    {
        $this->actingAs($this->staff)
            ->postJson('/api/v1/me/devices', ['token' => self::TOKEN, 'device_name' => 'Redmi 9A'])
            ->assertStatus(201);

        $this->actingAs($this->staff)
            ->postJson('/api/v1/me/devices', ['token' => self::TOKEN, 'device_name' => 'Redmi 9A'])
            ->assertStatus(200);

        $this->assertSame(1, DevicePushToken::query()->count());
    }

    /**
     * Shared handsets are normal here. The token must follow whoever is signed in now, or the
     * previous user's events keep arriving in someone else's pocket.
     */
    public function test_registering_the_same_token_as_another_user_moves_it(): void
    {
        $other = User::factory()->role(Role::BARISTA)->create();

        $this->actingAs($this->staff)->postJson('/api/v1/me/devices', ['token' => self::TOKEN])->assertStatus(201);
        $this->actingAs($other)->postJson('/api/v1/me/devices', ['token' => self::TOKEN])->assertStatus(200);

        $this->assertSame(1, DevicePushToken::query()->count());
        $this->assertSame($other->id, DevicePushToken::query()->value('user_id'));
    }

    public function test_a_user_cannot_delete_another_users_device(): void
    {
        $other = User::factory()->role(Role::BARISTA)->create();

        $this->actingAs($this->staff)->postJson('/api/v1/me/devices', ['token' => self::TOKEN])->assertStatus(201);

        $this->actingAs($other)
            ->deleteJson('/api/v1/me/devices', ['token' => self::TOKEN])
            ->assertNoContent();

        $this->assertSame(1, DevicePushToken::query()->count());

        $this->actingAs($this->staff)
            ->deleteJson('/api/v1/me/devices', ['token' => self::TOKEN])
            ->assertNoContent();

        $this->assertSame(0, DevicePushToken::query()->count());
    }

    public function test_registration_requires_authentication(): void
    {
        $this->withHeader('Accept', 'application/json')
            ->postJson('/api/v1/me/devices', ['token' => self::TOKEN])
            ->assertStatus(401);
    }

    // ── Delivery ────────────────────────────────────────────────────────────

    /**
     * Registers the staff member's device WITHOUT signing in as them.
     *
     * Going through `POST /me/devices` here would authenticate the staff member for the rest of
     * the test, and EventPublisher excludes the actor from its own push — so the delivery tests
     * below would assert nothing while appearing to pass. The endpoint itself is covered above.
     */
    private function registerStaffDevice(string $token = self::TOKEN): void
    {
        DevicePushToken::query()->create([
            'user_id' => $this->staff->id,
            'token' => $token,
            'platform' => 'android',
            'device_name' => 'Redmi 9A',
            'last_seen_at' => now(),
        ]);
    }

    public function test_publishing_an_event_sends_a_push_to_the_recipients_device(): void
    {
        $this->configureFcm();
        $this->registerStaffDevice();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/soul-test/messages/1']),
        ]);

        app(EventPublisher::class)->publish(
            'RefillRequestApproved',
            'Request disetujui',
            'REF-001 · 20 cups',
            ["user.{$this->staff->id}"],
            [$this->staff->id],
        );

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'fcm.googleapis.com')) {
                return false;
            }

            $message = $request->data()['message'];

            return $message['token'] === self::TOKEN
                && $message['notification']['title'] === 'Request disetujui'
                && $message['notification']['body'] === 'REF-001 · 20 cups'
                && $message['data']['type'] === 'RefillRequestApproved'
                && $message['android']['priority'] === 'high'
                // The dedupe key the app uses to ignore the socket copy of this same event (E15).
                && ! empty($message['data']['event_id']);
        });
    }

    /** A dead token is deleted, so the next event does not pay for it again. */
    public function test_an_unregistered_token_is_deleted(): void
    {
        $this->configureFcm();
        $this->registerStaffDevice();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response([
                'error' => [
                    'status' => 'NOT_FOUND',
                    'message' => 'Requested entity was not found.',
                    'details' => [['errorCode' => 'UNREGISTERED']],
                ],
            ], 404),
        ]);

        app(EventPublisher::class)->publish(
            'RefillRequestApproved',
            'Request disetujui',
            'REF-001',
            ["user.{$this->staff->id}"],
            [$this->staff->id],
        );

        $this->assertSame(0, DevicePushToken::query()->count());
    }

    /**
     * The event is durable either way. A provider outage must never fail the business action that
     * produced it — the row in `notifications` is what the user still sees in the app.
     */
    public function test_a_provider_failure_does_not_break_publishing(): void
    {
        $this->configureFcm();
        $this->registerStaffDevice();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'INTERNAL']], 500),
        ]);

        $eventId = app(EventPublisher::class)->publish(
            'RefillRequestApproved',
            'Request disetujui',
            'REF-001',
            ["user.{$this->staff->id}"],
            [$this->staff->id],
        );

        $this->assertNotEmpty($eventId);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->staff->id, 'event_id' => $eventId]);
        // The registration stays: a 500 says nothing about whether the device is still there.
        $this->assertSame(1, DevicePushToken::query()->count());
    }

    public function test_nothing_is_sent_when_fcm_is_not_configured(): void
    {
        config(['services.fcm.project_id' => null, 'services.fcm.credentials_path' => '']);

        $this->registerStaffDevice();

        Http::fake();

        app(EventPublisher::class)->publish(
            'RefillRequestApproved',
            'Request disetujui',
            'REF-001',
            ["user.{$this->staff->id}"],
            [$this->staff->id],
        );

        Http::assertNothingSent();
    }

    /** The person who tapped the button is looking at the result; buzzing them is noise. */
    public function test_the_actor_is_not_pushed_their_own_action(): void
    {
        $this->configureFcm();
        $this->registerStaffDevice();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.fake', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
        ]);

        // The staff member is both the actor and the only recipient, so nothing should go out.
        $this->actingAs($this->staff);

        app(EventPublisher::class)->publish(
            'RefillRequestSubmitted',
            'Request dikirim',
            'REF-002',
            ["user.{$this->staff->id}"],
            [$this->staff->id],
        );

        Http::assertNothingSent();
    }
}
