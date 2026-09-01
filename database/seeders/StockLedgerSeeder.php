<?php

namespace Database\Seeders;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Database\Seeder;

class StockLedgerSeeder extends Seeder
{
    public function run(): void
    {
        $kitchen = CentralKitchen::query()->firstOrFail();
        $barista = User::query()->where('role', Role::BARISTA)->firstOrFail();
        $products = Product::query()->get();

        foreach ($products as $product) {
            StockLedger::query()->create([
                'location_type' => 'kitchen',
                'location_id' => $kitchen->id,
                'product_id' => $product->id,
                'movement_type' => MovementType::PRODUCTION_IN,
                'qty_delta' => 200, // opening stock
                'ref_type' => null,
                'ref_id' => null,
                'actor_id' => $barista->id,
                'kitchen_id' => $kitchen->id,
            ]);
        }
    }
}
