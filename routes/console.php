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

/*
 * Drains the notification queue.
 *
 * This host has no supervisor and no systemd, so there is nowhere to keep a `queue:work` process
 * alive; and it has no `crontab` binary either, so the single cron entry that drives the
 * scheduler has to be added through hPanel by hand. Putting the worker HERE rather than in its
 * own cron entry means that one entry covers everything — a second entry is one more thing to
 * forget when this is set up again on a new host.
 *
 * Without this, PublishOutboxEvent jobs simply accumulate in the `jobs` table and no realtime
 * notification is ever delivered, which is precisely the state this server was found in: 30
 * queued jobs, 30 unpublished outbox rows, and a broadcaster pointed at `log`.
 *
 * `--stop-when-empty` exits as soon as the backlog clears instead of idling for a minute, and
 * `--max-time=55` guarantees the process is gone before the next minute's run, so
 * `withoutOverlapping` never has to arbitrate between two live workers.
 */
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();
