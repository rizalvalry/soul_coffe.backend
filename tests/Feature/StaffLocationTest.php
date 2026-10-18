<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Events\SoulEvent;
use App\Models\AppNotification;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Models\OutboxEvent;
use App\Models\StaffAssignment;
use App\Models\StaffLocationPing;
use App\Models\User;
use App\Services\SettlementService;
use App\Services\StaffLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The GPS trail behind "Aktivitas Staff".
 *
 * The tests worth reading are the ones about what is NOT stored. A tracker that wrote every fix
 * would fill the table with a phone standing still, and a tracker that blocked anything when the
 * signal died would break selling — so the thinning rule and the absence of any gate are both
 * asserted directly.
 */
class StaffLocationTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Cart $cart;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);

        $this->cart = Cart::create(['code' => '0018', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $this->location = Location::create(['name' => 'Pulomas', 'lat' => -6.18, 'lng' => 106.88]);
        $this->staff = User::factory()->role(Role::STAFF)->create(['name' => 'Rider Uji Satu']);

        StaffAssignment::create([
            'source' => 'hand_to_cart',
            'user_id' => $this->staff->id,
            'cart_id' => $this->cart->id,
            'location_id' => $this->location->id,
            'operating_date' => Carbon::today()->toDateString(),
            'assigned_by' => $this->staff->id,
            'kitchen_id' => $kitchen->id,
        ]);
    }

    private function service(): StaffLocationService
    {
        return app(StaffLocationService::class);
    }

    /** @param array<int, array<string, mixed>> $pings */
    private function report(array $pings, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->staff, 'sanctum')
            ->postJson('/api/v1/me/location', ['pings' => $pings, 'device_id' => 'PIXEL-7']);
    }

    // ── The API ──────────────────────────────────────────────────────────

    public function test_a_ping_is_stored_with_todays_cart_and_area(): void
    {
        $this->report([['lat' => -6.1751, 'lng' => 106.865, 'accuracy_m' => 12, 'battery_pct' => 84]])
            ->assertStatus(202)
            ->assertJsonPath('data.recorded', 1);

        $ping = StaffLocationPing::query()->firstOrFail();
        $this->assertSame($this->staff->id, $ping->user_id);
        $this->assertSame('-6.1751000', $ping->lat);
        $this->assertSame($this->cart->id, $ping->cart_id);
        $this->assertSame($this->location->id, $ping->location_id);
        $this->assertSame('ping', $ping->source);
        $this->assertSame('PIXEL-7', $ping->device_id);
        $this->assertSame(84, $ping->battery_pct);
    }

    /** The response tells the app how often the server actually wants to hear from it. */
    public function test_the_response_carries_the_reporting_interval(): void
    {
        config(['soul.location_ping_min_interval_seconds' => 90]);

        $this->report([['lat' => -6.1, 'lng' => 106.8]])
            ->assertStatus(202)
            ->assertJsonPath('data.min_interval_seconds', 90);
    }

    public function test_only_staff_report_their_position(): void
    {
        $barista = User::factory()->role(Role::BARISTA)->create();

        $this->report([['lat' => -6.1, 'lng' => 106.8]], as: $barista)->assertStatus(403);

        $this->assertSame(0, StaffLocationPing::query()->count());
    }

    public function test_kitchen_status_changes_publish_one_finance_and_management_event_per_transition(): void
    {
        $this->cart->kitchen->update(['lat' => -6.18, 'lng' => 106.88, 'geofence_m' => 100]);
        Event::fake([SoulEvent::class]);

        $this->report([['lat' => -6.18, 'lng' => 106.88]])->assertStatus(202);
        $arrival = OutboxEvent::query()->where('name', 'CartArrivalChanged')->sole();
        $this->assertSame(['role.FINANCE', 'role.MANAGEMENT'], $arrival->payload_json['channels']);
        $this->assertSame('ARRIVED', $arrival->payload_json['status']);
        $this->assertSame($this->cart->id, $arrival->payload_json['cart_id']);
        $this->assertSame(StaffLocationPing::query()->sole()->id, $arrival->payload_json['source_id']);
        $this->assertSame(0, AppNotification::query()->where('type', 'CartArrivalChanged')->count());
        Event::assertDispatchedTimes(SoulEvent::class, 1);

        $this->report([['lat' => -6.18, 'lng' => 106.88]])->assertStatus(202)->assertJsonPath('data.recorded', 0);
        $this->report([['lat' => -6.18001, 'lng' => 106.88]])->assertStatus(202);
        $this->assertSame(1, OutboxEvent::query()->where('name', 'CartArrivalChanged')->count());

        $this->report([['lat' => -6.2, 'lng' => 106.88]])->assertStatus(202);
        $this->assertSame('DEPARTED', OutboxEvent::query()->where('name', 'CartArrivalChanged')->latest('id')->firstOrFail()->payload_json['status']);
        $this->report([['lat' => -6.18, 'lng' => 106.88]])->assertStatus(202);
        $this->assertSame(3, OutboxEvent::query()->where('name', 'CartArrivalChanged')->count());
    }

    public function test_kitchen_arrival_is_dispatched_only_after_the_outer_commit(): void
    {
        $this->cart->kitchen->update(['lat' => -6.18, 'lng' => 106.88, 'geofence_m' => 100]);
        Event::fake([SoulEvent::class]);

        DB::transaction(function (): void {
            $this->report([['lat' => -6.18, 'lng' => 106.88]])->assertStatus(202);
            $this->assertSame(1, OutboxEvent::query()->where('name', 'CartArrivalChanged')->count());
            Event::assertNotDispatched(SoulEvent::class);
        });

        Event::assertDispatchedTimes(SoulEvent::class, 1);
    }

    public function test_a_small_geofence_crossing_is_kept_even_below_the_normal_movement_filter(): void
    {
        $this->cart->kitchen->update(['lat' => -6.18, 'lng' => 106.88, 'geofence_m' => 100]);
        config(['soul.location_ping_min_interval_seconds' => 45, 'soul.location_ping_min_move_m' => 25]);

        $this->report([['lat' => -6.17909, 'lng' => 106.88]])
            ->assertStatus(202)->assertJsonPath('data.recorded', 1);
        $this->assertSame(0, OutboxEvent::query()->where('name', 'CartArrivalChanged')->count());
        $this->report([['lat' => -6.17911, 'lng' => 106.88]])
            ->assertStatus(202)->assertJsonPath('data.recorded', 1);
        $this->assertSame(2, StaffLocationPing::query()->count());
        $this->assertSame('ARRIVED', OutboxEvent::query()->where('name', 'CartArrivalChanged')->sole()->payload_json['status']);
    }

    public function test_retrying_a_mixed_location_batch_does_not_repeat_arrival_events(): void
    {
        $this->cart->kitchen->update(['lat' => -6.18, 'lng' => 106.88, 'geofence_m' => 100]);
        $batch = [
            ['lat' => -6.2, 'lng' => 106.88],
            ['lat' => -6.18, 'lng' => 106.88],
        ];

        $this->report($batch)->assertStatus(202);
        $this->report($batch)->assertStatus(202);

        $this->assertSame(1, OutboxEvent::query()->where('name', 'CartArrivalChanged')->count());
        $queue = app(SettlementService::class)->queue();
        $this->assertSame($this->cart->id, $queue[0]['cart_id']);
        $this->assertNotNull($queue[0]['arrived_at']);
    }

    public function test_batch_returning_inside_keeps_final_position_without_an_extra_event(): void
    {
        $this->cart->kitchen->update(['lat' => -6.18, 'lng' => 106.88, 'geofence_m' => 100]);
        config(['soul.location_ping_min_interval_seconds' => 45, 'soul.location_ping_min_move_m' => 25]);

        $this->report([['lat' => -6.18, 'lng' => 106.88]])->assertStatus(202);
        $this->report([
            ['lat' => -6.17909, 'lng' => 106.88], // Outside, 101 metres from the kitchen.
            ['lat' => -6.17911, 'lng' => 106.88], // Inside, only 2 metres from the prior ping.
        ])->assertStatus(202)->assertJsonPath('data.recorded', 2);

        $this->assertSame('-6.1791100', StaffLocationPing::query()->latest('id')->firstOrFail()->lat);
        $this->assertSame(1, OutboxEvent::query()->where('name', 'CartArrivalChanged')->count());
        $this->assertNotNull(app(SettlementService::class)->queue()[0]['arrived_at']);
    }

    public function test_batch_returning_outside_keeps_final_position_without_a_false_arrival(): void
    {
        $this->cart->kitchen->update(['lat' => -6.18, 'lng' => 106.88, 'geofence_m' => 100]);
        config(['soul.location_ping_min_interval_seconds' => 45, 'soul.location_ping_min_move_m' => 25]);

        $this->report([['lat' => -6.2, 'lng' => 106.88]])->assertStatus(202);
        $this->report([
            ['lat' => -6.17911, 'lng' => 106.88], // Inside the kitchen geofence.
            ['lat' => -6.17909, 'lng' => 106.88], // Outside, only 2 metres from the prior ping.
        ])->assertStatus(202)->assertJsonPath('data.recorded', 2);

        $this->assertSame('-6.1790900', StaffLocationPing::query()->latest('id')->firstOrFail()->lat);
        $this->assertSame(0, OutboxEvent::query()->where('name', 'CartArrivalChanged')->count());
        $this->assertNull(app(SettlementService::class)->queue()[0]['arrived_at']);
    }

    public function test_wrong_role_cannot_publish_a_kitchen_arrival(): void
    {
        $this->cart->kitchen->update(['lat' => -6.18, 'lng' => 106.88, 'geofence_m' => 100]);
        $finance = User::factory()->role(Role::FINANCE)->create();
        $this->report([['lat' => -6.18, 'lng' => 106.88]], as: $finance)->assertStatus(403);
        $this->assertSame(0, OutboxEvent::query()->where('name', 'CartArrivalChanged')->count());
    }

    public function test_a_ping_without_coordinates_is_a_validation_error(): void
    {
        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/me/location', ['pings' => [['accuracy_m' => 10]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pings.0.lat', 'pings.0.lng']);
    }

    public function test_a_staff_member_with_no_assignment_can_still_report(): void
    {
        StaffAssignment::query()->delete();

        $this->report([['lat' => -6.1, 'lng' => 106.8]])->assertStatus(202);

        $ping = StaffLocationPing::query()->firstOrFail();
        $this->assertNull($ping->cart_id);
        $this->assertNull($ping->location_id);
    }

    // ── What is deliberately not stored ──────────────────────────────────

    /**
     * A phone that has not moved must not write a row per fix. This is the whole reason the
     * table stays small enough to live on shared hosting.
     */
    public function test_a_phone_standing_still_writes_one_row_not_many(): void
    {
        config(['soul.location_ping_min_interval_seconds' => 45, 'soul.location_ping_min_move_m' => 25]);

        $this->report([
            ['lat' => -6.175000, 'lng' => 106.865000],
            // ~2 metres away, one second later: the same spot as far as a GPS is concerned.
            ['lat' => -6.175018, 'lng' => 106.865000],
            ['lat' => -6.175002, 'lng' => 106.865010],
        ])->assertStatus(202)->assertJsonPath('data.recorded', 1);

        $this->assertSame(1, StaffLocationPing::query()->count());
    }

    public function test_a_phone_that_actually_moved_writes_both_positions(): void
    {
        config(['soul.location_ping_min_interval_seconds' => 45, 'soul.location_ping_min_move_m' => 25]);

        // Roughly 110 metres apart — a cart that has been pushed down the street.
        $this->report([
            ['lat' => -6.175000, 'lng' => 106.865000],
            ['lat' => -6.176000, 'lng' => 106.865000],
        ])->assertStatus(202)->assertJsonPath('data.recorded', 2);

        $this->assertSame(2, StaffLocationPing::query()->count());
    }

    /** A phone waking up sometimes reports (0,0) before its first real fix. */
    public function test_the_null_island_fix_is_dropped(): void
    {
        $this->report([
            ['lat' => 0, 'lng' => 0],
            ['lat' => -6.175, 'lng' => 106.865],
        ])->assertStatus(202)->assertJsonPath('data.recorded', 1);

        $this->assertSame('-6.1750000', StaffLocationPing::query()->firstOrFail()->lat);
    }

    public function test_a_batch_is_capped(): void
    {
        config(['soul.location_ping_max_batch' => 3, 'soul.location_ping_min_interval_seconds' => 0]);

        $pings = [];
        foreach (range(1, 10) as $i) {
            $pings[] = ['lat' => -6.17 - ($i / 1000), 'lng' => 106.86];
        }

        $this->report($pings)->assertStatus(202)->assertJsonPath('data.recorded', 3);
    }

    /** The device's own clock is kept beside the server's, never instead of it. */
    public function test_the_device_timestamp_is_kept_separately(): void
    {
        // Two hours behind the server, the way a phone with a drifting clock reports.
        $claimed = now()->subHours(2);

        $this->report([['lat' => -6.175, 'lng' => 106.865, 'captured_at' => $claimed->toIso8601String()]])
            ->assertStatus(202);

        $ping = StaffLocationPing::query()->firstOrFail();
        $this->assertSame($claimed->format('H:i'), $ping->captured_at->format('H:i'));
        $this->assertTrue($ping->recorded_at->gt($claimed));
    }

    // ── Positions captured by an action ──────────────────────────────────

    public function test_an_action_sourced_position_is_stored_unconditionally(): void
    {
        // Two positions metres apart, seconds apart: a background ping would be thinned out, but
        // a sale happened at each of them, so both are facts worth keeping.
        $this->service()->recordFromAction($this->staff, -6.175, 106.865, 'sale', $this->cart->id, $this->location->id);
        $this->service()->recordFromAction($this->staff, -6.175001, 106.865001, 'sale', $this->cart->id, $this->location->id);

        $this->assertSame(2, StaffLocationPing::query()->where('source', 'sale')->count());
    }

    // ── The board the CMS reads ──────────────────────────────────────────

    public function test_the_board_lists_a_staff_member_who_has_never_reported(): void
    {
        $board = $this->service()->liveBoard();

        $this->assertCount(1, $board);
        $this->assertNull($board[0]['lat']);
        $this->assertFalse($board[0]['is_live']);
        $this->assertSame('0018', $board[0]['cart_code']);
        $this->assertSame('Pulomas', $board[0]['area']);
    }

    public function test_a_recent_ping_reads_as_live_and_an_old_one_as_last_known(): void
    {
        config(['soul.location_stale_minutes' => 10]);

        $this->report([['lat' => -6.175, 'lng' => 106.865]])->assertStatus(202);

        $this->assertTrue($this->service()->liveBoard()[0]['is_live']);

        // Same row, pushed back beyond the staleness window.
        StaffLocationPing::query()->update(['recorded_at' => now()->subMinutes(30)]);

        $board = $this->service()->liveBoard();
        $this->assertFalse($board[0]['is_live']);
        $this->assertNotNull($board[0]['lat']);
    }

    public function test_an_inactive_staff_member_is_off_the_board(): void
    {
        $this->staff->update(['is_active' => false]);

        $this->assertCount(0, $this->service()->liveBoard());
    }

    public function test_the_trail_is_the_days_pings_oldest_first(): void
    {
        config(['soul.location_ping_min_interval_seconds' => 0]);

        $this->report([['lat' => -6.175, 'lng' => 106.865]]);
        $this->report([['lat' => -6.176, 'lng' => 106.866]]);

        $trail = $this->service()->trail($this->staff);

        $this->assertCount(2, $trail);
        $this->assertSame('-6.1750000', $trail->first()->lat);
        $this->assertSame('-6.1760000', $trail->last()->lat);
    }

    public function test_yesterdays_trail_is_not_todays(): void
    {
        $this->report([['lat' => -6.175, 'lng' => 106.865]]);
        StaffLocationPing::query()->update([
            'operating_date' => Carbon::yesterday()->toDateString(),
            'recorded_at' => now()->subDay(),
        ]);

        $this->assertCount(0, $this->service()->trail($this->staff));
        $this->assertCount(1, $this->service()->trail($this->staff, Carbon::yesterday()));
    }

    // ── Retention ────────────────────────────────────────────────────────

    public function test_pruning_removes_only_rows_past_the_window(): void
    {
        $this->report([['lat' => -6.175, 'lng' => 106.865]]);

        StaffLocationPing::query()->create([
            'user_id' => $this->staff->id,
            'operating_date' => now()->subDays(60)->toDateString(),
            'recorded_at' => now()->subDays(60),
            'lat' => -6.2, 'lng' => 106.9, 'source' => 'ping',
        ]);

        $this->artisan('soul:prune-location-pings', ['--days' => 45])
            ->expectsOutputToContain('1 baris lebih tua dari 45 hari dihapus')
            ->assertSuccessful();

        $this->assertSame(1, StaffLocationPing::query()->count());
    }

    public function test_pruning_refuses_a_window_that_would_delete_today(): void
    {
        $this->artisan('soul:prune-location-pings', ['--days' => 0])->assertFailed();
    }
}
