<?php

namespace App\Services;

use App\Enums\RefillStatus;
use App\Models\RefillRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class RefillExpiryService
{
    public function __construct(private readonly RefillRequestStateMachine $stateMachine) {}

    public function staleQuery(): Builder
    {
        return RefillRequest::query()
            ->where('operating_date', '<', Carbon::today()->toDateString())
            ->whereIn('status', [
                RefillStatus::SUBMITTED, RefillStatus::APPROVED, RefillStatus::PREPARING,
                RefillStatus::READY_TO_PICK, RefillStatus::PICKED_UP,
            ]);
    }

    public function expire(
        ?int $cartId = null,
        ?int $refillId = null,
        ?int $staffId = null,
        ?int $kitchenId = null,
        ?int $runnerId = null,
    ): int {
        $query = $this->staleQuery();
        if ($cartId !== null) {
            $query->where('cart_id', $cartId);
        }
        if ($refillId !== null) {
            $query->whereKey($refillId);
        }
        if ($staffId !== null) {
            $query->where('staff_id', $staffId);
        }
        if ($kitchenId !== null) {
            $query->where('kitchen_id', $kitchenId);
        }
        if ($runnerId !== null) {
            $query->where(function (Builder $scope) use ($runnerId): void {
                $scope->where('rider_id', $runnerId)
                    ->orWhere(function (Builder $pool): void {
                        $pool->whereNull('rider_id')->where('status', RefillStatus::READY_TO_PICK);
                    });
            });
        }

        $count = 0;
        $query->orderBy('id')->chunkById(100, function ($rows) use (&$count): void {
            foreach ($rows as $row) {
                if ($this->stateMachine->expireIfStale($row)) {
                    $count++;
                }
            }
        });

        return $count;
    }
}
