<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every active cart's daily operational allowance, written before anyone opens the app, so the
// barista's Add Stock form is already filled in and they only have to think about cups.
//
// `withoutOverlapping` because the scheduler here is driven by a cron entry on shared hosting
// that can fire twice in the same minute; the command is idempotent anyway, but overlapping runs
// would race on the same unique index and log noise for no reason.
Schedule::command('soul:seed-daily-allowances')
    ->dailyAt('00:00')
    ->withoutOverlapping();

// Carries yesterday's staff→cart→location roster onto today, so an Administrator only ever
// types "Penugasan Staff" once per pairing instead of every single operating day. A row is
// skipped rather than overwritten the moment today already has one for that staff member or
// that cart — see StaffAssignmentCarryForwardService for why a batch job must never clobber a
// decision a human already made for the day.
Schedule::command('soul:carry-forward-staff-assignments')
    ->dailyAt('00:00')
    ->withoutOverlapping();

// The GPS trail is the one table that grows with time rather than with business activity, so its
// retention window is enforced rather than hoped for. Runs at 03:10, well away from the midnight
// batch, because a delete over a large range holds locks that the 00:00 writers would queue on.
// See PruneLocationPings for what is kept and what is dropped.
Schedule::command('soul:prune-location-pings')
    ->dailyAt('03:10')
    ->withoutOverlapping();

/*
 * Drains whatever the inline path could not deliver.
 *
 * Realtime does NOT depend on this: EventPublisher::broadcast() pushes to Pusher inline, right
 * after the transaction commits, and only falls back to the queue when that HTTP call throws. So
 * this worker exists for the outage case — a Pusher blip, a DNS failure — not for the happy path.
 * That distinction matters on this host, which has no supervisor, no systemd, and no `crontab`
 * binary, so the single cron entry that drives the scheduler must be added through hPanel by hand
 * and may simply be absent.
 *
 * Registering the worker HERE rather than as its own cron entry means that one hPanel entry
 * covers everything — a second entry is one more thing to forget on the next host.
 *
 * `--stop-when-empty` exits as soon as the backlog clears instead of idling for a minute, and
 * `--max-time=55` guarantees the process is gone before the next minute's run, so
 * `withoutOverlapping` never has to arbitrate between two live workers.
 */
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();
