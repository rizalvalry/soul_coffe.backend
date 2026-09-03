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
