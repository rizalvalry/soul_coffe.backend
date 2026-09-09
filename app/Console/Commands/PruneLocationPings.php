<?php

namespace App\Console\Commands;

use App\Models\StaffLocationPing;
use App\Services\StaffLocationService;
use Illuminate\Console\Command;

/**
 * Drops GPS trail rows past the retention window. Scheduled nightly in routes/console.php.
 *
 * WHY THIS EXISTS AT ALL
 * ----------------------
 * `staff_location_pings` is the only table in this system that grows with time rather than with
 * business activity — a phone in a pocket writes rows whether or not anything is sold. On shared
 * hosting an unbounded table is a real outage waiting for a busy month, so the retention window
 * is part of the feature, not housekeeping bolted on afterwards.
 *
 * It prunes the raw trail only. The sales those positions helped explain are kept forever, and
 * the aggregates behind the engagement grid are computed from `sales`, not from this table — so a
 * pruned month still shows which area was busy at which hour. What is lost is street-level
 * movement, which is exactly the part that stops being useful once the month is closed.
 */
class PruneLocationPings extends Command
{
    protected $signature = 'soul:prune-location-pings
                            {--days= : Keep this many days, defaults to config soul.location_ping_retention_days}
                            {--dry-run : Report how many rows would be deleted without deleting them}';

    protected $description = 'Delete staff GPS trail rows older than the retention window';

    public function handle(StaffLocationService $locations): int
    {
        // `?:` would be wrong here: `--days=0` is falsy, so it would silently fall back to the
        // configured window instead of hitting the guard below — which is exactly the input the
        // guard exists for.
        $option = $this->option('days');
        $days = $option === null
            ? (int) config('soul.location_ping_retention_days', 45)
            : (int) $option;

        if ($days < 1) {
            $this->error('Retensi minimal 1 hari — perintah dibatalkan agar jejak hari ini tidak terhapus.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $count = StaffLocationPing::query()
                ->where('recorded_at', '<', now()->subDays($days))
                ->count();

            $this->info(sprintf('Dry run: %d baris jejak lebih tua dari %d hari.', $count, $days));

            return self::SUCCESS;
        }

        $deleted = $locations->prune($days);

        $this->info(sprintf('Jejak lokasi: %d baris lebih tua dari %d hari dihapus.', $deleted, $days));

        return self::SUCCESS;
    }
}
