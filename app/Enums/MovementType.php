<?php

namespace App\Enums;

/**
 * stock_ledger.movement_type (§12). The ledger is append-only (R6) — every row is
 * one of these. Sign convention: IN movements carry a positive qty_delta, OUT
 * movements a negative qty_delta, so `SUM(qty_delta)` is always the projected stock.
 */
enum MovementType: string
{
    case PRODUCTION_IN = 'PRODUCTION_IN';
    case ALLOCATION_OUT = 'ALLOCATION_OUT';
    case ALLOCATION_IN = 'ALLOCATION_IN';
    case REFILL_OUT = 'REFILL_OUT';
    case REFILL_IN = 'REFILL_IN';
    case SALE_OUT = 'SALE_OUT';
    case RETURN_IN = 'RETURN_IN';
    case WASTE_OUT = 'WASTE_OUT';
    case ADJUSTMENT = 'ADJUSTMENT';
}
