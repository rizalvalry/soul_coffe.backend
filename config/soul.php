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

    // A re-upload of identical bytes by the same staff member within this window is treated as
    // a retry of one upload, not a reused photo — the mobile client retries when the connection
    // drops before the response lands. Kept far shorter than the dedupe window above: it must
    // cover a handful of retries, never a second submit.
    'evidence_retry_window_minutes' => (int) env('SOUL_EVIDENCE_RETRY_WINDOW_MINUTES', 10),

    // §9: qty_requested upper bound per line.
    'max_qty_per_line' => (int) env('SOUL_MAX_QTY_PER_LINE', 100),

    // E12 fallback when a kitchen has no open_at/close_at of its own.
    'operating_open' => env('SOUL_OPERATING_OPEN', '06:00'),
    'operating_close' => env('SOUL_OPERATING_CLOSE', '21:00'),

    // Flow A invariant (§5): allocation beyond this % over the standardised
    // target requires Finance approval. Owned by the allocation flow, kept
    // here because this is the shared tunables file for both flows.
    'allocation_over_target_tolerance' => (int) env('SOUL_ALLOCATION_OVER_TARGET_TOLERANCE', 20),

    // Daily operational allowance per cart (uang makan/minum staff), in whole rupiah per R9.
    // Written once per cart per operating day by `soul:seed-daily-allowances` at 00:00 and
    // pre-filled — still editable — in the barista's Add Stock form, so the usual case needs
    // no typing at all.
    'daily_cart_allowance' => (int) env('SOUL_DAILY_CART_ALLOWANCE', 50000),

    // How the allowance above is treated in the money reports.
    //
    // false (default) = biaya operasional: company expense, outside the staff's settlement.
    // true            = counted into Settlement.expected_total_minor, i.e. money the staff is
    //                   accountable for at day-end reconciliation.
    //
    // This is deliberately a switch rather than something baked into how the allowance is
    // stored: DailyCartAllowance records the plain fact ("cart X received Rp N on date Y") and
    // nothing else, so changing this rule later changes only what the reports do with that fact
    // — no data migration, and past records stay true to whichever rule applied at the time.
    'allowance_counts_toward_settlement' => (bool) env('SOUL_ALLOWANCE_COUNTS_TOWARD_SETTLEMENT', false),

];
