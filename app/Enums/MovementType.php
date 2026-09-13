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
    // Pairs with RETURN_IN for unsold cups going back from a cart to the kitchen showcase at
    // close of day. RETURN_IN alone only ever described the receiving side; without this the
    // cart side of that move had no honest type (ADJUSTMENT would hide what actually happened).
    case RETURN_OUT = 'RETURN_OUT';
    case WASTE_OUT = 'WASTE_OUT';
    case ADJUSTMENT = 'ADJUSTMENT';

    // Pairs with SALE_OUT: a voided sale gives its cups back to the cart it left. A separate
    // type rather than reusing ALLOCATION_IN or ADJUSTMENT, because the ledger should say what
    // actually happened — cups that came back from an undone sale are a different fact from a
    // fresh hand-over or a stock-take correction, and a report built on movement_type must be
    // able to tell them apart.
    case SALE_VOID_IN = 'SALE_VOID_IN';

    // The stock-take correction: what a physical count found against what the ledger projected.
    // Signed like ADJUSTMENT, for the same reason — a correction may go either way.
    case OPNAME_ADJUSTMENT = 'OPNAME_ADJUSTMENT';

    // Phase 2 — raw materials & recipes (BOM). A brew consumes raw materials according to the
    // product's recipe; this is the OUT side at the raw-material store, paired with the
    // PRODUCTION_IN already posted for the finished product in the same brew.
    case RECIPE_CONSUME_OUT = 'RECIPE_CONSUME_OUT';

    // A purchase order landing stock in the raw-material store, with a real cost attached
    // (stock_ledger.cost_minor) — the one honest source for "what did this ingredient cost".
    case PURCHASE_IN = 'PURCHASE_IN';
}
