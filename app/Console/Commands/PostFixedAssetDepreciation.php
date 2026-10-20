<?php

namespace App\Console\Commands;

use App\Services\FixedAssetService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PostFixedAssetDepreciation extends Command
{
    protected $signature = 'assets:post-depreciation {month? : YYYY-MM, default this month}';

    protected $description = 'Post monthly straight-line fixed asset depreciation';

    public function handle(FixedAssetService $assets): int
    {
        $input = $this->argument('month') ?? now()->format('Y-m');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $input)) {
            $this->error('Bulan harus YYYY-MM.');

            return self::FAILURE;
        }
        $count = $assets->postMonthly(Carbon::createFromFormat('Y-m-d', $input.'-01'));
        $this->info("Penyusutan diposting: {$count} aset.");

        return self::SUCCESS;
    }
}
