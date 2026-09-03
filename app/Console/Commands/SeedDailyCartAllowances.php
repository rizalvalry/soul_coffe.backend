<?php

namespace App\Console\Commands;

use App\Services\DailyAllowanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Writes today's operational allowance for every active cart. Scheduled for 00:00 in
 * routes/console.php.
 *
 * On this hosting the scheduler runs from a cron entry that can miss a minute or fire twice
 * (see PRODUCTION-ACCESS.md), so this is written to be safely re-runnable: the unique index on
 * (cart_id, operating_date) makes a repeat run report 0 created rather than double-paying a cart.
 */
class SeedDailyCartAllowances extends Command
{
    protected $signature = 'soul:seed-daily-allowances
                            {--date= : Operating date (Y-m-d), defaults to today}';

    protected $description = 'Write the daily operational allowance for every active cart';

    public function handle(DailyAllowanceService $allowances): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::today();

        $created = $allowances->ensureForAllCarts($date);

        $this->info(sprintf(
            'Uang harian %s: %d gerobak baru diisi (yang sudah ada dibiarkan).',
            $date->toDateString(),
            $created,
        ));

        return self::SUCCESS;
    }
}
