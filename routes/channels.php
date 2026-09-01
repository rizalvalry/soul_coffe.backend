<?php

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

/*
 * Private channel authorisation (docs/04 §Realtime).
 *
 * These checks are deliberately written against tables rather than Eloquent models: channel
 * authorisation runs on every subscription and is pure authorisation, so a single indexed
 * existence query is both cheaper and independent of model-layer changes.
 *
 * Returning false here is the only thing standing between a staff member and another cart's
 * live event stream, so every channel below verifies ownership — never just "is logged in".
 */

/**
 * `role` is cast to a native enum on the User model, but channel callbacks also run in contexts
 * (tests, tinker, a hydrated-from-array user) where it may still be a plain string. Normalising
 * once here avoids calling ->value on a string.
 */
$roleOf = static function (object $user): string {
    $role = $user->role ?? null;

    if ($role instanceof \BackedEnum) {
        return strtoupper((string) $role->value);
    }

    return strtoupper((string) $role);
};

Broadcast::channel('user.{userId}', function ($user, string $userId): bool {
    return (string) $user->id === (string) $userId;
});

Broadcast::channel('role.{role}', function ($user, string $role) use ($roleOf): bool {
    return $roleOf($user) === strtoupper($role);
});

Broadcast::channel('kitchen.{kitchenId}', function ($user, string $kitchenId) use ($roleOf): bool {
    $role = $roleOf($user);

    if (in_array($role, ['ADMINISTRATOR', 'FINANCE'], true)) {
        return true;
    }

    return $role === 'BARISTA' && (string) $user->kitchen_id === (string) $kitchenId;
});

Broadcast::channel('cart.{cartId}', function ($user, string $cartId) use ($roleOf): bool {
    $role = $roleOf($user);

    if (in_array($role, ['ADMINISTRATOR', 'FINANCE', 'BARISTA'], true)) {
        return true;
    }

    // A staff member may only listen to the cart they are actually assigned to today (R11).
    return DB::table('staff_assignments')
        ->where('user_id', $user->id)
        ->where('cart_id', $cartId)
        ->whereDate('operating_date', now()->toDateString())
        ->exists();
});

Broadcast::channel('refill.{refillId}', function ($user, string $refillId) use ($roleOf): bool {
    $role = $roleOf($user);

    if (in_array($role, ['ADMINISTRATOR', 'FINANCE'], true)) {
        return true;
    }

    $refill = DB::table('refill_requests')
        ->select('staff_id', 'kitchen_id', 'rider_id', 'status')
        ->where('id', $refillId)
        ->first();

    if (! $refill) {
        return false;
    }

    return match ($role) {
        'STAFF' => (string) $refill->staff_id === (string) $user->id,
        'BARISTA' => (string) $refill->kitchen_id === (string) $user->kitchen_id,
        // A rider sees a request it has claimed, plus the unclaimed pool it may claim from.
        'RIDER' => (string) $refill->rider_id === (string) $user->id
            || ($refill->rider_id === null && $refill->status === 'READY_TO_PICK'),
        default => false,
    };
});
