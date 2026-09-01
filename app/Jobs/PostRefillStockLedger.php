<?php

namespace App\Jobs;

use App\Enums\RefillStatus;
use App\Models\RefillRequest;
use App\Services\RefillRequestStateMachine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * E19 retry: if the synchronous ledger post in
 * RefillRequestStateMachine::deliver() fails, this job is dispatched to try
 * again. The request stays DELIVERED — never silently CLOSED — until one
 * attempt (this job's or a later manual retry) succeeds.
 */
class PostRefillStockLedger implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(
        public readonly int $refillRequestId,
        public readonly int $actorId,
    ) {}

    public function handle(RefillRequestStateMachine $stateMachine): void
    {
        $refill = RefillRequest::query()->find($this->refillRequestId);

        if (! $refill || $refill->status !== RefillStatus::DELIVERED) {
            // Already closed by a previous attempt, or the row is gone — nothing to do.
            return;
        }

        $stateMachine->postLedger($refill, $this->actorId);
    }

    public function backoff(): array
    {
        return [10, 30, 120, 300, 900];
    }

    /**
     * Retries exhausted: the request stays DELIVERED (never silently CLOSED,
     * E19) and this is logged for the E13-style admin escalation to pick up.
     */
    public function failed(?Throwable $exception): void
    {
        report($exception);
    }
}
