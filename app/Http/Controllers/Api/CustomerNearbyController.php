<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CustomerNearbyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $position = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);
        $date = now('Asia/Jakarta')->toDateString();

        $carts = DB::table('staff_assignments as assignments')
            ->join('users as riders', 'riders.id', '=', 'assignments.user_id')
            ->join('carts', 'carts.id', '=', 'assignments.cart_id')
            ->join('attendances', function ($join) use ($date): void {
                $join->on('attendances.user_id', '=', 'assignments.user_id')
                    ->where('attendances.operating_date', '=', $date);
            })
            ->join('rider_location_setups as setups', function ($join): void {
                $join->on('setups.rider_id', '=', 'assignments.user_id')
                    ->whereNull('setups.superseded_at');
            })
            ->join('locations', 'locations.id', '=', 'setups.location_id')
            ->where('assignments.operating_date', $date)
            ->where('assignments.source', 'hand_to_cart')
            ->whereNull('assignments.superseded_at')
            ->where('riders.role', 'STAFF')
            ->where('riders.is_active', true)
            ->whereNotNull('locations.lat')
            ->whereNotNull('locations.lng')
            ->whereNotExists(function ($query) use ($date): void {
                $query->selectRaw('1')->from('rider_closings')
                    ->whereColumn('rider_closings.cart_id', 'assignments.cart_id')
                    ->where('rider_closings.operating_date', $date);
            })
            ->select('carts.code', 'locations.name as location_name', 'locations.lat',
                'locations.lng', 'attendances.clocked_in_at')
            ->get();

        $result = $carts->map(function ($cart) use ($position): ?array {
            $distance = $this->distanceMeters((float) $position['lat'], (float) $position['lng'],
                (float) $cart->lat, (float) $cart->lng);
            if ($distance >= 2000) {
                return null;
            }

            return [
                'code' => $cart->code,
                'name' => 'Coffee Bike '.$cart->code,
                'location_name' => $cart->location_name,
                'lat' => (float) $cart->lat,
                'lng' => (float) $cart->lng,
                'distance_m' => (int) (round($distance / 10) * 10),
                'open_since' => Carbon::parse($cart->clocked_in_at)
                    ->timezone('Asia/Jakarta')->format('H:i'),
                '_distance' => $distance,
            ];
        })->filter()->sortBy('_distance')->take(20)->values()->map(function (array $item): array {
            unset($item['_distance']);

            return $item;
        })->all();

        return response()->json(['data' => $result]);
    }

    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($lngDelta / 2) ** 2;

        return 6371000 * 2 * asin(min(1, sqrt($a)));
    }
}
