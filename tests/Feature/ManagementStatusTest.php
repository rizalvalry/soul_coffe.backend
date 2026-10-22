<?php

namespace Tests\Feature;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\ManagementReports;
use App\Filament\Pages\RoleAccessMatrix;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\CentralKitchen;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\EventPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManagementStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_super_administrator_can_set_status_for_any_role_and_it_does_not_grant_access(): void
    {
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $kitchen = CentralKitchen::query()->create([
            'name' => 'Dapur Uji Management', 'address' => 'Jakarta',
            'open_at' => '08:00:00', 'close_at' => '17:00:00',
        ]);

        foreach ([Role::FINANCE, Role::ADMINISTRATOR, Role::BARISTA] as $role) {
            $user = User::factory()->role($role)->create($role === Role::BARISTA ? ['kitchen_id' => $kitchen->id] : []);
            $before = PermissionMatrix::allFor($user);
            $this->actingAs($super);
            Livewire::test(EditUser::class, ['record' => $user->id])
                ->set('data.is_management', true)->call('save')->assertHasNoErrors();
            $this->assertTrue($user->fresh()->isManagement());
            $this->assertSame($before, PermissionMatrix::allFor($user->fresh()));
            $this->assertDatabaseHas('audit_log', ['subject_id' => $user->id, 'action' => 'management.status_changed']);
            Livewire::test(EditUser::class, ['record' => $user->id])
                ->set('data.is_management', false)->call('save')->assertHasNoErrors();
            $this->assertFalse($user->fresh()->isManagement());
            $this->assertSame($role, $user->fresh()->role);
        }
        $this->assertSame(6, AuditLog::query()->where('action', 'management.status_changed')->count());
    }

    public function test_non_super_accounts_cannot_change_management_status_even_by_direct_request(): void
    {
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $target = User::factory()->role(Role::FINANCE)->create();
        foreach ([Role::ADMINISTRATOR, Role::FINANCE] as $role) {
            $actor = User::factory()->role($role)->create();
            $this->actingAs($actor);
            Livewire::test(EditUser::class, ['record' => $target->id])
                ->set('data.is_management', true)->call('save')->assertForbidden();
            $this->assertFalse($target->fresh()->is_management);
        }
        $this->actingAs($super);
        $target->update(['is_management' => true]);
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        Livewire::test(EditUser::class, ['record' => $target->id])
            ->set('data.is_management', false)->call('save')->assertForbidden();
        $this->assertTrue($target->fresh()->is_management);
    }

    public function test_changing_role_keeps_management_status(): void
    {
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $user = User::factory()->role(Role::FINANCE)->create(['is_management' => true]);
        $kitchen = CentralKitchen::query()->create([
            'name' => 'Dapur Ganti Role', 'address' => 'Jakarta',
            'open_at' => '08:00:00', 'close_at' => '17:00:00',
        ]);
        $this->actingAs($super);
        Livewire::test(EditUser::class, ['record' => $user->id])
            ->set('data.role', Role::BARISTA->value)
            ->set('data.kitchen_id', $kitchen->id)
            ->call('save')->assertHasNoErrors();
        $this->assertSame(Role::BARISTA, $user->fresh()->role);
        $this->assertTrue($user->fresh()->isManagement());
    }

    public function test_user_list_labels_and_filters_management_status(): void
    {
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $marked = User::factory()->role(Role::FINANCE)->create(['is_management' => true]);
        $plain = User::factory()->role(Role::FINANCE)->create();
        $this->actingAs($super);

        Livewire::test(ListUsers::class)->filterTable('is_management', true)
            ->assertCanSeeTableRecords([$marked, $super])
            ->assertCanNotSeeTableRecords([$plain])
            ->assertSee('Finance · Management')
            ->assertSee('Super Administrator · Management');
    }

    public function test_management_role_is_not_offered_or_accepted_and_matrix_hides_legacy_template(): void
    {
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $this->actingAs($super);
        $this->assertNotContains(Role::MANAGEMENT, Livewire::test(RoleAccessMatrix::class)->instance()->editableRoles());
        Livewire::test(RoleAccessMatrix::class)->set('role', Role::MANAGEMENT->value)->assertStatus(422);
        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Akun Lama', 'phone_e164' => '081299900064', 'role' => Role::FINANCE->value,
            'password' => 'rahasia123', 'is_active' => true,
        ])->set('data.role', Role::MANAGEMENT->value)->call('create')->assertStatus(422);
        $this->assertDatabaseMissing('users', ['phone_e164' => '081299900064']);
    }

    public function test_status_controls_api_value_realtime_channel_and_leadership_recipients(): void
    {
        $marked = User::factory()->role(Role::FINANCE)->create(['is_management' => true]);
        $plain = User::factory()->role(Role::FINANCE)->create();
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $inactive = User::factory()->role(Role::FINANCE)->create(['is_management' => true, 'is_active' => false]);
        $this->assertSame([$marked->id, $super->id], User::managementIds());
        $this->assertFalse($inactive->isManagement());

        $this->actingAs($marked, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.is_management', true);
        $this->actingAs($plain, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.is_management', false);
        $this->actingAs($super, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.is_management', true);

        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
        ]);
        require base_path('routes/channels.php');
        $payload = ['socket_id' => '123.456', 'channel_name' => 'private-role.MANAGEMENT'];
        $this->actingAs($marked, 'sanctum')->postJson('/api/v1/broadcasting/auth', $payload)->assertOk();
        $this->actingAs($plain, 'sanctum')->postJson('/api/v1/broadcasting/auth', $payload)->assertForbidden();
        $this->actingAs($super, 'sanctum')->postJson('/api/v1/broadcasting/auth', $payload)->assertOk();
        $this->actingAs($inactive, 'sanctum')->postJson('/api/v1/broadcasting/auth', $payload)->assertForbidden();

        $eventId = app(EventPublisher::class)->publish(
            'AccountingPeriodReopened', 'Periode dibuka lagi', 'Cek pembukuan',
            ['role.MANAGEMENT'], [$marked->id, $super->id],
        );
        $channels = OutboxEvent::query()->where('event_id', $eventId)->firstOrFail()->payload_json['channels'];
        $this->assertContains('user.'.$marked->id, $channels);
        $this->assertContains('user.'.$super->id, $channels);
        $this->assertNotContains('user.'.$plain->id, $channels);
        $this->assertFalse(AppNotification::query()->where('event_id', $eventId)
            ->where('user_id', $plain->id)->exists());
    }

    public function test_revoking_status_stops_private_leadership_delivery_on_an_existing_socket(): void
    {
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $production = User::factory()->role(Role::BARISTA)->create(['is_management' => true]);
        $publisher = app(EventPublisher::class);

        $publisher->publish('SaleRecorded', 'Penjualan tercatat', 'Coffee Bike baru', ['role.MANAGEMENT']);
        $before = OutboxEvent::query()->latest('id')->firstOrFail()->payload_json['channels'];
        $this->assertContains('user.'.$production->id, $before);
        $this->assertNotContains('role.MANAGEMENT', $before);

        $this->actingAs($super);
        $production->update(['is_management' => false]);
        $publisher->publish('SaleRecorded', 'Penjualan tercatat', 'Coffee Bike baru', ['role.MANAGEMENT']);
        $after = OutboxEvent::query()->latest('id')->firstOrFail()->payload_json['channels'];
        $this->assertNotContains('user.'.$production->id, $after);
        $this->assertNotContains('role.MANAGEMENT', $after);
    }

    public function test_leadership_alert_reaches_status_without_grants_and_deduplicates_finance_members(): void
    {
        $manager = User::factory()->role(Role::BARISTA)->create(['is_management' => true]);
        $dual = User::factory()->role(Role::FINANCE)->create(['is_management' => true]);
        $plain = User::factory()->role(Role::FINANCE)->create();
        $eventId = app(EventPublisher::class)->publish(
            'SettlementVarianceFlagged', 'Ada selisih', 'Cek setoran',
            ['role.FINANCE', 'role.MANAGEMENT'], [$plain->id, $dual->id],
        );
        $this->assertSame(1, AppNotification::query()->where('event_id', $eventId)
            ->where('user_id', $manager->id)->count());
        $this->assertSame(1, AppNotification::query()->where('event_id', $eventId)
            ->where('user_id', $dual->id)->count());
        $this->assertSame(1, AppNotification::query()->where('event_id', $eventId)
            ->where('user_id', $plain->id)->count());
    }

    public function test_management_status_and_grant_remain_independent_with_audited_reason(): void
    {
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $kitchen = CentralKitchen::query()->create([
            'name' => 'Dapur Akses Management', 'address' => 'Jakarta',
            'open_at' => '08:00:00', 'close_at' => '17:00:00',
        ]);
        $production = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $kitchen->id]);
        $this->actingAs($super);
        $this->assertFalse(PermissionMatrix::can($production, PanelModule::MANAGEMENT_REPORTS, 'view'));
        Livewire::test(EditUser::class, ['record' => $production->id])
            ->set('data.is_management', true)
            ->set('data.access_grants.management_reports', 'view')
            ->set('data.access_reasons.management_reports', 'Keputusan rapat Management 22 Okt')
            ->call('save')->assertHasNoErrors();
        $this->assertTrue($production->fresh()->isManagement());
        $this->assertTrue(PermissionMatrix::can($production->fresh(), PanelModule::MANAGEMENT_REPORTS, 'view'));
        $this->actingAs($production)->get(ManagementReports::getUrl())->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'user_permission.changed')->get()
            ->contains(fn (AuditLog $log): bool => ($log->after_json['user_id'] ?? null) === $production->id
                && ($log->after_json['override']['reason'] ?? null) === 'Keputusan rapat Management 22 Okt'));
    }
}
