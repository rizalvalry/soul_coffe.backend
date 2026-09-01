<?php

namespace Database\Seeders;

use App\Models\Cart;
use App\Models\CentralKitchen;
use Illuminate\Database\Seeder;

class CartSeeder extends Seeder
{
    public function run(): void
    {
        $kitchen = CentralKitchen::query()->firstOrFail();

        // Kode sepeda from the paper form.
        foreach (['0018', '0019', '0020'] as $code) {
            Cart::query()->create([
                'code' => $code,
                'plate' => null,
                'status' => 'active',
                'kitchen_id' => $kitchen->id,
            ]);
        }
    }
}
