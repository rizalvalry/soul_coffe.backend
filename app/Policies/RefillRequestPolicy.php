<?php

namespace App\Policies;

use App\Enums\RefillStatus;
use App\Enums\Role;
use App\Models\RefillRequest;
use App\Models\User;

/**
 * Who may act, per docs/02 §2.1 and the §6.1 transition table's "Actor" column.
 *
 * Deliberately role/ownership only — NOT the current status. Mixing the two
 * here would collapse the R1/E1/E2 state guards (which must answer 409) into
 * this policy's 403, contradicting the status-code table in docs/04. Status
 * eligibility is RefillRequestStateMachine's job; this class only answers
 * "is this actor even the right kind of person to attempt it".
 */
class RefillRequestPolicy
{
    public function viewAny(User $user): bool
    {
        // Scoping happens at the query level in RefillRequestController::index() (§2.2) — never here.
        return true;
    }

    public function view(User $user, RefillRequest $refill): bool
    {
        return match ($user->role) {
            Role::ADMINISTRATOR, Role::FINANCE => true,
            Role::BARISTA => $user->kitchen_id === $refill->kitchen_id,
            Role::STAFF => $user->id === $refill->staff_id,
            Role::RIDER => $refill->rider_id === $user->id
                || ($refill->rider_id === null && $refill->status === RefillStatus::READY_TO_PICK),
        };
    }

    public function create(User $user): bool
    {
        return $user->role === Role::STAFF;
    }

    public function approve(User $user, RefillRequest $refill): bool
    {
        // §2.1 permission matrix: Approve/reject is Finance-only — Admin has read access, not approval.
        return $user->role === Role::FINANCE;
    }

    public function reject(User $user, RefillRequest $refill): bool
    {
        return $user->role === Role::FINANCE;
    }

    public function cancel(User $user, RefillRequest $refill): bool
    {
        return $user->role === Role::STAFF && $user->id === $refill->staff_id;
    }

    public function startPreparing(User $user, RefillRequest $refill): bool
    {
        return $user->role === Role::BARISTA && $user->kitchen_id === $refill->kitchen_id;
    }

    public function markReady(User $user, RefillRequest $refill): bool
    {
        return $user->role === Role::BARISTA && $user->kitchen_id === $refill->kitchen_id;
    }

    public function claim(User $user, RefillRequest $refill): bool
    {
        // Self-claim from the shared pool (Q4) — any rider may attempt; the
        // state machine's atomic UPDATE decides who actually wins (E2).
        return $user->role === Role::RIDER;
    }

    public function deliver(User $user, RefillRequest $refill): bool
    {
        return $user->role === Role::RIDER && $refill->rider_id === $user->id;
    }
}
