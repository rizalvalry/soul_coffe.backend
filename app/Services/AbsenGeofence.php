<?php

namespace App\Services;

use App\Enums\AbsenExemptionMode;
use App\Enums\Role;
use App\Models\AttendanceExemption;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Where a person is allowed to clock in from, and how far away they actually are.
 *
 * ONE ANSWER, TWO CALLERS
 * -----------------------
 * The mobile app asks this before enabling the absen button (through `GET /absen/status`), and
 * AttendanceService asks it again when the button is pressed. Both go through here so the screen
 * and the server can never disagree about a rule the person is standing in the street trying to
 * satisfy.
 *
 * THE ONE PLACE GPS IS A GATE
 * ---------------------------
 * Everywhere else in this system a missing fix is recorded and ignored (E10) — a refill, a sale
 * and a delivery all complete without it. Absen is the exception, and deliberately: the whole
 * content of an attendance record is "this person was here", so a record created without any
 * evidence of where they were is not a weaker record, it is a different and false one.
 *
 * That exception is bounded three ways, because a gate with no way through gets worked around
 * rather than obeyed:
 *   • a kitchen with no map pin has no geofence at all — the rule switches on per kitchen, when
 *     an Administrator tags it;
 *   • Administrator and Finance can exempt a cart for a date range (events, a distant mess, a
 *     pitch in Blok M) — see AttendanceExemption;
 *   • `soul.absen_requires_gps` turns the whole thing off if a fleet of handsets turns out to be
 *     unable to satisfy it.
 */
class AbsenGeofence
{
    private const METRES_PER_DEGREE = 111_320.0;

    /**
     * What rule applies to this person today, and where they must stand to satisfy it.
     *
     * @return array{
     *     enforced: bool,
     *     basis: string,
     *     lat: float|null,
     *     lng: float|null,
     *     radius_m: int,
     *     label: string|null,
     *     exemption_reason: string|null,
     * }
     */
    public function rule(User $user, ?Carbon $date = null): array
    {
        $date = ($date ?? Carbon::today())->startOfDay();

        $none = [
            'enforced' => false,
            'basis' => 'untagged',
            'lat' => null,
            'lng' => null,
            'radius_m' => (int) config('soul.absen_geofence_m', 10),
            'label' => null,
            'exemption_reason' => null,
        ];

        if (! (bool) config('soul.absen_requires_gps', true)) {
            return $none + ['basis' => 'disabled'];
        }

        $assignment = $user->role === Role::STAFF
            ? StaffAssignment::query()
                ->with(['cart', 'location'])
                ->where('user_id', $user->id)
                ->whereDate('operating_date', $date->toDateString())
                ->where('source', 'hand_to_cart')
                ->whereNull('superseded_at')
                ->latest('id')
                ->first()
            : null;

        $lastAssignment = $user->role === Role::STAFF && ! $assignment
            ? StaffAssignment::query()->with(['cart', 'location'])->where('user_id', $user->id)
                ->where('source', 'hand_to_cart')->orderByDesc('operating_date')->latest('id')->first()
            : null;
        $cartId = $assignment?->cart_id ?? $lastAssignment?->cart_id
            ?? $user->employeeProfile?->default_cart_id;
        $setup = $user->role === Role::STAFF ? app(RiderLocationSetupService::class)->active($user) : null;

        // The exemption is granted to a gerobak code, which is how the request framed it: the
        // cart is what is trading somewhere unusual, and whoever is rostered to it that day
        // inherits the permission.
        if ($cartId) {
            $exemptions = AttendanceExemption::query()
                ->with('cart')->where('cart_id', $cartId)
                ->activeOn($date)
                ->latest('effective_from')
                ->get();

            $exemption = $exemptions->first(fn (AttendanceExemption $row): bool => $row->mode === AbsenExemptionMode::ANYWHERE);

            if ($exemption?->mode === AbsenExemptionMode::ANYWHERE) {
                return [
                    'enforced' => false,
                    'basis' => 'exempt',
                    'lat' => null,
                    'lng' => null,
                    'radius_m' => 0,
                    'label' => 'Bebas lokasi (izin '.$exemption->cart?->code.')',
                    'exemption_reason' => $exemption->reason,
                ];
            }

            $exemption = $exemptions->first(fn (AttendanceExemption $row): bool => $row->mode === AbsenExemptionMode::SELLING_LOCATION);
            $sellingPoint = collect([$setup?->location, $assignment?->location, $lastAssignment?->location])
                ->first(fn ($location): bool => $location?->lat !== null && $location?->lng !== null);
            if ($exemption?->mode === AbsenExemptionMode::SELLING_LOCATION && $sellingPoint?->lat !== null && $sellingPoint->lng !== null) {
                $location = $sellingPoint;

                return [
                    'enforced' => true,
                    'basis' => 'selling_location',
                    'lat' => (float) $location->lat,
                    'lng' => (float) $location->lng,
                    // A selling point is a pavement, not a doorway, so it uses its own geofence
                    // rather than the kitchen's 10 m.
                    'radius_m' => (int) ($location->geofence_m ?: config('soul.absen_geofence_m', 10)),
                    'label' => $location->name,
                    'exemption_reason' => $exemption->reason,
                ];
            }
        }

        if ($setup?->basis === 'selling_location' && $setup->location?->lat !== null && $setup->location->lng !== null) {
            $location = $setup->location;

            return [
                'enforced' => true,
                'basis' => 'selling_location',
                'lat' => (float) $location->lat,
                'lng' => (float) $location->lng,
                'radius_m' => (int) ($location->geofence_m ?: config('soul.absen_geofence_m', 10)),
                'label' => $location->name,
                'exemption_reason' => null,
            ];
        }

        $kitchen = $this->kitchenFor($user, $assignment);

        if (! $kitchen || $kitchen->lat === null || $kitchen->lng === null) {
            // Not tagged on the map yet: nothing to enforce, and absen works exactly as before.
            return $none;
        }

        return [
            'enforced' => true,
            'basis' => 'kitchen',
            'lat' => (float) $kitchen->lat,
            'lng' => (float) $kitchen->lng,
            'radius_m' => (int) ($kitchen->geofence_m ?: config('soul.absen_geofence_m', 10)),
            'label' => $kitchen->name,
            'exemption_reason' => null,
        ];
    }

    /**
     * Decides one clock-in.
     *
     * @param  array{lat: float|null, lng: float|null}  $gps
     * @return array{allowed: bool, message: string|null, distance_m: int|null, basis: string}
     */
    public function check(User $user, array $gps, ?Carbon $date = null): array
    {
        $rule = $this->rule($user, $date);

        if (! $rule['enforced']) {
            return [
                'allowed' => true,
                'message' => null,
                'distance_m' => null,
                'basis' => $rule['basis'],
            ];
        }

        $lat = $gps['lat'] ?? null;
        $lng = $gps['lng'] ?? null;

        if ($lat === null || $lng === null) {
            return [
                'allowed' => false,
                'message' => 'Nyalakan lokasi (GPS) di HP Anda, lalu coba absen lagi. Absen harus dilakukan di '
                    .($rule['label'] ?? 'lokasi yang ditentukan').'.',
                'distance_m' => null,
                'basis' => $rule['basis'],
            ];
        }

        $distance = (int) round($this->metresBetween((float) $rule['lat'], (float) $rule['lng'], (float) $lat, (float) $lng));

        if ($distance > $rule['radius_m']) {
            return [
                'allowed' => false,
                // The numbers are in the message on purpose: "too far" leaves someone walking in
                // a random direction, "34 m, batas 10 m" tells them roughly how far to walk.
                'message' => sprintf(
                    'Kamu masih %d m dari %s, batasnya %d m. Dekat dulu lalu coba absen lagi ya. Kalau jualan jauh hari ini, minta Administrator atau Finance buatin izin absen buat Coffee Bike kamu.',
                    $distance,
                    $rule['label'] ?? 'lokasi absen',
                    $rule['radius_m'],
                ),
                'distance_m' => $distance,
                'basis' => $rule['basis'],
            ];
        }

        return [
            'allowed' => true,
            'message' => null,
            'distance_m' => $distance,
            'basis' => $rule['basis'],
        ];
    }

    private function kitchenFor(User $user, ?StaffAssignment $assignment): ?CentralKitchen
    {
        $kitchenId = $assignment?->kitchen_id ?? $user->kitchen_id;

        if ($kitchenId) {
            return CentralKitchen::query()->find($kitchenId);
        }

        // A staff member with no roster row and no kitchen of their own: if the whole operation
        // has exactly one kitchen, that is unambiguously the one they report to. More than one and
        // there is nothing to guess, so nothing is enforced.
        $kitchens = CentralKitchen::query()->where('is_active', true)->limit(2)->get();

        return $kitchens->count() === 1 ? $kitchens->first() : null;
    }

    /**
     * Equirectangular approximation — the same one StaffLocationService uses, and for the same
     * reason: over tens of metres its error is far below a phone's own accuracy, at a fraction of
     * haversine's cost.
     */
    private function metresBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = ($lat2 - $lat1) * self::METRES_PER_DEGREE;
        $dLng = ($lng2 - $lng1) * self::METRES_PER_DEGREE * cos(deg2rad(($lat1 + $lat2) / 2));

        return sqrt($dLat ** 2 + $dLng ** 2);
    }
}
