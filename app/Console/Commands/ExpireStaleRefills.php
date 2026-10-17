<?php

namespace App\Console\Commands;

use App\Services\RefillExpiryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ExpireStaleRefills extends Command
{
    protected $signature = 'refills:expire-stale {--dry-run : List affected requests without changing data}';

    protected $description = 'Expire unfinished refill requests from past operating dates';

    public function handle(RefillExpiryService $expiry): int
    {
        if ($this->option('dry-run')) {
            $rows = $expiry->staleQuery()->orderBy('id')->get(['code', 'status', 'operating_date']);
            foreach ($rows as $row) {
                $this->line(sprintf('%s | %s | %d hari', $row->code, $row->status->value,
                    (int) $row->operating_date->diffInDays(Carbon::today())));
            }
            $this->info('Akan diproses: '.$rows->count());

            return self::SUCCESS;
        }

        $this->info('Diproses: '.$expiry->expire());

        return self::SUCCESS;
    }
}
