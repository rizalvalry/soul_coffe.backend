<?php

namespace Tests\Feature;

use App\Enums\AbsenExemptionMode;
use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\SetupLokasiRider;
use App\Filament\Pages\StaffActivity;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Resources\Settlements\Pages\ListSettlements;
use App\Filament\Widgets\CartSalesTodayWidget;
use App\Models\AppNotification;
use App\Models\AttendanceExemption;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\EmployeeProfile;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\RiderClosing;
use App\Models\RiderLocationSetup;
use App\Models\Settlement;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\AbsenGeofence;
use App\Services\Access\PermissionMatrix;
use App\Services\AttendanceService;
use App\Services\CentralStockService;
use App\Services\Reporting\SalesActivityService;
use App\Services\RiderLocationSetupService;
use App\Services\SettlementService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class RiderLocationSetupTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Cart $cart;

    private Location $point;

    private Product $product;

    private User $rider;

    private User $barista;

    private User $management;

    private User $admin;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        config(['outbox.publish_mode' => 'queue']);
        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji', 'lat' => -6.1751, 'lng' => 106.865,
            'geofence_m' => 10, 'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);
        $this->cart = Cart::create(['code' => 'T049', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);
        $this->point = Location::create(['name' => 'Blok M', 'lat' => -6.244, 'lng' => 106.8, 'geofence_m' => 150]);
        $this->product = Product::create(['code' => 'T049-P', 'name' => 'Kopi Uji', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true]);
        ProductPriceVersion::create(['product_id' => $this->product->id, 'cost_price_minor' => 8000,
            'sell_price_minor' => 20000, 'effective_from' => now()->subDay()]);
        $this->rider = User::factory()->role(Role::STAFF)->create(['kitchen_id' => $this->kitchen->id]);
        $this->barista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
        $this->management = User::factory()->role(Role::MANAGEMENT)->create();
        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        app(StockLedgerService::class)->post(StockLedgerService::KITCHEN, $this->kitchen->id,
            $this->product->id, MovementType::PRODUCTION_IN, 50, $this->barista->id, $this->kitchen->id);
    }

    private function open(): void
    {
        $attendance = app(AttendanceService::class);
        $attendance->clockIn($this->barista, gps: ['lat' => -6.1751, 'lng' => 106.865]);
        $attendance->openStaffWindow($this->barista);
    }

    private function writeAs(User $actor, ?int $locationId, string $basis, ?string $key = null): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->patchJson('/api/v1/rider-location-setups/'.$this->rider->id,
                ['location_id' => $locationId, 'basis' => $basis]);
    }

    public function test_cms_allows_management_and_administrator_to_edit_and_finance_to_view(): void
    {
        PermissionMatrix::set(Role::FINANCE, PanelModule::ATTENDANCE, ['view']);
        foreach ([$this->management, $this->admin, $this->finance] as $reader) {
            $this->actingAs($reader)->get(SetupLokasiRider::getUrl())
                ->assertOk()->assertSee('Setup Lokasi Rider');
        }
        foreach ([$this->rider] as $blocked) {
            $this->actingAs($blocked)->get(SetupLokasiRider::getUrl())->assertForbidden();
        }
        $this->actingAs($this->barista)->get(SetupLokasiRider::getUrl())->assertOk();
        Livewire::actingAs($this->finance)->test(SetupLokasiRider::class)
            ->assertTableActionVisible('setup', $this->rider);
        Livewire::actingAs($this->management)->test(SetupLokasiRider::class)
            ->assertTableActionVisible('setup', $this->rider)
            ->callTableAction('setup', $this->rider, [
                'location_id' => $this->point->id, 'basis' => 'selling_location',
            ])->assertHasNoErrors();
        $this->assertDatabaseHas('rider_location_setups', [
            'rider_id' => $this->rider->id, 'location_id' => $this->point->id,
            'basis' => 'selling_location', 'changed_by' => $this->management->id,
        ]);
        Livewire::actingAs($this->admin)->test(SetupLokasiRider::class)
            ->assertTableActionVisible('setup', $this->rider)
            ->callTableAction('setup', $this->rider, ['location_id' => null, 'basis' => 'kitchen'])
            ->assertHasNoErrors();
        $this->assertDatabaseHas('audit_log', ['action' => 'rider_location_setup.changed', 'actor_role' => Role::ADMINISTRATOR->value]);
    }

    public function test_management_and_administrator_can_write_while_allowed_roles_can_read_and_history_is_preserved(): void
    {
        foreach ([$this->management, $this->barista, $this->admin, $this->finance] as $reader) {
            $this->actingAs($reader, 'sanctum')->getJson('/api/v1/rider-location-setups')->assertOk()
                ->assertJsonPath('data.0.id', $this->rider->id);
        }
        foreach ([$this->barista,
            $this->rider, User::factory()->role(Role::RIDER)->create(),
            User::factory()->role(Role::CONTENT_CREATOR)->create()] as $blocked) {
            $this->writeAs($blocked, $this->point->id, 'selling_location')->assertForbidden();
            if (! in_array($blocked->role, [Role::BARISTA, Role::ADMINISTRATOR, Role::FINANCE], true)) {
                $this->actingAs($blocked, 'sanctum')->getJson('/api/v1/rider-location-setups')->assertForbidden();
            }
        }
        $this->management->update(['is_active' => false]);
        $this->writeAs($this->management, $this->point->id, 'selling_location')->assertForbidden();
        $this->actingAs($this->management, 'sanctum')->getJson('/api/v1/rider-location-setups')->assertForbidden();
        $this->management->update(['is_active' => true]);
        $this->admin->update(['is_active' => false]);
        $this->writeAs($this->admin, $this->point->id, 'selling_location')->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/rider-location-setups')->assertForbidden();
        $this->admin->update(['is_active' => true]);
        $this->writeAs($this->admin, $this->point->id, 'selling_location')->assertOk();
        $this->assertDatabaseHas('audit_log', ['action' => 'rider_location_setup.changed', 'actor_role' => Role::ADMINISTRATOR->value]);
        $this->writeAs($this->management, $this->point->id, 'selling_location')->assertOk();
        $this->writeAs($this->management, $this->point->id, 'selling_location')->assertOk();
        $this->assertSame(1, RiderLocationSetup::query()->where('rider_id', $this->rider->id)->count());
        $this->writeAs($this->management, null, 'kitchen')->assertOk();
        $this->assertSame(2, RiderLocationSetup::query()->where('rider_id', $this->rider->id)->count());
        $this->assertNotNull(RiderLocationSetup::query()->where('location_id', $this->point->id)->firstOrFail()->superseded_at);
        $this->assertSame('kitchen', app(RiderLocationSetupService::class)->active($this->rider)?->basis);
    }

    public function test_selling_setup_applies_before_add_stock_and_management_location_wins(): void
    {
        $this->writeAs($this->management, $this->point->id, 'selling_location')->assertOk();
        $this->open();
        $this->assertSame(0, StaffAssignment::query()->count());
        $this->actingAs($this->rider, 'sanctum')->getJson('/api/v1/absen/status')
            ->assertOk()->assertJsonPath('data.geofence.basis', 'selling_location');
        $this->actingAs($this->rider, 'sanctum')->postJson('/api/v1/absen',
            ['gps_lat' => -6.1751, 'gps_lng' => 106.865])->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'm dari Blok M'));
        $this->actingAs($this->rider, 'sanctum')->postJson('/api/v1/absen',
            ['gps_lat' => -6.244, 'gps_lng' => 106.8])->assertCreated();
        $other = Location::create(['name' => 'Lain', 'lat' => -6.2, 'lng' => 106.9]);
        $assignment = app(CentralStockService::class)->handToCart($this->barista, $this->cart,
            $this->rider, [$this->product->id => 10], locationId: $other->id);
        $this->assertSame($this->point->id, $assignment->location_id);
        $this->assertFalse($assignment->location_setup_missing);
        $this->assertSame($this->point->id, app(RiderLocationSetupService::class)->active($this->rider)?->location_id);
    }

    public function test_expired_setup_retry_cannot_replace_a_newer_management_change(): void
    {
        $other = Location::create(['name' => 'Titik Baru', 'lat' => -6.25, 'lng' => 106.82]);
        $oldKey = (string) Str::uuid();
        $newKey = (string) Str::uuid();
        $old = $this->writeAs($this->management, $this->point->id, 'selling_location', $oldKey)
            ->assertOk();
        $this->writeAs($this->management, $other->id, 'selling_location', $newKey)->assertOk();

        Cache::forget('idem:'.$this->management->id.':'.hash('sha256', $oldKey));
        $this->writeAs($this->management, $this->point->id, 'selling_location', $oldKey)
            ->assertOk()->assertJsonPath('data.id', $old->json('data.id'));
        $this->assertSame($other->id, app(RiderLocationSetupService::class)->active($this->rider)?->location_id);
        $this->assertDatabaseCount('rider_location_setups', 2);
        $this->assertDatabaseCount('rider_location_setup_idempotency_keys', 2);
        $this->assertSame(2, AuditLog::query()->where('action', 'rider_location_setup.changed')->count());

        Cache::forget('idem:'.$this->management->id.':'.hash('sha256', $oldKey));
        $this->writeAs($this->management, $other->id, 'selling_location', $oldKey)
            ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame($other->id, app(RiderLocationSetupService::class)->active($this->rider)?->location_id);
    }

    public function test_exemption_uses_default_cart_before_add_stock_and_anywhere_has_priority(): void
    {
        $this->writeAs($this->management, $this->point->id, 'selling_location')->assertOk();
        EmployeeProfile::create(['user_id' => $this->rider->id, 'default_cart_id' => $this->cart->id]);
        AttendanceExemption::create(['cart_id' => $this->cart->id, 'mode' => AbsenExemptionMode::ANYWHERE,
            'effective_from' => today()->subDay()->toDateString(), 'reason' => 'Event', 'created_by' => $this->admin->id]);
        AttendanceExemption::create(['cart_id' => $this->cart->id, 'mode' => AbsenExemptionMode::SELLING_LOCATION,
            'effective_from' => today()->toDateString(), 'reason' => 'Titik jualan sementara', 'created_by' => $this->admin->id]);
        $this->assertSame('exempt', app(AbsenGeofence::class)->rule($this->rider)['basis']);
        $this->open();
        $this->actingAs($this->rider, 'sanctum')->postJson('/api/v1/absen', [])->assertCreated();
    }

    public function test_selling_exemption_uses_previous_assignment_before_add_stock(): void
    {
        $this->writeAs($this->management, null, 'kitchen')->assertOk();
        StaffAssignment::create(['source' => 'hand_to_cart', 'user_id' => $this->rider->id,
            'cart_id' => $this->cart->id, 'location_id' => $this->point->id,
            'operating_date' => today()->subDay(), 'assigned_by' => $this->barista->id,
            'kitchen_id' => $this->kitchen->id]);
        AttendanceExemption::create(['cart_id' => $this->cart->id, 'mode' => AbsenExemptionMode::SELLING_LOCATION,
            'effective_from' => today()->toDateString(), 'reason' => 'Titik sementara', 'created_by' => $this->admin->id]);

        $rule = app(AbsenGeofence::class)->rule($this->rider);
        $this->assertSame('selling_location', $rule['basis']);
        $this->assertSame('Blok M', $rule['label']);
        $this->open();
        $this->actingAs($this->rider, 'sanctum')->postJson('/api/v1/absen',
            ['gps_lat' => -6.244, 'gps_lng' => 106.8])->assertCreated();
    }

    public function test_missing_setup_keeps_add_stock_open_and_notifies_management(): void
    {
        $this->open();
        app(AttendanceService::class)->clockIn($this->rider, gps: ['lat' => -6.1751, 'lng' => 106.865]);
        StaffAssignment::create(['source' => 'hand_to_cart', 'user_id' => $this->rider->id,
            'cart_id' => $this->cart->id, 'location_id' => $this->point->id,
            'operating_date' => today()->subDay(), 'assigned_by' => $this->barista->id,
            'kitchen_id' => $this->kitchen->id]);
        $result = $this->actingAs($this->barista, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/showcase/hand-to-cart', ['cart_id' => $this->cart->id,
                'staff_id' => $this->rider->id, 'location_id' => 999999,
                'lines' => [['product_id' => $this->product->id, 'qty' => 10]]]);
        $result->assertCreated();
        $assignment = StaffAssignment::query()->whereDate('operating_date', today())->firstOrFail();
        $this->assertTrue($assignment->location_setup_missing);
        $this->assertSame($this->point->id, $assignment->location_id);
        $this->assertTrue(AppNotification::query()->where('type', 'RiderLocationSetupMissing')
            ->where('user_id', $this->management->id)->exists());
        $this->assertTrue(AppNotification::query()->where('type', 'RiderLocationSetupMissing')
            ->where('user_id', $this->admin->id)->exists());
    }

    public function test_kitchen_basis_uses_current_rider_kitchen_not_yesterdays_assignment(): void
    {
        $newKitchen = CentralKitchen::create([
            'name' => 'Dapur Baru', 'address' => 'Jl. Baru', 'lat' => -6.3, 'lng' => 106.9,
            'geofence_m' => 10, 'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);
        StaffAssignment::create(['source' => 'hand_to_cart', 'user_id' => $this->rider->id,
            'cart_id' => $this->cart->id, 'location_id' => $this->point->id,
            'operating_date' => today()->subDay(), 'assigned_by' => $this->barista->id,
            'kitchen_id' => $this->kitchen->id]);
        $this->rider->update(['kitchen_id' => $newKitchen->id]);
        $this->writeAs($this->management, $this->point->id, 'kitchen')->assertOk();
        $rule = app(AbsenGeofence::class)->rule($this->rider);
        $this->assertSame('Dapur Baru', $rule['label']);
        $this->assertSame(-6.3, $rule['lat']);
    }

    public function test_closing_location_is_flagged_without_blocking_and_daily_settlement_is_read_only(): void
    {
        $this->writeAs($this->management, $this->point->id, 'selling_location')->assertOk();
        $this->open();
        app(AttendanceService::class)->clockIn($this->rider, gps: ['lat' => -6.244, 'lng' => 106.8]);
        app(CentralStockService::class)->handToCart($this->barista, $this->cart,
            $this->rider, [$this->product->id => 10]);
        $this->actingAs($this->rider, 'sanctum')->getJson('/api/v1/me/closing/draft')
            ->assertOk()->assertJsonPath('data.closing_point.label', 'Blok M');
        $body = ['lines' => [['product_id' => $this->product->id, 'qty_remaining' => 5,
            'qty_wasted' => 0]], 'qris' => 0, 'transfer' => 0];
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/me/closing', $body + ['gps_lat' => -6.244, 'gps_lng' => 106.8])
            ->assertCreated()->assertJsonPath('data.outside_closing_point', false);
        $this->assertFalse(AppNotification::query()->where('type', 'RiderClosingOutsidePoint')->exists());
        $this->actingAs($this->rider, 'sanctum')->getJson('/api/v1/me/settlement')
            ->assertOk()->assertJsonPath('data.status', 'WAITING_FINANCE')
            ->assertJsonPath('data.closing.cash_minor', 100000);
        $closingId = RiderClosing::query()->sole()->id;
        $this->actingAs($this->finance, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/closings/'.$closingId.'/corrections', [
                'lines' => [['product_id' => $this->product->id, 'qty_remaining' => 4, 'qty_wasted' => 0]],
                'qris' => 0, 'transfer' => 0, 'reason' => 'Sisa cups diperbaiki',
            ])->assertOk();
        $this->assertFalse(RiderClosing::query()->sole()->outside_closing_point);
        $this->assertSame(0, RiderClosing::query()->sole()->closing_distance_m);
        $this->assertNotNull(RiderClosing::query()->sole()->gps_lat);
        $body['lines'][0]['qty_remaining'] = 4;
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/me/closing', $body + ['gps_lat' => -6.1751, 'gps_lng' => 106.865])
            ->assertOk()->assertJsonPath('data.outside_closing_point', true);
        $this->assertGreaterThan(100, RiderClosing::query()->sole()->closing_distance_m);
        foreach ([$this->admin, $this->finance] as $recipient) {
            $this->assertTrue(AppNotification::query()->where('type', 'RiderClosingOutsidePoint')
                ->where('user_id', $recipient->id)->exists());
        }
        $this->assertFalse(AppNotification::query()->where('type', 'RiderClosingOutsidePoint')
            ->where('user_id', $this->management->id)->exists());
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/me/closing', $body + ['gps_lat' => -6.244, 'gps_lng' => 106.8])
            ->assertOk()->assertJsonPath('data.outside_closing_point', true)
            ->assertJsonPath('data.closing_distance_m', 0);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/me/closing', $body + ['gps_unavailable' => true])
            ->assertOk()->assertJsonPath('data.outside_closing_point', true)
            ->assertJsonPath('data.closing_distance_m', null);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/me/closing', $body + ['gps_lat' => -6.244])
            ->assertOk()->assertJsonPath('data.outside_closing_point', true);
        $this->assertTrue(RiderClosing::query()->sole()->gps_unavailable);
        $this->assertNull(RiderClosing::query()->sole()->gps_lat);
        $settlement = Settlement::create(['cart_id' => $this->cart->id, 'staff_id' => $this->rider->id,
            'operating_date' => today(), 'status' => 'SUBMITTED']);
        $this->actingAs($this->rider, 'sanctum')->getJson('/api/v1/me/settlement')
            ->assertOk()->assertJsonPath('data.status', 'RECORDED');
        $settlement->update(['status' => 'RECONCILED', 'reconciled_at' => now()]);
        $this->actingAs($this->rider, 'sanctum')->getJson('/api/v1/me/settlement')
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->actingAs($this->barista, 'sanctum')->getJson('/api/v1/me/settlement')->assertForbidden();
        $closingDate = today()->toDateString();
        $this->travelTo(now()->addDay());
        $this->actingAs($this->rider, 'sanctum')->getJson('/api/v1/me/settlement')
            ->assertOk()->assertJsonPath('data.closing', null);
        $this->actingAs($this->rider, 'sanctum')->getJson('/api/v1/me/settlement?operating_date='.$closingDate)
            ->assertOk()->assertJsonPath('data.closing.id', $closingId)
            ->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_unpinned_kitchen_does_not_raise_a_closing_location_flag(): void
    {
        $this->writeAs($this->management, null, 'kitchen')->assertOk();
        $this->kitchen->update(['lat' => null, 'lng' => null]);
        $this->open();
        app(AttendanceService::class)->clockIn($this->rider, gps: ['lat' => -6.1751, 'lng' => 106.865]);
        app(CentralStockService::class)->handToCart($this->barista, $this->cart,
            $this->rider, [$this->product->id => 10]);
        $this->actingAs($this->rider, 'sanctum')->getJson('/api/v1/me/closing/draft')
            ->assertOk()->assertJsonPath('data.closing_point.lat', null);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/me/closing', [
                'lines' => [['product_id' => $this->product->id, 'qty_remaining' => 5, 'qty_wasted' => 0]],
                'qris' => 0, 'transfer' => 0, 'gps_unavailable' => true,
            ])->assertCreated()->assertJsonPath('data.outside_closing_point', false)
            ->assertJsonPath('data.closing_distance_m', null);
        $this->assertFalse(AppNotification::query()->where('type', 'RiderClosingOutsidePoint')->exists());
    }

    public function test_closing_location_warning_is_visible_only_to_admin_and_finance_in_cms(): void
    {
        $this->writeAs($this->management, $this->point->id, 'selling_location')->assertOk();
        $this->open();
        app(AttendanceService::class)->clockIn($this->rider, gps: ['lat' => -6.244, 'lng' => 106.8]);
        app(CentralStockService::class)->handToCart($this->barista, $this->cart,
            $this->rider, [$this->product->id => 10]);
        $this->actingAs($this->rider, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/me/closing', [
                'lines' => [['product_id' => $this->product->id, 'qty_remaining' => 5, 'qty_wasted' => 0]],
                'qris' => 0, 'transfer' => 0, 'gps_lat' => -6.1751, 'gps_lng' => 106.865,
            ])->assertCreated()->assertJsonPath('data.outside_closing_point', true);
        PermissionMatrix::set(Role::FINANCE, PanelModule::SETTLEMENTS, ['view']);
        PermissionMatrix::set(Role::FINANCE, PanelModule::STAFF_ACTIVITY, ['view']);
        PermissionMatrix::set(Role::FINANCE, PanelModule::AUDIT_LOGS, ['view']);
        $closingAudit = AuditLog::query()->where('action', 'rider_closing.recorded')->sole();
        $this->assertTrue($closingAudit->after_json['outside_closing_point']);

        Auth::shouldUse('web');
        $this->assertNull(Auth::user());
        $this->assertSame(0, app(SalesActivityService::class)->dayTotals()['flagged']);
        $this->assertFalse(app(SettlementService::class)->queue()[0]['outside_closing_point']);
        $this->assertArrayNotHasKey('outside_closing_point', $closingAudit->displayChanges('after'));

        foreach ([$this->admin, $this->finance] as $reader) {
            $this->actingAs($reader);
            $this->assertTrue($closingAudit->displayChanges('after')['outside_closing_point']);
            $this->assertSame(1, app(SalesActivityService::class)->dayTotals()['flagged']);
            $this->assertSame(1, app(SalesActivityService::class)->perCart()[0]['flagged']);
            $this->assertTrue(app(SettlementService::class)->queue()[0]['outside_closing_point']);
            Livewire::actingAs($reader)->test(ListSettlements::class)->assertSee('Pernah Closing di luar titik');
            Livewire::actingAs($reader)->test(StaffActivity::class)->assertSee('Pernah Closing di luar titik');
            $this->get(ViewAuditLog::getUrl(['record' => $closingAudit]))->assertSuccessful()
                ->assertSee('outside_closing_point')->assertSee('closing_distance_m');
        }
        PermissionMatrix::set(Role::MANAGEMENT, PanelModule::CLOSING_LOCATION_EVIDENCE, []);
        $this->actingAs($this->management);
        $this->assertArrayNotHasKey('outside_closing_point', $closingAudit->displayChanges('after'));
        $this->assertArrayNotHasKey('closing_distance_m', $closingAudit->displayChanges('after'));
        $this->get(ViewAuditLog::getUrl(['record' => $closingAudit]))->assertSuccessful()
            ->assertDontSee('outside_closing_point')->assertDontSee('closing_distance_m')
            ->assertDontSee('gps_lat')->assertDontSee('gps_lng');
        $this->assertSame(0, app(SalesActivityService::class)->dayTotals()['flagged']);
        $this->assertSame(0, app(SalesActivityService::class)->perCart()[0]['flagged']);
        $this->assertNull(app(SalesActivityService::class)->perCart()[0]['closing_location_warning']);
        $this->assertFalse(app(SettlementService::class)->queue()[0]['outside_closing_point']);
        $this->assertNull(app(SettlementService::class)->queue()[0]['closing_distance_m']);
        $this->actingAs($this->management, 'sanctum')->getJson('/api/v1/settlements/queue')
            ->assertOk()->assertJsonPath('data.0.outside_closing_point', false)
            ->assertJsonPath('data.0.closing_distance_m', null);
        Livewire::actingAs($this->management)->test(ListSettlements::class)
            ->assertDontSee('Pernah Closing di luar titik');
        Livewire::actingAs($this->management)->test(StaffActivity::class)
            ->assertDontSee('Pernah Closing di luar titik')->assertDontSee('bsi-stat--alarm');
        Livewire::actingAs($this->management)->test(CartSalesTodayWidget::class)
            ->assertDontSee('1 ditandai');
    }

    public function test_backfill_is_idempotent_and_does_not_replace_management_setup(): void
    {
        StaffAssignment::create(['source' => 'hand_to_cart', 'user_id' => $this->rider->id,
            'cart_id' => $this->cart->id, 'location_id' => $this->point->id,
            'operating_date' => today()->subDay(), 'assigned_by' => $this->barista->id,
            'kitchen_id' => $this->kitchen->id]);
        $migration = require database_path('migrations/2026_10_20_000100_create_rider_location_setups.php');
        $migration->backfill();
        $migration->backfill();
        $this->assertSame(1, RiderLocationSetup::query()->where('rider_id', $this->rider->id)->count());
        $this->assertSame('kitchen', app(RiderLocationSetupService::class)->active($this->rider)?->basis);
        $this->writeAs($this->management, null, 'kitchen')->assertOk();
        $migration->backfill();
        $this->assertSame(2, RiderLocationSetup::query()->where('rider_id', $this->rider->id)->count());
        $this->assertNull(app(RiderLocationSetupService::class)->active($this->rider)?->location_id);
    }

    public function test_rollback_refuses_populated_setup_without_dropping_any_columns(): void
    {
        $this->writeAs($this->management, $this->point->id, 'selling_location')->assertOk();
        $migration = require database_path('migrations/2026_10_20_000100_create_rider_location_setups.php');

        try {
            $migration->down();
            $this->fail('Rollback must preserve Rider setup history.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'T-049 setup history or closing location evidence must be preserved before rollback.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('rider_location_setups', 1);
        $this->assertTrue(Schema::hasColumn('staff_assignments', 'location_setup_missing'));
        $this->assertTrue(Schema::hasColumn('rider_closings', 'outside_closing_point'));
    }
}
