<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Services\EventPublisher;
use App\Services\StockReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        [$findings, $newCount, $sent] = DB::transaction(function () use ($date, $reconciler, $events): array {
            DB::table('stock_reconciliation_results')->insertOrIgnore([
                'checked_on' => $date, 'checked_at' => now(),
                'status' => 'PENDING', 'finding_count' => 0, 'findings' => '[]', 'history' => '[]',
                'pending_cost_rows' => 0, 'pending_cost_estimated_minor' => 0,
                'findings_hash' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $current = DB::table('stock_reconciliation_results')->where('checked_on', $date)
                ->lockForUpdate()->first();
            $previous = DB::table('stock_reconciliation_results')->where('status', '!=', 'PENDING')
                ->orderByDesc('checked_at')->orderByDesc('id')->first();
            $previousActive = [];
            if ($previous !== null) {
                foreach (json_decode($previous->findings, true, 512, JSON_THROW_ON_ERROR) as $prior) {
                    $previousActive[$prior['baseKey'] ?? $prior['key'] ?? $reconciler->legacyFindingKey($prior)] = $prior;
                }
            }
            $saved = $reconciler->savedFindings();
            $findings = $reconciler->findings();
            $pending = $reconciler->pendingCostSummary();
            $new = [];
            foreach ($findings as $index => $finding) {
                $baseKey = $finding['key'];
                $findings[$index]['baseKey'] = $baseKey;
                if (in_array($finding['code'], ['BOOK_MISMATCH', 'TOTAL_MISMATCH',
                    'DISPLAY_MISMATCH', 'NEGATIVE_BALANCE'], true)) {
                    $prior = $previousActive[$baseKey] ?? null;
                    if ($prior !== null && ! $this->worsened($finding, $prior)) {
                        $findings[$index]['key'] = $prior['key'] ?? $reconciler->legacyFindingKey($prior);
                    } elseif ($prior !== null || collect($saved)->contains(
                        fn (array $entry): bool => ($entry['baseKey'] ?? $entry['key']) === $baseKey
                    )) {
                        // A cleared or worsening aggregate gap is a fresh incident.
                        $findings[$index]['key'] = (string) Str::uuid();
                    }
                }
                $legacyKey = $reconciler->legacyFindingKey($finding);
                $legacy = $saved[$legacyKey] ?? null;
                $legacyMatch = $finding['code'] === 'UNPAIRED_TRANSFER'
                    && isset($legacy['legacyObservedAt'])
                    && ($finding['eventAt'] ?? '') <= $legacy['legacyObservedAt'];
                $findings[$index]['reportedAt'] = $saved[$findings[$index]['key']]['reportedAt']
                    ?? ($legacyMatch ? $legacy['reportedAt']
                        : ($finding['code'] !== 'UNPAIRED_TRANSFER' && $findings[$index]['key'] === $baseKey
                            ? ($legacy['reportedAt'] ?? null) : null));
                if ($findings[$index]['reportedAt'] === null) {
                    $new[] = $index;
                }
            }
            $sent = false;
            if (config('soul.stock_reconcile_alerts') && $new !== []) {
                $recipients = array_values(array_unique(array_merge(
                    User::query()->where('role', Role::FINANCE)->where('is_active', true)->pluck('id')->all(),
                    User::managementIds(),
                )));
                if ($recipients !== []) {
                    $newFindings = array_map(fn (int $index): array => $findings[$index], $new);
                    $detail = collect($newFindings)->take(3)->map(function (array $finding): string {
                        $detail = $finding['locationLabel'].' · '.$finding['itemLabel'].' · ledger '.$finding['ledger']
                            .' vs tampilan/buku besar '.($finding['compared'] ?? 'belum ada');
                        if ($finding['code'] === 'BOOK_MISMATCH') {
                            $detail .= '; riwayat lokasi/produk untuk ditelusuri: '
                                .($finding['stockCandidates'] ?: 'belum ada riwayat ledger')
                                .' (sumber selisih belum pasti)';
                        }

                        return $detail;
                    })->implode('; ');
                    if (count($new) > 3) {
                        $detail .= '; +'.(count($new) - 3).' selisih baru lain';
                    }
                    $events->publish('StockReconciliationMismatch', 'Integritas stok perlu dicek',
                        $detail.'.',
                        array_map(fn (int $id): string => 'user.'.$id, $recipients),
                        $recipients, status: 'MISMATCH', includeActorPush: true);
                    foreach ($new as $index) {
                        $findings[$index]['reportedAt'] = now()->toDateTimeString();
                    }
                    $sent = true;
                }
            }

            $history = [];
            foreach (array_merge(
                json_decode($current->history ?? '[]', true, 512, JSON_THROW_ON_ERROR),
                json_decode($current->findings, true, 512, JSON_THROW_ON_ERROR),
            ) as $entry) {
                $history[$entry['key'] ?? $reconciler->legacyFindingKey($entry)] = $entry;
            }
            DB::table('stock_reconciliation_results')->where('checked_on', $date)->update(
                ['checked_at' => now(), 'status' => $findings === [] ? 'BALANCED' : 'MISMATCH',
                    'finding_count' => count($findings), 'findings' => json_encode($findings, JSON_THROW_ON_ERROR),
                    'history' => json_encode(array_values($history), JSON_THROW_ON_ERROR),
                    'pending_cost_rows' => $pending['rows'],
                    'pending_cost_estimated_minor' => $pending['estimated_minor'],
                    'findings_hash' => hash('sha256', json_encode($findings, JSON_THROW_ON_ERROR)),
                    'updated_at' => now()],
            );

            return [$findings, count($new), $sent];
        });

        $status = ! config('soul.stock_reconcile_alerts') ? 'notifikasi mati'
            : ($sent ? $newCount.' temuan baru dinotifikasi' : '0 notifikasi baru');
        $this->outputFindings($findings, $date, $status);

        return self::SUCCESS;
    }

    private function worsened(array $current, array $prior): bool
    {
        $currentGap = (int) $current['ledger'] - (int) ($current['compared'] ?? 0);
        $priorGap = (int) $prior['ledger'] - (int) ($prior['compared'] ?? 0);

        return ($currentGap < 0) !== ($priorGap < 0) || abs($currentGap) > abs($priorGap);
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
