<?php

namespace Database\Seeders;

use App\Models\CentralKitchen;
use Illuminate\Database\Seeder;

class CentralKitchenSeeder extends Seeder
{
    public function run(): void
    {
        CentralKitchen::query()->create([
            'name' => 'Dapur Pusat Cempaka Putih',
            'address' => 'Jl. Pramuka Kav 56, Cempaka Putih, Jakarta Pusat',
            'open_at' => '06:00:00',
            'close_at' => '21:00:00',
            'is_active' => true,
        ]);
    }
}
