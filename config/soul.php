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

    // Divisor behind "Presentase Kehadiran" on the monthly absensi sheet: hadir ÷ this × 100.
    // 26 is the payroll convention the reference sheet uses (verified against its own printed
    // percentages — see AttendanceSheetService), not the number of days in the month, which is
    // why a partner who works through their days off can legitimately exceed 100%.
    'attendance_working_days' => (int) env('SOUL_ATTENDANCE_WORKING_DAYS', 26),

    // A single sale above this many cups is FLAGGED for Administrator and Finance — never
    // refused (see SaleService). Carts standing in a genuinely busy zone can be exempted per
    // cart (`carts.high_volume_zone`), because a flag that fires every hour is a flag nobody
    // reads.
    'sale_suspect_qty_threshold' => (int) env('SOUL_SALE_SUSPECT_QTY_THRESHOLD', 15),

    // ── Absen berbasis lokasi ──────────────────────────────────────────────────────────────────
    //
    // How close to the Dapur Pusat someone must be to clock in, in metres. Used when a kitchen
    // has been tagged on the map but left its own radius blank; each kitchen may override it,
    // because a yard and a shophouse are not the same size.
    'absen_geofence_m' => (int) env('SOUL_ABSEN_GEOFENCE_M', 10),

    // The master switch for the whole rule. Absen is the ONE place in this system where a missing
    // GPS fix stops an action (everywhere else E10 applies and a fix is only evidence), so there
    // has to be a way to turn it off without a deploy — a fleet of handsets that cannot hold a
    // fix would otherwise mean nobody can start their shift.
    'absen_requires_gps' => (bool) env('SOUL_ABSEN_REQUIRES_GPS', true),

    // ── Aktivitas staff (GPS trail) ────────────────────────────────────────────────────────────
    //
    // A background ping is kept when either this much time has passed since the last stored one,
    // or the phone has moved at least this far. Both filters exist so that a phone standing at a
    // cart for four hours writes a readable trail instead of thousands of identical rows — and
    // so that a phone actually moving is still recorded at street resolution.
    'location_ping_min_interval_seconds' => (int) env('SOUL_LOCATION_PING_MIN_INTERVAL', 45),
    'location_ping_min_move_m' => (int) env('SOUL_LOCATION_PING_MIN_MOVE_M', 25),

    // Cap on one batch upload. The app queues fixes while offline, and a device returning from a
    // long dead spot must not be able to post an unbounded array.
    'location_ping_max_batch' => (int) env('SOUL_LOCATION_PING_MAX_BATCH', 50),

    // How recent a ping must be for the map to call a staff member "live" rather than showing a
    // last known position. Anything older is labelled, never silently presented as current.
    'location_stale_minutes' => (int) env('SOUL_LOCATION_STALE_MINUTES', 10),

    // Retention for the trail, enforced by `soul:prune-location-pings`. Long enough for a
    // monthly engagement review, short enough that the table stays small on shared hosting.
    // Note this prunes only the raw trail: the sales it helped explain are kept forever.
    'location_ping_retention_days' => (int) env('SOUL_LOCATION_PING_RETENTION_DAYS', 45),

];
