<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            ['name' => 'Sudirman', 'lat' => -6.2088000, 'lng' => 106.8228000, 'geofence_m' => 100],
            ['name' => 'Thamrin', 'lat' => -6.1944000, 'lng' => 106.8229000, 'geofence_m' => 100],
            ['name' => 'Kemang', 'lat' => -6.2607000, 'lng' => 106.8133000, 'geofence_m' => 100],
        ];

        foreach ($locations as $location) {
            Location::query()->create($location + ['notes' => null]);
        }
    }
}
