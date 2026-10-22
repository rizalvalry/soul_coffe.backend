<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Services\KitchenContext;
use App\Services\RefillExpiryService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `GET /carts` (docs/04). Role scoping applied at the query level (docs/02 §2.2): a Staff
 * user sees only the cart(s) they are assigned to today; every other role sees the fleet.
 */
class CartController extends Controller
{
    public function __construct(private readonly RefillExpiryService $expiry) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $this->expiry->expire(staffId: $user->role === Role::STAFF ? $user->id : null);

        $query = Cart::query()->orderBy('code');
        $kitchenId = null;
        if ($request->query('scope') === 'kitchen' || $request->filled('kitchen_id')) {
            $kitchenId = KitchenContext::resolve($request);
            $query->where(fn ($cartQuery) => $cartQuery->where('kitchen_id', $kitchenId)
                ->orWhere(fn ($unbound) => $unbound->whereNull('kitchen_id')
                    ->whereNotIn('id', fn ($assigned) => $assigned->select('cart_id')
                        ->from('staff_assignments')
                        ->whereDate('operating_date', today()->toDateString())
                        ->where('source', 'hand_to_cart')
                        ->whereNull('superseded_at')
                        ->where('kitchen_id', '<>', $kitchenId))));
        }

        if ($user->role === Role::STAFF) {
            $query->whereIn('id', function ($sub) use ($user): void {
                $sub->select('cart_id')
                    ->from('staff_assignments')
                    ->where('user_id', $user->id)
                    ->whereDate('operating_date', now()->toDateString())
                    ->where('source', 'hand_to_cart')
                    ->whereNull('superseded_at');
            });
        }

        $carts = $query->get();
        if ($kitchenId !== null) {
            $carts = $carts->filter(fn (Cart $cart): bool => ($provenance = KitchenContext::cartStockProvenanceKitchen($cart)) === null
                || $provenance === $kitchenId)->values();
        }

        return CartResource::collection($carts);
    }
}
