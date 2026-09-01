<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\DailyAllocation;
use App\Models\User;

/**
 * Record-level authorisation for daily_allocations (docs/02 §2.1, Flow A).
 *
 * Route-wide "is this role even allowed to hit this endpoint" checks live in
 * AllocationController::middleware() (EnsureRole) — that answers a question that is the
 * same for every request to that action. This policy answers the question EnsureRole
 * structurally cannot: "does THIS caller own or oversee THIS specific record". A Staff
 * member scoped to their own cart is the only role where the two questions diverge.
 */
class DailyAllocationPolicy
{
    /** Who may see the full staff-on-shift roster (the barista's allocation worksheet). */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [Role::ADMINISTRATOR, Role::FINANCE, Role::BARISTA], true);
    }

    /** Who may create an allocation. Requirement 1: barista-owned. */
    public function create(User $user): bool
    {
        return $user->role === Role::BARISTA;
    }

    /** Who may view one allocation record. */
    public function view(User $user, DailyAllocation $allocation): bool
    {
        return match ($user->role) {
            Role::ADMINISTRATOR, Role::FINANCE, Role::BARISTA => true,
            // A Staff member may only see the allocation issued to their own cart.
            Role::STAFF => $allocation->staff_id === $user->id,
            default => false,
        };
    }
}
