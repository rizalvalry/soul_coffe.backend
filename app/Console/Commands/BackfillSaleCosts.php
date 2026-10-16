<?php

namespace App\Console\Commands;

use App\Models\DirectSaleLine;
use App\Models\SaleLine;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BackfillSaleCosts extends Command
{
    protected $signature = 'soul:backfill-sale-costs {--dry-run : Count fillable lines without changing data}';

    protected $description = 'Fill missing sale cost snapshots from the price version effective at each transaction';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        foreach ([
            [SaleLine::class, 'sale', 'sale_lines'],
            [DirectSaleLine::class, 'directSale', 'direct_sale_lines'],
        ] as [$lineClass, $parentRelation, $table]) {
            $fillable = 0;
            $unfilled = 0;

            $lineClass::query()
                ->whereNull('unit_cost_minor')
                ->with(['product', $parentRelation])
                ->chunkById(500, function ($lines) use ($parentRelation, $table, $dryRun, &$fillable, &$unfilled): void {
                    foreach ($lines as $line) {
                        /** @var Model $parent */
                        $parent = $line->{$parentRelation};
                        $version = $line->product?->priceVersionAt($parent->occurred_at, knownAtTime: true);
                        // A mismatched selling price cannot identify the historical HPP safely.
                        $cost = $version && $version->sell_price_minor === $line->unit_price_minor
                            ? $version->cost_price_minor
                            : null;

                        if ($cost === null) {
                            $unfilled++;

                            continue;
                        }

                        if ($dryRun) {
                            $fillable++;

                            continue;
                        }

                        // Conditional update also protects a concurrent backfill from overwriting a snapshot.
                        $fillable += DB::table($table)
                            ->where('id', $line->id)
                            ->whereNull('unit_cost_minor')
                            ->update(['unit_cost_minor' => $cost]);
                    }
                });

            $this->info(sprintf(
                '%s: %s %d, tidak dapat diisi %d.',
                $table,
                $dryRun ? 'dapat diisi' : 'diisi',
                $fillable,
                $unfilled,
            ));
        }

        return self::SUCCESS;
    }
}
