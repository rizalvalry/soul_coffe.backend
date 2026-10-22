<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Resources\DeviceLoginAttempts\DeviceLoginAttemptResource;
use App\Filament\Resources\DeviceLoginAttempts\Pages\ListDeviceLoginAttempts;
use App\Filament\Resources\UserDevices\Pages\ListUserDevices;
use App\Filament\Resources\UserDevices\UserDeviceResource;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\DeviceLoginAttempt;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Access\PermissionMatrix;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserDevicePanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_pending_badge_and_approval_action_require_module_rights(): void
    {
        $owner = User::factory()->role(Role::STAFF)->create();
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $device = UserDevice::query()->create([
            'user_id' => $owner->id, 'device_id' => 'abcdef123456', 'device_name' => 'HP Baru',
            'status' => UserDevice::PENDING,
        ]);

        $this->actingAs($admin);
        $this->assertSame('1', UserDeviceResource::getNavigationBadge());
        Livewire::test(ListUserDevices::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$device])
            ->assertSee('abcd…3456')
            ->assertDontSee('abcdef123456')
            ->callAction(TestAction::make('approve')->table($device), ['reason' => 'Sudah diverifikasi'])
            ->assertHasNoActionErrors();
        $this->assertSame(UserDevice::ACTIVE, $device->fresh()->status);
        $this->assertNull(UserDeviceResource::getNavigationBadge());

        $this->actingAs($owner);
        $this->assertFalse(UserDeviceResource::canViewAny());
        $this->assertNull(UserDeviceResource::getNavigationBadge());
        $this->get('/admin/user-devices')->assertForbidden();
    }

    public function test_account_history_includes_unknown_and_failed_unbound_devices(): void
    {
        $owner = User::factory()->role(Role::STAFF)->create();
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $device = UserDevice::query()->create([
            'user_id' => $owner->id, 'device_id' => 'abcdef123456',
            'device_name' => 'HP Baru', 'status' => UserDevice::ACTIVE,
        ]);
        DeviceLoginAttempt::query()->create([
            'user_id' => $owner->id, 'device_name' => 'APK lama',
            'succeeded' => true, 'reason' => 'UNKNOWN_DEVICE',
        ]);
        DeviceLoginAttempt::query()->create([
            'user_id' => $owner->id, 'device_id' => 'other-id-1234',
            'device_name' => 'HP asing', 'succeeded' => false, 'reason' => 'BAD_CREDENTIALS',
        ]);

        $this->actingAs($admin);
        $html = view('filament.device-login-history', [
            'attempts' => DeviceLoginAttempt::query()->where('user_id', $owner->id)->latest()->get(),
        ])->render();
        $this->assertStringContainsString('HP tidak dikenal (APK lama)', $html);
        $this->assertStringContainsString('Nomor HP atau sandi/PIN salah', $html);
        Livewire::test(EditUser::class, ['record' => $owner->id])
            ->assertSee('HP tidak dikenal (APK lama)');
    }

    public function test_devices_view_can_open_history_when_account_has_no_device_row(): void
    {
        $owner = User::factory()->role(Role::STAFF)->create();
        $viewer = User::factory()->role(Role::CONTENT_CREATOR)->create();
        PermissionMatrix::set(Role::CONTENT_CREATOR, PanelModule::DEVICES, ['view']);
        $attempt = DeviceLoginAttempt::query()->create([
            'user_id' => $owner->id, 'device_name' => 'APK lama',
            'succeeded' => true, 'reason' => 'UNKNOWN_DEVICE',
        ]);
        $this->assertSame(0, UserDevice::query()->where('user_id', $owner->id)->count());

        $this->actingAs($viewer);
        $this->get(DeviceLoginAttemptResource::getUrl('index'))->assertOk()->assertSee('APK lama');
        Livewire::test(ListDeviceLoginAttempts::class)->assertCanSeeTableRecords([$attempt]);
        $this->get('/admin/users')->assertForbidden();

        $this->actingAs(User::factory()->role(Role::RIDER)->create());
        $this->get(DeviceLoginAttemptResource::getUrl('index'))->assertForbidden();
    }
}
