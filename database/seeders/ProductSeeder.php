<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductPriceVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // PLACEHOLDER — real per-item prices not verified; replace before production.
        // Verified research found only Soul CoffeeMate's overall range (Rp 18.000-96.000);
        // no per-item price list was found. Sell prices below are seeded in the
        // 18.000-25.000 band and cost prices at ~40% of sell, purely so Finance's
        // approval-value screen and settlement math have non-zero numbers to work with
        // in a demo. None of these numbers are the real HPP/sell price.
        $products = [
            ['name' => 'Soul Coffee', 'unit' => 'cup', 'sellable' => true, 'sell' => 20000],
            ['name' => 'Cytrus Cold Brew', 'unit' => 'cup', 'sellable' => true, 'sell' => 22000],
            ['name' => 'Thaitea', 'unit' => 'cup', 'sellable' => true, 'sell' => 20000],
            ['name' => 'Kopsu', 'unit' => 'cup', 'sellable' => true, 'sell' => 19000],
            ['name' => 'Passion Coldbrew', 'unit' => 'cup', 'sellable' => true, 'sell' => 23000],
            ['name' => 'Soul Latte', 'unit' => 'cup', 'sellable' => true, 'sell' => 21000],
            ['name' => 'Butterscotch SeaSalt Cream', 'unit' => 'cup', 'sellable' => true, 'sell' => 25000],
            ['name' => 'Soul Matcha', 'unit' => 'cup', 'sellable' => true, 'sell' => 24000],
            ['name' => 'Soul Chocolate', 'unit' => 'cup', 'sellable' => true, 'sell' => 22000],
            ['name' => 'Lechee Tea', 'unit' => 'cup', 'sellable' => true, 'sell' => 18000],
            // Non-sellable consumable (§3.1, Q3): tracked so it can be requested and
            // refilled without polluting sales figures. No sell price; a small
            // placeholder cost keeps its own request-value line non-zero for Finance.
            ['name' => 'ES BATU', 'unit' => 'pack', 'sellable' => false, 'sell' => 0, 'cost' => 2000],
        ];

        foreach ($products as $i => $data) {
            $sortOrder = $i + 1;
            $sell = $data['sell'];
            $cost = $data['cost'] ?? (int) round($sell * 0.4); // ~40% of sell, placeholder

            $product = Product::query()->create([
                'code' => Str::upper(Str::slug($data['name'])),
                'name' => $data['name'],
                'unit' => $data['unit'],
                'is_sellable' => $data['sellable'],
                'sort_order' => $sortOrder,
                'is_active' => true,
            ]);

            ProductPriceVersion::query()->create([
                'product_id' => $product->id,
                'cost_price_minor' => $cost,
                'sell_price_minor' => $sell,
                'effective_from' => now(),
            ]);
        }
    }
}
