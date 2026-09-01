<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** `GET /products` (docs/04, docs/02 §3.1). Read-only master data for every authenticated role. */
class ProductController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        // Eager-load only the current price version (effective_from <= now, newest first) so
        // ProductResource never issues a per-product query for FINANCE/ADMINISTRATOR viewers.
        $products = Product::query()
            ->orderBy('sort_order')
            ->with(['priceVersions' => function ($query): void {
                $query->where('effective_from', '<=', now())->orderByDesc('effective_from');
            }])
            ->get();

        return ProductResource::collection($products);
    }
}
