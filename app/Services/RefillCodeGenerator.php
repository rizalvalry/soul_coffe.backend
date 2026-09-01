<?php

namespace App\Services;

use App\Models\RefillRequest;
use Illuminate\Support\Carbon;

/**
 * Human-facing request codes, e.g. `REF-20260901-0003` (docs/04 §Flow B).
 *
 * The sequence is derived from today's row count rather than a dedicated
 * counter table — acceptable at this fleet's scale (Q10: ~50 carts) — and a
 * collision on the resulting `code` unique index is handled by the caller
 * (RefillRequestStateMachine::submit()) regenerating and retrying the insert,
 * so a race here degrades to a retry rather than a lost request.
 */
class RefillCodeGenerator
{
    public function generate(Carbon $operatingDate): string
    {
        $sequence = RefillRequest::query()
            ->whereDate('operating_date', $operatingDate->toDateString())
            ->count() + 1;

        return sprintf('REF-%s-%04d', $operatingDate->format('Ymd'), $sequence);
    }
}
