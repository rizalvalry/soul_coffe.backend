<?php

namespace Database\Seeders;

use App\Services\Access\DefaultPermissions;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        DefaultPermissions::seed();
    }
}
