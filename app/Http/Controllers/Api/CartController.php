<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * `GET /carts` (docs/04). Role scoping applied at the query level (docs/02 §2.2): a Staff
 * user sees only the cart(s) they are assigned to today; every other role sees the fleet.
 */
class CartController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Cart::query()->orderBy('code');

        if ($user->role === Role::STAFF) {
            $query->whereIn('id', function ($sub) use ($user): void {
                $sub->select('cart_id')
                    ->from('staff_assignments')
                    ->where('user_id', $user->id)
                    ->whereDate('operating_date', now()->toDateString());
            });
        }

        return CartResource::collection($query->get());
    }
}
