<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\EventPublisher;
use App\Services\StockReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReconcileStock extends Command
{
    protected $signature = 'soul:stock-reconcile {--dry-run : Cek saja tanpa menyimpan hasil atau mengirim notifikasi}';

    protected $description = 'Cek keseimbangan stok, tampilan, dan buku besar tanpa mengubah stok';

    public function handle(StockReconciliationService $reconciler, EventPublisher $events): int
    {
        $date = today()->toDateString();
        if (DB::connection()->getDriverName() === 'mysql' && DB::transactionLevel() === 0) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        if ($this->option('dry-run')) {
            $this->outputFindings($reconciler->findings(), $date, 'dry-run');

            return self::SUCCESS;
        }
        [$findings, $changed] = DB::transaction(function () use ($date, $reconciler, $events): array {
            DB::table('stock_reconciliation_results')->insertOrIgnore([
                'checked_on' => $date, 'checked_at' => now(),
                'status' => 'PENDING', 'finding_count' => 0, 'findings' => '[]',
                'pending_cost_rows' => 0, 'pending_cost_estimated_minor' => 0,
                'findings_hash' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $previous = DB::table('stock_reconciliation_results')->where('checked_on', $date)
                ->lockForUpdate()->first();
            $findings = $reconciler->findings();
            $pending = $reconciler->pendingCostSummary();
            $hash = hash('sha256', json_encode($findings, JSON_THROW_ON_ERROR));
            DB::table('stock_reconciliation_results')->where('checked_on', $date)->update(
                ['checked_at' => now(), 'status' => $findings === [] ? 'BALANCED' : 'MISMATCH',
                    'finding_count' => count($findings), 'findings' => json_encode($findings, JSON_THROW_ON_ERROR),
                    'pending_cost_rows' => $pending['rows'],
                    'pending_cost_estimated_minor' => $pending['estimated_minor'],
                    'findings_hash' => $hash, 'updated_at' => now()],
            );
            if ($findings === [] || $previous?->findings_hash === $hash) {
                return [$findings, false];
            }

            $recipients = array_values(array_unique(array_merge(
                User::query()->where('role', Role::FINANCE)->where('is_active', true)->pluck('id')->all(),
                User::managementIds(),
            )));
            if ($recipients !== []) {
                $detail = collect($findings)->take(3)->map(function (array $finding): string {
                    $detail = $finding['locationLabel'].' · '.$finding['itemLabel'].' · ledger '.$finding['ledger']
                        .' vs tampilan/buku besar '.($finding['compared'] ?? 'belum ada');
                    if ($finding['code'] === 'BOOK_MISMATCH') {
                        $detail .= '; riwayat lokasi/produk untuk ditelusuri: '
                            .($finding['stockCandidates'] ?: 'belum ada riwayat ledger')
                            .' (sumber selisih belum pasti)';
                    }

                    return $detail;
                })->implode('; ');
                if (count($findings) > 3) {
                    $detail .= '; +'.(count($findings) - 3).' selisih lain';
                }
                $events->publish('StockReconciliationMismatch', 'Integritas stok perlu dicek',
                    $detail.'.',
                    array_map(fn (int $id): string => 'user.'.$id, $recipients),
                    $recipients, status: 'MISMATCH', includeActorPush: true);
            }

            return [$findings, true];
        });

        $this->outputFindings($findings, $date, $changed ? 'notifikasi baru' : 'notifikasi tetap');

        return self::SUCCESS;
    }

    /** @param array<int, array<string, int|string|null>> $findings */
    private function outputFindings(array $findings, string $date, string $status): void
    {
        $this->line(($findings === [] ? 'Seimbang' : 'Selisih: '.count($findings)).' | '.$date.' | '.$status);
        foreach ($findings as $finding) {
            $this->line(json_encode($finding, JSON_THROW_ON_ERROR));
        }
    }
}
