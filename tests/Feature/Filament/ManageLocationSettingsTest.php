<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\ManageLocationSettings;
use App\Filament\Resources\CentralKitchens\Pages\EditCentralKitchen;
use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Http\Resources\LocationResource;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Models\LocationSetting;
use App\Models\RiderLocationSetup;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\AbsenGeofence;
use App\Services\Access\PermissionMatrix;
use App\Services\AttendanceService;
use App\Services\LocationSettings;
use App\Services\RiderClosingPoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ManageLocationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function user(Role $role): User
    {
        return User::factory()->role($role)->create();
    }

    private function kitchen(int $radius = 10): CentralKitchen
    {
        return CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jalan Uji',
            'lat' => 0, 'lng' => 0, 'geofence_m' => $radius,
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);
    }

    public function test_role_templates_and_wrong_role_403(): void
    {
        foreach ([Role::ADMINISTRATOR, Role::FINANCE, Role::MANAGEMENT] as $index => $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get(ManageLocationSettings::getUrl())->assertOk();
            $this->assertTrue(PermissionMatrix::can($user, PanelModule::LOCATION_SETTINGS, 'edit'));
            Livewire::test(ManageLocationSettings::class)
                ->set('defaults.customer_radius_m', 2500 + $index)
                ->set('defaultReason', 'Ubah radius uji')
                ->call('saveDefaults')->assertHasNoErrors();
            $this->assertSame(2500 + $index, LocationSetting::query()->findOrFail(1)->customer_radius_m);
        }
        foreach ([Role::BARISTA, Role::STAFF, Role::RIDER, Role::CONTENT_CREATOR] as $role) {
            $this->actingAs($this->user($role))->get(ManageLocationSettings::getUrl())->assertForbidden();
        }
    }

    public function test_default_radius_changes_absen_immediately_and_audits(): void
    {
        $kitchen = $this->kitchen();
        $rider = $this->user(Role::STAFF);
        $rider->update(['kitchen_id' => $kitchen->id]);
        $admin = $this->user(Role::ADMINISTRATOR);
        $this->actingAs($admin);

        Livewire::test(ManageLocationSettings::class)
            ->set("kitchenUseDefault.{$kitchen->id}", true)
            ->set("kitchenReasons.{$kitchen->id}", 'Ikuti radius bawaan')
            ->call('saveKitchen', $kitchen->id)->assertHasNoErrors();
        $this->assertSame(0, $kitchen->fresh()->geofence_m);

        Livewire::test(ManageLocationSettings::class)
            ->set('defaults.absen_radius_m', 50)
            ->set('defaultReason', 'Batas baru untuk UAT')
            ->call('saveDefaults')
            ->assertHasNoErrors();

        $geofence = app(AbsenGeofence::class);
        $this->assertSame(50, $geofence->rule($rider)['radius_m']);
        $this->assertTrue($geofence->check($rider, ['lat' => 40 / 111320, 'lng' => 0])['allowed']);
        $far = $geofence->check($rider, ['lat' => 60 / 111320, 'lng' => 0]);
        $this->assertFalse($far['allowed']);
        $this->assertStringContainsString('50 m', $far['message']);
        $barista = $this->user(Role::BARISTA);
        $barista->update(['kitchen_id' => $kitchen->id]);
        app(AttendanceService::class)->clockIn($barista, gps: ['lat' => 0, 'lng' => 0]);
        app(AttendanceService::class)->openStaffWindow($barista);
        $this->actingAs($rider, 'sanctum')->getJson('/api/v1/absen/status')
            ->assertOk()->assertJsonPath('data.geofence.radius_m', 50);
        $this->postJson('/api/v1/absen', ['gps_lat' => 40 / 111320, 'gps_lng' => 0])->assertCreated();
        $farRider = $this->user(Role::STAFF);
        $farRider->update(['kitchen_id' => $kitchen->id]);
        $this->actingAs($farRider, 'sanctum')
            ->postJson('/api/v1/absen', ['gps_lat' => 60 / 111320, 'gps_lng' => 0])
            ->assertStatus(422)->assertSee('50 m');
        $audit = AuditLog::query()->where('action', 'location_settings.changed')->firstOrFail();
        $this->assertSame(10, $audit->before_json['absen_radius_m']);
        $this->assertSame(50, $audit->after_json['absen_radius_m']);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame('Batas baru untuk UAT', $audit->after_json['reason']);
    }

    public function test_inline_radius_changes_feed_absen_and_closing_with_audit(): void
    {
        $kitchen = $this->kitchen(10);
        $location = Location::create(['name' => 'Titik Uji', 'lat' => 0, 'lng' => 0, 'geofence_m' => 100]);
        $rider = $this->user(Role::STAFF);
        $rider->update(['kitchen_id' => $kitchen->id]);
        $cart = Cart::create(['code' => '0700', 'kitchen_id' => $kitchen->id]);
        $assignment = StaffAssignment::create([
            'user_id' => $rider->id, 'cart_id' => $cart->id, 'kitchen_id' => $kitchen->id,
            'location_id' => $location->id, 'operating_date' => today(),
            'assigned_by' => $rider->id, 'source' => 'hand_to_cart',
        ]);
        $admin = $this->user(Role::ADMINISTRATOR);
        $this->actingAs($admin);

        Livewire::test(ManageLocationSettings::class)
            ->set("kitchenRadii.{$kitchen->id}", 80)
            ->set("kitchenReasons.{$kitchen->id}", 'Radius dapur baru')
            ->call('saveKitchen', $kitchen->id)->assertHasNoErrors();
        $this->assertSame(80, app(AbsenGeofence::class)->rule($rider)['radius_m']);
        $this->assertSame(80, app(RiderClosingPoint::class)->for($rider, $assignment)['radius_m']);

        RiderLocationSetup::create([
            'rider_id' => $rider->id, 'location_id' => $location->id,
            'basis' => 'selling_location', 'changed_by' => $admin->id,
        ]);
        Livewire::test(ManageLocationSettings::class)
            ->set("locationRadii.{$location->id}", 250)
            ->set("locationReasons.{$location->id}", 'Radius jualan baru')
            ->call('saveLocation', $location->id)->assertHasNoErrors();
        $this->assertSame(250, app(AbsenGeofence::class)->rule($rider)['radius_m']);
        $this->assertSame(250, app(RiderClosingPoint::class)->for($rider, $assignment)['radius_m']);
        $this->assertSame(2, AuditLog::query()->where('action', 'location_radius.changed')->count());
        $audit = AuditLog::query()->where('subject_type', Location::class)->firstOrFail();
        $this->assertSame(100, $audit->before_json['geofence_m']);
        $this->assertSame(250, $audit->after_json['geofence_m']);
        $this->assertSame('Radius jualan baru', $audit->after_json['reason']);
    }

    public function test_gps_switch_and_validation_boundaries(): void
    {
        $kitchen = $this->kitchen(10);
        $rider = $this->user(Role::STAFF);
        $rider->update(['kitchen_id' => $kitchen->id]);
        $admin = $this->user(Role::ADMINISTRATOR);
        $this->actingAs($admin);
        $page = Livewire::test(ManageLocationSettings::class)
            ->set('defaults.absen_requires_gps', false)
            ->set('defaultReason', 'GPS dimatikan sementara')
            ->call('saveDefaults')->assertHasNoErrors();
        $this->assertSame('disabled', app(AbsenGeofence::class)->rule($rider)['basis']);
        $this->assertTrue(app(AbsenGeofence::class)->check($rider, ['lat' => null, 'lng' => null])['allowed']);

        $page->set('defaults.absen_radius_m', 4)->set('defaultReason', 'Terlalu kecil')
            ->call('saveDefaults')->assertHasErrors();
        $page->set('defaults.absen_radius_m', 10)->set('defaults.customer_radius_m', 20001)
            ->call('saveDefaults')->assertHasErrors();
        $page->set('defaults.customer_radius_m', 99)
            ->call('saveDefaults')->assertHasErrors();
        $this->assertSame(2000, LocationSetting::query()->findOrFail(1)->customer_radius_m);
        $this->expectException(ValidationException::class);
        app(LocationSettings::class)->saveRadius($admin, $kitchen, 5001, 'Batas terlalu besar');
    }

    public function test_stale_default_form_cannot_revert_another_edit(): void
    {
        $admin = $this->user(Role::ADMINISTRATOR);
        $this->actingAs($admin);
        $first = Livewire::test(ManageLocationSettings::class);
        $stale = Livewire::test(ManageLocationSettings::class);

        $first->set('defaults.customer_radius_m', 3000)
            ->set('defaultReason', 'Jangkauan pelanggan naik')
            ->call('saveDefaults')->assertHasNoErrors();
        $stale->set('defaults.absen_requires_gps', false)
            ->set('defaultReason', 'GPS dimatikan sementara')
            ->call('saveDefaults')->assertHasErrors(['defaults']);

        $row = LocationSetting::query()->findOrFail(1);
        $this->assertSame(3000, $row->customer_radius_m);
        $this->assertTrue($row->absen_requires_gps);
        $this->assertSame(1, AuditLog::query()->where('action', 'location_settings.changed')->count());
    }

    public function test_missing_settings_row_uses_fallback_and_can_be_saved(): void
    {
        DB::table('location_settings')->where('id', 1)->delete();
        $this->actingAs($this->user(Role::ADMINISTRATOR));

        Livewire::test(ManageLocationSettings::class)
            ->assertSet('defaults.absen_radius_m', 10)
            ->set('defaults.absen_radius_m', 50)
            ->set('defaultReason', 'Mulai dari cadangan')
            ->call('saveDefaults')->assertHasNoErrors();

        $row = LocationSetting::query()->findOrFail(1);
        $this->assertSame(50, $row->absen_radius_m);
        $this->assertSame(1, $row->version);
    }

    public function test_rollback_refuses_to_discard_inherited_radius_meaning(): void
    {
        $this->kitchen(0);
        $migration = require database_path('migrations/2026_10_22_000070_create_location_settings.php');

        try {
            $migration->down();
            $this->fail('Rollback should reject inherited radii.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('radius dapur/titik', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('location_settings'));
    }

    public function test_selling_point_can_inherit_default_and_return_effective_radius(): void
    {
        $location = Location::create(['name' => 'Titik Uji', 'lat' => 0, 'lng' => 0, 'geofence_m' => 100]);
        $admin = $this->user(Role::ADMINISTRATOR);
        $this->actingAs($admin);
        Livewire::test(ManageLocationSettings::class)
            ->set('defaults.absen_radius_m', 50)
            ->set('defaultReason', 'Batas bawaan baru')
            ->call('saveDefaults')->assertHasNoErrors();
        Livewire::test(ManageLocationSettings::class)
            ->set("locationUseDefault.{$location->id}", true)
            ->set("locationReasons.{$location->id}", 'Ikuti radius bawaan')
            ->call('saveLocation', $location->id)->assertHasNoErrors();

        $this->assertSame(0, $location->fresh()->geofence_m);
        $this->assertSame(50, app(LocationSettings::class)->radiusFor($location->fresh()));
        $this->assertSame(50, (new LocationResource($location->fresh()))->toArray(request())['geofence_m']);
    }

    public function test_edit_revocation_keeps_page_read_only_and_rejects_submit(): void
    {
        $kitchen = $this->kitchen(10);
        $admin = $this->user(Role::ADMINISTRATOR);
        PermissionMatrix::set(Role::ADMINISTRATOR, PanelModule::LOCATION_SETTINGS, ['view']);
        $this->actingAs($admin)->get(ManageLocationSettings::getUrl())->assertOk()
            ->assertDontSee('Simpan bawaan');
        Livewire::test(ManageLocationSettings::class)
            ->set("kitchenRadii.{$kitchen->id}", 100)
            ->set("kitchenReasons.{$kitchen->id}", 'Tidak boleh ubah')
            ->call('saveKitchen', $kitchen->id)->assertForbidden();
        $this->assertSame(10, $kitchen->fresh()->geofence_m);
    }

    public function test_existing_master_forms_require_reason_and_share_audit(): void
    {
        $kitchen = $this->kitchen(10);
        $location = Location::create(['name' => 'Titik Uji', 'lat' => 0, 'lng' => 0, 'geofence_m' => 100]);
        $admin = $this->user(Role::ADMINISTRATOR);
        $this->actingAs($admin);

        Livewire::test(EditCentralKitchen::class, ['record' => $kitchen->id])
            ->fillForm(['geofence_m' => 55])
            ->call('save')->assertHasErrors(['data.radius_reason']);
        Livewire::test(EditCentralKitchen::class, ['record' => $kitchen->id])
            ->fillForm(['geofence_m' => 55, 'radius_reason' => 'Dapur lebih luas'])
            ->call('save')->assertHasNoErrors();
        Livewire::test(EditLocation::class, ['record' => $location->id])
            ->fillForm(['geofence_m' => 150, 'radius_reason' => 'Titik lebih luas'])
            ->call('save')->assertHasNoErrors();

        Livewire::test(EditCentralKitchen::class, ['record' => $kitchen->id])
            ->fillForm(['use_default_radius' => true, 'radius_reason' => 'Ikuti bawaan absen'])
            ->call('save')->assertHasNoErrors();

        $this->assertSame(0, $kitchen->fresh()->geofence_m);
        $this->assertSame(150, $location->fresh()->geofence_m);
        $this->assertSame(3, AuditLog::query()->where('action', 'location_radius.changed')->count());
    }
}
