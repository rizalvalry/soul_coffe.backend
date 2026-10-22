<?php

namespace App\Services;

use App\Models\CentralKitchen;
use App\Models\StaffAssignment;
use App\Models\User;

class RiderClosingPoint
{
    public function for(User $rider, StaffAssignment $assignment): array
    {
        $setup = app(RiderLocationSetupService::class)->active($rider);
        if ($setup?->basis === 'selling_location') {
            $location = $setup->location;

            return [
                'basis' => 'selling_location', 'label' => $location?->name ?? 'titik jualan',
                'lat' => $location?->lat === null ? null : (float) $location->lat,
                'lng' => $location?->lng === null ? null : (float) $location->lng,
                'radius_m' => app(LocationSettings::class)->radiusFor($location),
            ];
        }
        $kitchen = CentralKitchen::query()->find($assignment->kitchen_id ?? $rider->kitchen_id);

        return [
            'basis' => 'kitchen', 'label' => $kitchen?->name ?? 'Dapur',
            'lat' => $kitchen?->lat === null ? null : (float) $kitchen->lat,
            'lng' => $kitchen?->lng === null ? null : (float) $kitchen->lng,
            'radius_m' => app(LocationSettings::class)->radiusFor($kitchen),
        ];
    }

    public function check(array $point, array $gps): array
    {
        if ($point['lat'] === null || $point['lng'] === null) {
            return ['basis' => $point['basis'], 'distance_m' => null, 'outside' => false];
        }
        if (($gps['lat'] ?? null) === null || ($gps['lng'] ?? null) === null || ($gps['unavailable'] ?? false)) {
            return ['basis' => $point['basis'], 'distance_m' => null, 'outside' => true];
        }
        $a = ((float) $gps['lat'] - $point['lat']) * 111_320;
        $b = ((float) $gps['lng'] - $point['lng']) * 111_320
            * cos(deg2rad(((float) $gps['lat'] + $point['lat']) / 2));
        $distance = (int) round(sqrt($a ** 2 + $b ** 2));

        return ['basis' => $point['basis'], 'distance_m' => $distance,
            'outside' => $distance > $point['radius_m']];
    }
}
