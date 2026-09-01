<?php

namespace App\Http\Controllers\Api;

use App\Enums\AllocationStatus;
use App\Enums\RefillStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\DailyAllocation;
use App\Models\RefillRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /badges` (docs/04 §Notifications, docs/02 §8). Every count is a `count()` query scoped
 * to the caller's role — never a loaded collection — and keys irrelevant to the role stay 0.
 *
 * Spans both flows (Flow A `daily_allocations` and Flow B `refill_requests`) because a badge
 * is what a role needs to act on right now, regardless of which flow it came from — Finance's
 * `pendingApprovals` genuinely includes both a submitted refill and an over-target allocation.
 */
class BadgeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $counts = [
            'pendingApprovals' => 0,
            'incomingRequests' => 0,
            'readyToPick' => 0,
            'myRequests' => 0,
        ];

        switch ($user->role) {
            case Role::FINANCE:
                $counts['pendingApprovals'] = $this->pendingApprovalsCount();
                break;

            case Role::BARISTA:
                $counts['incomingRequests'] = RefillRequest::query()
                    ->where('kitchen_id', $user->kitchen_id)
                    ->where('status', RefillStatus::APPROVED)
                    ->count();
                break;

            case Role::RIDER:
                $counts['readyToPick'] = $this->unclaimedReadyToPickCount();
                break;

            case Role::STAFF:
                $counts['myRequests'] = RefillRequest::query()
                    ->where('staff_id', $user->id)
                    ->whereNotIn('status', array_map(
                        fn (RefillStatus $status): string => $status->value,
                        RefillRequest::TERMINAL_STATUSES,
                    ))
                    ->count();
                break;

            case Role::ADMINISTRATOR:
                // Admin sees everything (docs/02 §2.1) — fleet-wide, unscoped by kitchen.
                $counts['pendingApprovals'] = $this->pendingApprovalsCount();
                $counts['incomingRequests'] = RefillRequest::query()
                    ->where('status', RefillStatus::APPROVED)
                    ->count();
                $counts['readyToPick'] = $this->unclaimedReadyToPickCount();
                break;
        }

        return response()->json(['data' => $counts]);
    }

    private function pendingApprovalsCount(): int
    {
        return RefillRequest::query()->where('status', RefillStatus::SUBMITTED)->count()
            + DailyAllocation::query()->where('status', AllocationStatus::PENDING_FINANCE)->count();
    }

    private function unclaimedReadyToPickCount(): int
    {
        return RefillRequest::query()
            ->where('status', RefillStatus::READY_TO_PICK)
            ->whereNull('rider_id')
            ->count();
    }
}
