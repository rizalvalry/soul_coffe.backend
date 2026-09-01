<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database with a demo-ready dataset. Order matters:
     * each seeder depends on rows created by the ones before it.
     */
    public function run(): void
    {
        $this->call([
            CentralKitchenSeeder::class,
            CartSeeder::class,
            LocationSeeder::class,
            ProductSeeder::class,
            UserSeeder::class,
            StaffAssignmentSeeder::class,
            DailyTargetSeeder::class,
            StockLedgerSeeder::class,
        ]);
    }
}
