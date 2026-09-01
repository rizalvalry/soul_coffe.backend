<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Cart;
use App\Models\Location;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class StaffAssignmentSeeder extends Seeder
{
    public function run(): void
    {
        $maufu = User::query()->where('role', Role::STAFF)->firstOrFail();
        $admin = User::query()->where('role', Role::ADMINISTRATOR)->firstOrFail();
        $cart0018 = Cart::query()->where('code', '0018')->firstOrFail();
        $location = Location::query()->firstOrFail();

        StaffAssignment::query()->create([
            'user_id' => $maufu->id,
            'cart_id' => $cart0018->id,
            'location_id' => $location->id,
            'operating_date' => Carbon::today(),
            'assigned_by' => $admin->id,
            'kitchen_id' => $cart0018->kitchen_id,
        ]);
    }
}
