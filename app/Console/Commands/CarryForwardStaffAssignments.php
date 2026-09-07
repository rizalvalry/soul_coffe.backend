<?php

namespace App\Console\Commands;

use App\Services\StaffAssignmentCarryForwardService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Clones yesterday's staff roster onto today. Scheduled for 00:00 in routes/console.php,
 * alongside soul:seed-daily-allowances.
 */
class CarryForwardStaffAssignments extends Command
{
    protected $signature = 'soul:carry-forward-staff-assignments
                            {--date= : Operating date (Y-m-d), defaults to today}';

    protected $description = "Copy yesterday's staff-cart-location roster onto today for every staff member without a row yet";

    public function handle(StaffAssignmentCarryForwardService $carryForward): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::today();

        $result = $carryForward->carryForward($date);

        $this->info(sprintf(
            'Penugasan staff %s: %d dibuat, %d staff sudah punya penugasan (dilewati), %d gerobak sudah ada penugasannya (dilewati), %d staff tidak aktif (dilewati).',
            $date->toDateString(),
            $result['created'],
            $result['skipped_user_conflict'],
            $result['skipped_cart_conflict'],
            $result['skipped_inactive'],
        ));

        return self::SUCCESS;
    }
}
