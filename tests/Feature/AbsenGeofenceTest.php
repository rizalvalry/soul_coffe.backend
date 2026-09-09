<?php

namespace Tests\Feature;

use App\Enums\AbsenExemptionMode;
use App\Enums\Role;
use App\Models\Attendance;
use App\Models\AttendanceExemption;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Absen has to happen at the Dapur Pusat — and the ways out when it genuinely cannot.
 *
 * This is the ONE place in the system where a GPS reading stops somebody doing their job, so the
 * tests here are as much about the escape hatches as the rule: an untagged kitchen enforces
 * nothing, an exempt cart clocks in at its selling point or anywhere at all, and the whole thing
 * can be switched off by config. A gate with no way through gets worked around rather than
 * obeyed, and the workaround would be one person clocking in for another.
 */
class AbsenGeofenceTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Cart $cart;

    private Location $location;

    private User $staff;

    private User $barista;

    /** The kitchen's pin, and a point 400 m away — well outside any sane radius. */
    private const KITCHEN_LAT = -6.1751000;

    private const KITCHEN_LNG = 106.8650000;

    private const FAR_LAT = -6.1787000;

    private const FAR_LNG = 106.8650000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Pulomas',
            'address' => 'Jl. Pulomas 1',
            'lat' => self::KITCHEN_LAT,
            'lng' => self::KITCHEN_LNG,
            'geofence_m' => 10,
            'open_at' => '05:00',
            'close_at' => '20:00',
            'is_active' => true,
        ]);

        $this->cart = Cart::create(['code' => '0018', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);

        $this->location = Location::create([
            'name' => 'Blok M',
            'lat' => -6.2440000,
            'lng' => 106.8000000,
            'geofence_m' => 150,
        ]);

        $this->barista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
        $this->staff = User::factory()->role(Role::STAFF)->create(['name' => 'Mufit']);

        StaffAssignment::create([
            'user_id' => $this->staff->id,
            'cart_id' => $this->cart->id,
            'location_id' => $this->location->id,
            'operating_date' => Carbon::today()->toDateString(),
            'assigned_by' => $this->barista->id,
            'kitchen_id' => $this->kitchen->id,
        ]);
    }

    private function service(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    /** Barista clocks in at the kitchen and opens the day, so staff tests can proceed. */
    private function openTheDay(): void
    {
        $this->service()->clockIn($this->barista, null, ['lat' => self::KITCHEN_LAT, 'lng' => self::KITCHEN_LNG]);
        $this->service()->openStaffWindow($this->barista);
    }

    private function absen(array $gps, ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as ?? $this->staff, 'sanctum')
            ->postJson('/api/v1/absen', $gps);
    }

    // ── The rule ─────────────────────────────────────────────────────────

    public function test_clocking_in_at_the_kitchen_is_accepted_and_records_the_distance(): void
    {
        $this->openTheDay();

        // Roughly 4 m north of the pin.
        $this->absen(['gps_lat' => -6.1750640, 'gps_lng' => self::KITCHEN_LNG])->assertCreated();

        $row = Attendance::query()->where('user_id', $this->staff->id)->firstOrFail();
        $this->assertSame('kitchen', $row->geofence_basis);
        $this->assertNotNull($row->distance_m);
        $this->assertLessThanOrEqual(10, $row->distance_m);
        // The record now carries its own evidence of where it was made.
        $this->assertSame('-6.1750640', $row->gps_lat);
    }

    public function test_clocking_in_far_from_the_kitchen_is_refused_and_says_how_far(): void
    {
        $this->openTheDay();

        $response = $this->absen(['gps_lat' => self::FAR_LAT, 'gps_lng' => self::FAR_LNG])->assertStatus(422);

        // The distance and the limit are both in the message: "too far" leaves someone walking in
        // a random direction.
        $this->assertMatchesRegularExpression('/\d+ m dari Dapur Pulomas, batasnya 10 m/', (string) $response->json('message'));
        $this->assertSame(0, Attendance::query()->where('user_id', $this->staff->id)->count());
    }

    public function test_clocking_in_with_no_gps_at_a_tagged_kitchen_is_refused(): void
    {
        $this->openTheDay();

        $this->absen([])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'Nyalakan lokasi'));
    }

    public function test_a_barista_is_held_to_the_same_rule(): void
    {
        $response = $this->absen(
            ['gps_lat' => self::FAR_LAT, 'gps_lng' => self::FAR_LNG],
            as: $this->barista,
        );

        $response->assertStatus(422);
        $this->assertSame(0, Attendance::query()->where('user_id', $this->barista->id)->count());
    }

    // ── The ways out ─────────────────────────────────────────────────────

    /** Nothing breaks on the day this ships: an untagged kitchen enforces nothing. */
    public function test_a_kitchen_with_no_pin_accepts_absen_from_anywhere(): void
    {
        $this->kitchen->update(['lat' => null, 'lng' => null]);

        $this->service()->clockIn($this->barista);
        $this->service()->openStaffWindow($this->barista);

        $this->absen([])->assertCreated();

        $this->assertSame('untagged', Attendance::query()->where('user_id', $this->staff->id)->value('geofence_basis'));
    }

    public function test_an_exempt_cart_may_clock_in_at_its_selling_point(): void
    {
        $this->openTheDay();

        AttendanceExemption::query()->create([
            'cart_id' => $this->cart->id,
            'mode' => AbsenExemptionMode::SELLING_LOCATION,
            'effective_from' => Carbon::today()->toDateString(),
            'reason' => 'Berjualan di Blok M selama acara.',
            'created_by' => $this->barista->id,
        ]);

        // At the selling point — 20 km from the kitchen, and refused without the exemption.
        $this->absen(['gps_lat' => -6.2440400, 'gps_lng' => 106.8000000])->assertCreated();

        $this->assertSame('selling_location', Attendance::query()->where('user_id', $this->staff->id)->value('geofence_basis'));
    }

    /** The selling-point exemption is still a geofence, not an open door. */
    public function test_the_selling_point_exemption_still_has_a_radius(): void
    {
        $this->openTheDay();

        AttendanceExemption::query()->create([
            'cart_id' => $this->cart->id,
            'mode' => AbsenExemptionMode::SELLING_LOCATION,
            'effective_from' => Carbon::today()->toDateString(),
            'reason' => 'Berjualan di Blok M.',
        ]);

        // Neither at the kitchen nor at Blok M.
        $this->absen(['gps_lat' => -6.3000000, 'gps_lng' => 106.9000000])->assertStatus(422);
    }

    public function test_an_anywhere_exemption_accepts_absen_with_no_gps_at_all(): void
    {
        $this->openTheDay();

        AttendanceExemption::query()->create([
            'cart_id' => $this->cart->id,
            'mode' => AbsenExemptionMode::ANYWHERE,
            'effective_from' => Carbon::today()->toDateString(),
            'reason' => 'Mess staff di Bekasi, acara tujuh hari.',
        ]);

        $this->absen([])->assertCreated();

        $this->assertSame('exempt', Attendance::query()->where('user_id', $this->staff->id)->value('geofence_basis'));
    }

    public function test_an_expired_exemption_no_longer_applies(): void
    {
        $this->openTheDay();

        AttendanceExemption::query()->create([
            'cart_id' => $this->cart->id,
            'mode' => AbsenExemptionMode::ANYWHERE,
            'effective_from' => Carbon::today()->subDays(10)->toDateString(),
            'effective_until' => Carbon::today()->subDay()->toDateString(),
            'reason' => 'Acara pekan lalu.',
        ]);

        $this->absen(['gps_lat' => self::FAR_LAT, 'gps_lng' => self::FAR_LNG])->assertStatus(422);
    }

    public function test_an_exemption_for_another_cart_does_not_help(): void
    {
        $this->openTheDay();

        $otherCart = Cart::create(['code' => '0019', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);

        AttendanceExemption::query()->create([
            'cart_id' => $otherCart->id,
            'mode' => AbsenExemptionMode::ANYWHERE,
            'effective_from' => Carbon::today()->toDateString(),
            'reason' => 'Gerobak lain.',
        ]);

        $this->absen(['gps_lat' => self::FAR_LAT, 'gps_lng' => self::FAR_LNG])->assertStatus(422);
    }

    /** The master switch, for a fleet of handsets that cannot hold a fix. */
    public function test_the_whole_rule_can_be_switched_off_by_config(): void
    {
        config(['soul.absen_requires_gps' => false]);

        $this->service()->clockIn($this->barista);
        $this->service()->openStaffWindow($this->barista);

        $this->absen(['gps_lat' => self::FAR_LAT, 'gps_lng' => self::FAR_LNG])->assertCreated();
    }

    // ── What the app is told before the button is pressed ────────────────

    public function test_the_status_endpoint_describes_the_rule(): void
    {
        $this->openTheDay();

        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/absen/status')
            ->assertSuccessful()
            ->assertJsonPath('data.geofence.enforced', true)
            ->assertJsonPath('data.geofence.basis', 'kitchen')
            ->assertJsonPath('data.geofence.radius_m', 10)
            ->assertJsonPath('data.geofence.label', 'Dapur Pulomas')
            ->assertJsonPath('data.geofence.lat', -6.1751);
    }

    public function test_the_status_endpoint_explains_an_exemption(): void
    {
        $this->openTheDay();

        AttendanceExemption::query()->create([
            'cart_id' => $this->cart->id,
            'mode' => AbsenExemptionMode::ANYWHERE,
            'effective_from' => Carbon::today()->toDateString(),
            'reason' => 'Car Free Day Sudirman.',
        ]);

        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/absen/status')
            ->assertSuccessful()
            ->assertJsonPath('data.geofence.enforced', false)
            ->assertJsonPath('data.geofence.basis', 'exempt')
            ->assertJsonPath('data.geofence.exemption_reason', 'Car Free Day Sudirman.');
    }

    // ── The rule must not break what already worked ──────────────────────

    /** A second tap is a replay, not a fresh location check. */
    public function test_a_repeat_tap_from_further_away_still_returns_the_existing_row(): void
    {
        $this->openTheDay();

        $this->absen(['gps_lat' => self::KITCHEN_LAT, 'gps_lng' => self::KITCHEN_LNG])->assertCreated();

        // Walked away, tapped again: the first clock-in stands and is returned as-is.
        $this->absen(['gps_lat' => self::FAR_LAT, 'gps_lng' => self::FAR_LNG])->assertOk();

        $this->assertSame(1, Attendance::query()->where('user_id', $this->staff->id)->count());
    }

    /** The barista gate still comes first: being in the right place is not a way around it. */
    public function test_staff_still_cannot_clock_in_before_the_barista_opens_the_day(): void
    {
        $this->absen(['gps_lat' => self::KITCHEN_LAT, 'gps_lng' => self::KITCHEN_LNG])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'Absen staff belum dibuka'));
    }
}
