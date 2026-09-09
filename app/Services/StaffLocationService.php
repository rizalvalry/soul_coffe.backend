<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\StaffAssignment;
use App\Models\StaffLocationPing;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `staff_location_pings`.
 *
 * WHAT THIS IS FOR
 * ----------------
 * "Aktivitas Staff" answers two different questions with the same rows: *where is this person
 * right now* (supervision) and *which area was busy at which hour* (the engagement analysis the
 * AI insight will read). The second question is the reason this is a trail and not a single
 * mutable "current position" column.
 *
 * WHAT IS DELIBERATELY NOT HERE
 * -----------------------------
 * • **No broadcast per ping.** A phone reports roughly once a minute, so pushing every ping to
 *   Pusher would spend the notification quota on data that has not changed yet. The CMS map
 *   refreshes itself instead — see App\Filament\Pages\StaffActivity.
 *
 * • **No blocking on GPS.** Consistent with E10 everywhere else in this system: a phone with
 *   location switched off reports nothing and every other feature keeps working. A missing
 *   trail is missing evidence, never a refused transaction.
 *
 * • **No storing of every single fix.** Writing a row every few seconds for a phone sitting
 *   still would grow the table without adding one fact. A ping is kept when enough time has
 *   passed OR the phone actually moved; see `shouldKeep()`.
 */
class StaffLocationService
{
    /** Metres per degree of latitude — good to well under a metre at Jakarta's latitude. */
    private const METRES_PER_DEGREE = 111_320.0;

    /**
     * Store a batch of pings, newest last. Batches exist because the app queues fixes while
     * offline; a staff member walking through a dead spot must not lose their trail.
     *
     * @param  array<int, array{lat: float, lng: float, accuracy_m?: int|null, battery_pct?: int|null, is_moving?: bool|null, captured_at?: string|null}>  $pings
     * @return int how many rows were actually written
     */
    public function recordBatch(User $staff, array $pings, ?string $deviceId = null): int
    {
        if ($pings === []) {
            return 0;
        }

        $max = (int) config('soul.location_ping_max_batch', 50);
        $pings = array_slice(array_values($pings), -$max);

        $assignment = $this->assignmentFor($staff);
        $last = $this->latestFor($staff);
        $written = 0;

        foreach ($pings as $ping) {
            $lat = (float) $ping['lat'];
            $lng = (float) $ping['lng'];

            // A phone that has just woken up sometimes reports (0,0) before its first real fix.
            // Storing that would put a staff member in the Gulf of Guinea on the map.
            if ($lat === 0.0 && $lng === 0.0) {
                continue;
            }

            $capturedAt = isset($ping['captured_at']) && $ping['captured_at'] !== null
                ? Carbon::parse($ping['captured_at'])
                : null;

            if (! $this->shouldKeep($last, $lat, $lng)) {
                continue;
            }

            $last = StaffLocationPing::query()->create([
                'user_id' => $staff->id,
                'operating_date' => Carbon::today()->toDateString(),
                // Server clock (R16). The phone's own idea of the time is kept beside it, never
                // instead of it, so a wrong device clock cannot move a ping into another hour.
                'recorded_at' => now(),
                'captured_at' => $capturedAt,
                'lat' => $lat,
                'lng' => $lng,
                'accuracy_m' => $ping['accuracy_m'] ?? null,
                'battery_pct' => $ping['battery_pct'] ?? null,
                'is_moving' => $ping['is_moving'] ?? null,
                'source' => 'ping',
                'cart_id' => $assignment?->cart_id,
                'location_id' => $assignment?->location_id,
                'device_id' => $deviceId,
            ]);

            $written++;
        }

        return $written;
    }

    /**
     * A coordinate captured by another action — a sale, a clock-in, a delivery.
     *
     * These are the strongest rows in the table: they are tied to something that demonstrably
     * happened, so they are stored unconditionally, without the movement filter that thins out
     * background pings.
     */
    public function recordFromAction(
        User $staff,
        float $lat,
        float $lng,
        string $source,
        ?int $cartId = null,
        ?int $locationId = null,
        ?string $deviceId = null,
    ): ?StaffLocationPing {
        if ($lat === 0.0 && $lng === 0.0) {
            return null;
        }

        return StaffLocationPing::query()->create([
            'user_id' => $staff->id,
            'operating_date' => Carbon::today()->toDateString(),
            'recorded_at' => now(),
            'captured_at' => null,
            'lat' => $lat,
            'lng' => $lng,
            'source' => $source,
            'cart_id' => $cartId,
            'location_id' => $locationId,
            'device_id' => $deviceId,
        ]);
    }

    public function latestFor(User $staff): ?StaffLocationPing
    {
        return StaffLocationPing::query()
            ->where('user_id', $staff->id)
            ->latest('recorded_at')
            ->latest('id')
            ->first();
    }

    /**
     * One row per staff member with a position, newest first by recency of that position.
     *
     * Every active staff member appears, including those who have not reported at all — "no
     * signal since 07:12" is exactly the thing a supervisor opens this screen to see, and a list
     * that silently omits them would read as "everyone is fine".
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function liveBoard(?Carbon $date = null): Collection
    {
        $date = ($date ?? Carbon::today())->startOfDay();
        $staleAfter = (int) config('soul.location_stale_minutes', 10);

        $staff = User::query()
            ->where('role', Role::STAFF)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'phone_e164']);

        if ($staff->isEmpty()) {
            return collect();
        }

        $ids = $staff->pluck('id')->all();

        // Latest ping per staff member in one query: the id of the newest row per user, then the
        // rows themselves. Cheap because (user_id, recorded_at) is indexed — a correlated
        // subquery per staff member would be N+1 dressed up as SQL.
        $latestIds = StaffLocationPing::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('user_id', $ids)
            ->groupBy('user_id')
            ->pluck('id');

        $pings = StaffLocationPing::query()
            ->with(['cart:id,code', 'location:id,name'])
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('user_id');

        $assignments = StaffAssignment::query()
            ->with(['cart:id,code', 'location:id,name,lat,lng'])
            ->whereIn('user_id', $ids)
            ->whereDate('operating_date', $date->toDateString())
            ->get()
            ->keyBy('user_id');

        $clockedIn = DB::table('attendances')
            ->whereIn('user_id', $ids)
            ->whereDate('operating_date', $date->toDateString())
            ->pluck('clocked_in_at', 'user_id');

        $sales = DB::table('sales')
            ->selectRaw('staff_id, COUNT(*) as trx, COALESCE(SUM(total_qty),0) as cups, COALESCE(SUM(total_amount_minor),0) as revenue, MAX(occurred_at) as last_sale_at')
            ->whereIn('staff_id', $ids)
            ->whereDate('operating_date', $date->toDateString())
            ->groupBy('staff_id')
            ->get()
            ->keyBy('staff_id');

        return $staff->map(function (User $member) use ($pings, $assignments, $clockedIn, $sales, $staleAfter): array {
            $ping = $pings->get($member->id);
            $assignment = $assignments->get($member->id);
            $sale = $sales->get($member->id);

            return [
                'user_id' => $member->id,
                'name' => $member->name,
                'phone' => $member->phone_e164,
                'cart_code' => $assignment?->cart?->code ?? $ping?->cart?->code,
                'area' => $assignment?->location?->name ?? $ping?->location?->name,
                'clocked_in_at' => $clockedIn->get($member->id),
                'lat' => $ping ? (float) $ping->lat : null,
                'lng' => $ping ? (float) $ping->lng : null,
                'accuracy_m' => $ping?->accuracy_m,
                'battery_pct' => $ping?->battery_pct,
                'source' => $ping?->source,
                'reported_at' => $ping?->recorded_at,
                // "Live" means the phone reported within the staleness window. Anything older is
                // shown as a last known position, labelled as such, rather than pretended to be
                // current.
                'is_live' => $ping !== null && $ping->recorded_at->gt(now()->subMinutes($staleAfter)),
                'transactions' => (int) ($sale->trx ?? 0),
                'cups' => (int) ($sale->cups ?? 0),
                'revenue' => (int) ($sale->revenue ?? 0),
                'last_sale_at' => isset($sale->last_sale_at) ? Carbon::parse($sale->last_sale_at) : null,
            ];
        })->sortByDesc(fn (array $row): int => $row['reported_at']?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * One staff member's movement for a day, oldest first — the drill-down trail.
     *
     * @return Collection<int, StaffLocationPing>
     */
    public function trail(User $staff, ?Carbon $date = null, int $limit = 500): Collection
    {
        return StaffLocationPing::query()
            ->with(['cart:id,code', 'location:id,name'])
            ->where('user_id', $staff->id)
            ->whereDate('operating_date', ($date ?? Carbon::today())->toDateString())
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /** Rows older than the retention window. Called by `soul:prune-location-pings`. */
    public function prune(int $keepDays): int
    {
        return StaffLocationPing::query()
            ->where('recorded_at', '<', now()->subDays($keepDays))
            ->delete();
    }

    /**
     * Today's cart and area for this staff member, stamped onto each ping.
     *
     * Copied onto the row rather than joined at read time: the assignment can be changed later
     * in the day, and a trail that silently re-labels this morning's pings with this afternoon's
     * cart would make the area analysis wrong in a way nobody could see.
     */
    private function assignmentFor(User $staff): ?StaffAssignment
    {
        return StaffAssignment::query()
            ->where('user_id', $staff->id)
            ->whereDate('operating_date', Carbon::today()->toDateString())
            ->first();
    }

    /**
     * Keep a background ping only if it says something new: enough time has passed, or the phone
     * has actually moved further than the noise floor of a consumer GPS.
     */
    private function shouldKeep(?StaffLocationPing $last, float $lat, float $lng): bool
    {
        if (! $last) {
            return true;
        }

        $minInterval = (int) config('soul.location_ping_min_interval_seconds', 45);
        $minMove = (float) config('soul.location_ping_min_move_m', 25);

        if ($last->recorded_at->lte(now()->subSeconds($minInterval))) {
            return true;
        }

        return $this->metresBetween((float) $last->lat, (float) $last->lng, $lat, $lng) >= $minMove;
    }

    /**
     * Equirectangular approximation, not haversine.
     *
     * Over the tens of metres this is used for, the error is far below GPS accuracy, and it
     * costs one cos() instead of five trig calls per comparison — which matters because this
     * runs on every ping in every batch.
     */
    private function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = ($lat2 - $lat1) * self::METRES_PER_DEGREE;
        $dLng = ($lng2 - $lng1) * self::METRES_PER_DEGREE * cos(deg2rad(($lat1 + $lat2) / 2));

        return sqrt($dLat ** 2 + $dLng ** 2);
    }
}
