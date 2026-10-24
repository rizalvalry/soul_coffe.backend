<?php

namespace App\Services;

use App\Models\LocationPriceVersion;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\StaffAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ProductSellPriceResolver
{
    public function priceForCart(Product $product, int $cartId, Carbon $date): int
    {
        // An older APK may already have sold cups today. Its first saved price remains the
        // one price for this cart/product/day even if a later base version is imported.
        $firstSalePrice = DB::table('sale_lines as lines')
            ->join('sales', 'sales.id', '=', 'lines.sale_id')
            ->where('sales.cart_id', $cartId)
            ->whereDate('sales.operating_date', $date->toDateString())
            ->where('lines.product_id', $product->id)
            ->orderBy('sales.id')->value('lines.unit_price_minor');
        if ($firstSalePrice !== null) {
            return (int) $firstSalePrice;
        }

        $locationId = StaffAssignment::query()
            ->where('cart_id', $cartId)
            ->whereDate('operating_date', $date->toDateString())
            ->where('source', 'hand_to_cart')
            ->orderBy('id')
            ->value('location_id');

        return $this->price($product, $locationId, $date->copy()->startOfDay(),
            $this->baseVersionForDay($product, $date));
    }

    public function baseVersionForDay(Product $product, Carbon $date): ?ProductPriceVersion
    {
        $atStart = $product->priceVersionAt($date->copy()->startOfDay());
        if ($atStart !== null) {
            return $atStart;
        }

        // Legacy first prices could be entered at noon. They count for that calendar day,
        // while a second version that day cannot change an already chosen Rider price.
        return $product->priceVersions()->whereDate('effective_from', $date->toDateString())
            ->orderBy('effective_from')->orderBy('id')->first();
    }

    public function price(Product $product, ?int $locationId, Carbon $at, ?ProductPriceVersion $baseVersion = null): int
    {
        $productPrice = $baseVersion ?? $product->priceVersionAt($at);
        if ($productPrice === null) {
            return 0;
        }

        if ($locationId !== null) {
            $version = LocationPriceVersion::query()
                ->where('product_id', $product->id)
                ->where('location_id', $locationId)
                ->whereDate('effective_from', '<=', $at->toDateString())
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();

            if ($version !== null) {
                return $version->sell_price_minor;
            }
        }

        return $productPrice->sell_price_minor;
    }
}
