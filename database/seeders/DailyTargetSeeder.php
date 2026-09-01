<?php

namespace Database\Seeders;

use App\Models\Cart;
use App\Models\DailyTarget;
use App\Models\Product;
use Illuminate\Database\Seeder;

class DailyTargetSeeder extends Seeder
{
    public function run(): void
    {
        $carts = Cart::query()->get();
        $sellableProducts = Product::query()->where('is_sellable', true)->get();

        foreach ($carts as $cart) {
            foreach ($sellableProducts as $product) {
                DailyTarget::query()->create([
                    'cart_id' => $cart->id,
                    'location_id' => null,
                    'product_id' => $product->id,
                    'target_qty' => 5, // 5 cups per drink per cart
                    'weekday' => null, // applies every day
                ]);
            }
        }
    }
}
