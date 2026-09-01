<?php

namespace App\Enums;

/**
 * daily_allocations.status (Flow A / §5, Q2). An allocation that exceeds the
 * standardised target by more than the configured tolerance (default 20%) is
 * created as PENDING_FINANCE instead of ISSUED.
 */
enum AllocationStatus: string
{
    case ISSUED = 'ISSUED';
    case PENDING_FINANCE = 'PENDING_FINANCE';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
}
