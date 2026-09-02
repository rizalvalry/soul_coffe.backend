<?php

namespace App\Http\Resources;

use App\Enums\Role;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `user` object shape shared by `POST /auth/login` and `GET /me` (docs/04).
 *
 * `cart_code`/`cart_id` are read from today's staff_assignments row (STAFF only);
 * `kitchen_name`/`kitchen_id` from `users.kitchen_id` (BARISTA only). Both stay null for
 * every other role — matching the contract's `"kitchen_name": null` example for a STAFF user.
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $cartId = null;
        $cartCode = null;
        $kitchenId = null;
        $kitchenName = null;

        if ($user->role === Role::STAFF) {
            $assignment = StaffAssignment::query()
                ->with('cart')
                ->where('user_id', $user->id)
                ->whereDate('operating_date', now()->toDateString())
                ->first();

            $cartId = $assignment?->cart_id;
            $cartCode = $assignment?->cart?->code;
        }

        if ($user->role === Role::BARISTA) {
            $kitchenId = $user->kitchen_id;
            $kitchenName = $user->kitchen?->name;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role->value,
            'cart_code' => $cartCode,
            'cart_id' => $cartId,
            'kitchen_name' => $kitchenName,
            'kitchen_id' => $kitchenId,
            // Whether a PIN exists, never the PIN itself. The Settings screen needs this to show
            // "change" versus "create", and the login screen needs it to decide whether offering
            // PIN sign-in on this device would lead anywhere.
            'has_login_pin' => $user->login_pin_hash !== null,
        ];
    }
}
