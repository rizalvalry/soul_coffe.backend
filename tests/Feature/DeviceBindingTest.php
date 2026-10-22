<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\DeviceLoginAttempt;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Push\FcmClient;
use App\Services\Push\PushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeviceBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->staff = User::factory()->role(Role::STAFF)->create(['password' => 'secret123']);
        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        config()->set('soul.device_binding_enforce', true);
    }

    private function login(string $id, string $password = 'secret123')
    {
        return $this->postJson('/api/v1/auth/login', [
            'phone' => $this->staff->phone_e164, 'password' => $password,
            'device_id' => $id, 'device_name' => 'HP Staf', 'model' => 'Pixel 8',
            'os_version' => '16', 'app_version' => '1.5.9',
        ]);
    }

    private function adminToken(): string
    {
        $device = UserDevice::query()->create([
            'user_id' => $this->admin->id, 'device_id' => 'admin-phone',
            'device_name' => 'HP Administrator', 'status' => UserDevice::ACTIVE,
        ]);
        $token = $this->admin->createToken('admin');
        $token->accessToken->forceFill(['user_device_id' => $device->id])->save();

        return $token->plainTextToken;
    }

    public function test_first_and_repeat_login_bind_one_active_device(): void
    {
        $this->login('android-one')->assertOk();
        $this->login('android-one')->assertOk();
        $this->assertSame(1, UserDevice::query()->where('status', UserDevice::ACTIVE)->count());
        $this->assertNotNull(UserDevice::query()->firstOrFail()->last_login_at);
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.device.first_bound')->count());
        $this->assertSame(2, DeviceLoginAttempt::query()->where('succeeded', true)->count());
    }

    public function test_new_device_is_pending_once_after_valid_password_and_notifies_rights_holders(): void
    {
        $management = User::factory()->role(Role::STAFF)->create(['is_management' => true]);
        $this->login('android-one')->assertOk();
        $this->login('android-two')->assertStatus(403)->assertJsonPath('code', 'DEVICE_PENDING');
        $this->login('android-two')->assertStatus(403)->assertJsonPath('code', 'DEVICE_PENDING');
        $this->assertSame(1, UserDevice::query()->where('status', UserDevice::PENDING)->count());
        $this->assertSame(1, AppNotification::query()->where('type', 'DeviceApprovalRequested')
            ->where('user_id', $this->admin->id)->count());
        $this->assertSame(1, AppNotification::query()->where('type', 'DeviceApprovalRequested')
            ->where('user_id', $management->id)->count());
        $this->login('android-three', 'wrong')->assertUnauthorized();
        $this->assertSame(2, UserDevice::query()->count());
    }

    public function test_new_request_dispatches_one_push_and_repeat_login_does_not_repeat_it(): void
    {
        $push = \Mockery::mock(PushNotifier::class);
        $push->shouldReceive('notifyUsers')->once()->withArgs(
            fn (array $ids, string $title, string $body): bool => in_array($this->admin->id, $ids, true)
                && $title === 'HP baru menunggu persetujuan'
                && str_contains($body, 'Pixel 8')
        );
        app()->instance(PushNotifier::class, $push);

        $this->login('android-one')->assertOk();
        $this->login('android-two')->assertForbidden();
        $this->login('android-two')->assertForbidden();
    }

    public function test_approval_revokes_old_token_and_reject_requires_admin_to_release(): void
    {
        $oldToken = $this->login('android-one')->json('data.token');
        $this->login('android-two')->assertForbidden();
        $pending = UserDevice::query()->where('device_id', 'android-two')->firstOrFail();
        $adminToken = $this->adminToken();
        $this->withToken($adminToken)->postJson('/api/v1/login-devices/'.$pending->id.'/approve',
            ['reason' => 'Identitas diverifikasi'], ['Idempotency-Key' => 'approve-device-two'])
            ->assertOk()->assertJsonPath('data.status', UserDevice::ACTIVE);
        $this->assertSame(0, $this->staff->tokens()->whereKey((int) explode('|', $oldToken)[0])->count());
        auth()->forgetGuards();
        $this->withToken($oldToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->login('android-two')->assertOk();
        $this->assertDatabaseHas('user_devices', ['device_id' => 'android-one', 'status' => UserDevice::REVOKED]);
        $this->assertDatabaseHas('audit_log', ['action' => 'auth.device.approve', 'actor_id' => $this->admin->id]);
        $this->assertSame('Identitas diverifikasi', AuditLog::query()->where('action', 'auth.device.approve')
            ->latest('id')->firstOrFail()->after_json['reason']);

        $this->login('android-three')->assertJsonPath('code', 'DEVICE_PENDING');
        $third = UserDevice::query()->where('device_id', 'android-three')->firstOrFail();
        $this->withToken($adminToken)->postJson('/api/v1/login-devices/'.$third->id.'/reject',
            ['reason' => 'Bukan HP staf'], ['Idempotency-Key' => 'reject-device-three'])->assertOk();
        $this->login('android-three')->assertJsonPath('code', 'DEVICE_REJECTED');
        $this->assertSame('Bukan HP staf', AuditLog::query()->where('action', 'auth.device.reject')
            ->latest('id')->firstOrFail()->after_json['reason']);
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.device.requested')
            ->where('subject_id', $third->id)->count());
        $this->withToken($adminToken)->postJson('/api/v1/login-devices/'.$third->id.'/revoke',
            ['reason' => 'Penolakan dihapus'], ['Idempotency-Key' => 'clear-device-three'])->assertOk();
        $this->login('android-three')->assertJsonPath('code', 'DEVICE_PENDING');
    }

    public function test_releasing_active_device_allows_next_first_binding(): void
    {
        $oldToken = $this->login('android-one')->json('data.token');
        $first = UserDevice::query()->firstOrFail();
        $adminToken = $this->adminToken();
        $this->withToken($adminToken)->postJson('/api/v1/login-devices/'.$first->id.'/revoke',
            ['reason' => 'HP hilang'], ['Idempotency-Key' => 'revoke-device-one'])->assertOk();
        $this->assertSame('HP hilang', AuditLog::query()->where('action', 'auth.device.revoke')
            ->latest('id')->firstOrFail()->after_json['reason']);
        auth()->forgetGuards();
        $this->withToken($oldToken)->getJson('/api/v1/me')->assertUnauthorized();
        $this->login('android-two')->assertOk();
        $this->assertDatabaseHas('user_devices', ['device_id' => 'android-two', 'status' => UserDevice::ACTIVE]);
    }

    public function test_transition_accepts_old_apk_and_flags_new_phone_without_blocking(): void
    {
        config()->set('soul.device_binding_enforce', false);
        $this->postJson('/api/v1/auth/login', [
            'phone' => $this->staff->phone_e164, 'password' => 'secret123', 'device_name' => 'APK lama',
        ])->assertOk();
        $this->assertDatabaseHas('device_login_attempts', ['reason' => 'UNKNOWN_DEVICE']);
        $this->login('android-one')->assertOk();
        $this->login('android-two')->assertOk();
        $this->assertDatabaseHas('user_devices', ['device_id' => 'android-two', 'status' => UserDevice::PENDING]);
        $this->assertNotNull(UserDevice::query()->where('device_id', 'android-two')->value('last_login_at'));
        $this->assertSame(1, AppNotification::query()->where('type', 'DeviceApprovalRequested')
            ->where('user_id', $this->admin->id)->count());
    }

    public function test_replacement_removes_old_push_registration_and_preserves_new_phone(): void
    {
        config()->set('soul.device_binding_enforce', false);
        $oldToken = $this->login('android-one')->json('data.token');
        $old = UserDevice::query()->where('device_id', 'android-one')->firstOrFail();
        $this->withToken($oldToken)->postJson('/api/v1/me/devices', [
            'token' => str_repeat('a', 40), 'device_name' => 'HP lama',
        ])->assertCreated();
        $this->assertDatabaseHas('device_push_tokens', ['token' => str_repeat('a', 40), 'user_device_id' => $old->id]);

        $newToken = $this->login('android-two')->json('data.token');
        $new = UserDevice::query()->where('device_id', 'android-two')->firstOrFail();
        auth()->forgetGuards();
        $this->withToken($newToken)->postJson('/api/v1/me/devices', [
            'token' => str_repeat('b', 40), 'device_name' => 'HP baru',
        ])->assertCreated();
        DevicePushToken::query()->create([
            'user_id' => $this->staff->id, 'token' => str_repeat('c', 40),
            'device_name' => 'APK lama', 'platform' => 'android', 'last_seen_at' => now(),
        ]);

        auth()->forgetGuards();
        $adminToken = $this->adminToken();
        $this->withToken($adminToken)->postJson('/api/v1/login-devices/'.$new->id.'/approve',
            ['reason' => 'Sudah dicek'], ['Idempotency-Key' => 'approve-push-device'])->assertOk();
        $this->assertDatabaseMissing('device_push_tokens', ['token' => str_repeat('a', 40)]);
        $this->assertDatabaseMissing('device_push_tokens', ['token' => str_repeat('c', 40)]);
        $this->assertDatabaseHas('device_push_tokens', ['token' => str_repeat('b', 40), 'user_device_id' => $new->id]);
    }

    public function test_enforcement_filters_pending_push_and_rejects_pending_socket_subscription(): void
    {
        config()->set('soul.device_binding_enforce', false);
        $this->login('android-one')->assertOk();
        $pendingToken = $this->login('android-two')->json('data.token');
        $pending = UserDevice::query()->where('device_id', 'android-two')->firstOrFail();
        DevicePushToken::query()->create([
            'user_id' => $this->staff->id, 'user_device_id' => $pending->id,
            'token' => str_repeat('b', 40), 'platform' => 'android', 'last_seen_at' => now(),
        ]);
        $active = UserDevice::query()->where('device_id', 'android-one')->firstOrFail();
        DevicePushToken::query()->create([
            'user_id' => $this->staff->id, 'user_device_id' => $active->id,
            'token' => str_repeat('a', 40), 'platform' => 'android', 'last_seen_at' => now(),
        ]);

        config()->set('soul.device_binding_enforce', true);
        $fcm = \Mockery::mock(FcmClient::class);
        $fcm->shouldReceive('isConfigured')->once()->andReturn(true);
        $fcm->shouldReceive('sendMany')->once()->withArgs(
            fn (array $tokens): bool => $tokens === [str_repeat('a', 40)]
        )->andReturn([]);
        (new PushNotifier($fcm))->notifyUsers([$this->staff->id], 'Tes', 'Cek HP');

        auth()->forgetGuards();
        $this->withToken($pendingToken)->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '123.456', 'channel_name' => 'private-user.'.$this->staff->id,
        ])->assertUnauthorized();
    }

    public function test_missing_device_requires_update_only_after_valid_credentials(): void
    {
        $body = ['phone' => $this->staff->phone_e164, 'device_name' => 'APK lama'];
        $this->postJson('/api/v1/auth/login', $body + ['password' => 'wrong'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', $body + ['password' => 'secret123'])
            ->assertForbidden()->assertJsonPath('code', 'DEVICE_UPDATE_REQUIRED');
    }

    public function test_pin_login_uses_same_binding_and_wrong_pin_makes_no_request(): void
    {
        $this->staff->forceFill(['login_pin_hash' => Hash::make('482915')])->save();
        $body = ['phone' => $this->staff->phone_e164, 'pin' => '482915',
            'device_name' => 'HP', 'device_id' => 'android-one'];
        $this->postJson('/api/v1/auth/login-pin', $body)->assertOk();
        $this->postJson('/api/v1/auth/login-pin', array_replace($body, ['device_id' => 'android-two']))
            ->assertForbidden()->assertJsonPath('code', 'DEVICE_PENDING');
        $this->postJson('/api/v1/auth/login-pin', array_replace($body, ['pin' => '000000', 'device_id' => 'android-three']))
            ->assertUnauthorized();
        $this->assertSame(2, UserDevice::query()->count());
    }

    public function test_wrong_role_gets_403_on_device_api(): void
    {
        $this->login('android-one')->assertOk();
        $token = $this->staff->createToken('staff');
        $token->accessToken->forceFill(['user_device_id' => UserDevice::query()->where('user_id', $this->staff->id)->value('id')])->save();
        $token = $token->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/login-devices')->assertForbidden();
        $device = UserDevice::query()->firstOrFail();
        $this->withToken($token)->postJson('/api/v1/login-devices/'.$device->id.'/revoke',
            ['reason' => 'no access'], ['Idempotency-Key' => 'staff-revoke'])->assertForbidden();
    }
}
