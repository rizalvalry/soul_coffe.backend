<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `GET /products` (docs/04). `sort_order` follows the paper form — never re-sort here or
 * on the client.
 *
 * R15: `cost_price`/`sell_price` are OMITTED from the JSON entirely (not null, not zero)
 * unless the viewer is FINANCE or ADMINISTRATOR. The current price version is expected to
 * already be eager-loaded on `priceVersions` (filtered to `effective_from <= now()`, newest
 * first) by the caller — see ProductController::index — so this resource never issues its
 * own per-product query.
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        $data = [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name,
            'unit' => $product->unit,
            'is_sellable' => $product->is_sellable,
            'sort_order' => $product->sort_order,
        ];

        $user = $request->user();
        $canSeeCost = $user && in_array($user->role, [Role::FINANCE, Role::ADMINISTRATOR], true);

        if ($canSeeCost) {
            $priceVersion = $product->relationLoaded('priceVersions')
                ? $product->priceVersions->first()
                : $product->currentPriceVersion();

            if ($priceVersion) {
                $data['cost_price'] = $priceVersion->cost_price_minor;
                $data['sell_price'] = $priceVersion->sell_price_minor;
            }
        }

        return $data;
    }
}
