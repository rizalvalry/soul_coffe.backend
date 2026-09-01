<?php

/*
 * Soul Coffeemate business-rule tunables (docs/02-context-business-process.md).
 * Every key here has a code default so a missing .env entry degrades safely
 * rather than disabling a guard.
 */
return [

    // E6: evidence photo must be captured within this many minutes of submit.
    'evidence_max_age_minutes' => (int) env('SOUL_EVIDENCE_MAX_AGE_MINUTES', 15),

    // E6: an evidence photo's sha256 may not repeat within this rolling window.
    'evidence_dedupe_days' => (int) env('SOUL_EVIDENCE_DEDUPE_DAYS', 7),

    // §9: qty_requested upper bound per line.
    'max_qty_per_line' => (int) env('SOUL_MAX_QTY_PER_LINE', 100),

    // E12 fallback when a kitchen has no open_at/close_at of its own.
    'operating_open' => env('SOUL_OPERATING_OPEN', '06:00'),
    'operating_close' => env('SOUL_OPERATING_CLOSE', '21:00'),

    // Flow A invariant (§5): allocation beyond this % over the standardised
    // target requires Finance approval. Owned by the allocation flow, kept
    // here because this is the shared tunables file for both flows.
    'allocation_over_target_tolerance' => (int) env('SOUL_ALLOCATION_OVER_TARGET_TOLERANCE', 20),

];
