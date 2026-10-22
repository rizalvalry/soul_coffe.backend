<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AccountingPeriodService
{
    public function closeReadiness(int $year, int $month): array
    {
        if ($year < 2000 || $year > 9999 || $month < 1 || $month > 12) {
            throw ValidationException::withMessages(['period' => 'Periode tidak valid.']);
        }
        $start = Carbon::create($year, $month, 1)->toDateString();
        $end = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $pendingSettlements = DB::table('settlements')->whereBetween('operating_date', [$start, $end])
            ->where('status', '!=', 'RECONCILED')->count();
        $missingSettlements = DB::table('rider_closings as closing')
            ->whereBetween('closing.operating_date', [$start, $end])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('settlements as settlement')
                ->whereColumn('settlement.cart_id', 'closing.cart_id')
                ->whereColumn('settlement.operating_date', 'closing.operating_date'))->count();
        $clearingDays = collect(app(ClearingService::class)->aging($end)['rows'])
            ->filter(fn (array $row): bool => $row['sale_date'] >= $start && $row['sale_date'] <= $end)->count();
        $pendingStock = DB::table('stock_ledger')->where('cost_pending', true)
            ->whereDate('created_at', '>=', $start)->whereDate('created_at', '<=', $end)
            ->whereNotIn('id', DB::table('stock_cost_resolutions')->select('stock_ledger_id'))->count();
        $postedDepreciation = DB::table('journal_entries')->where('source_type', 'fixed_asset_depreciation')
            ->where('purpose', 'DEPRECIATION_'.sprintf('%04d%02d', $year, $month))
            ->whereNotIn('id', DB::table('journal_entries')->where('source_type', 'journal_reversal')
                ->where('entry_date', '<=', $end)->select('reverses_entry_id'))->pluck('source_id')->all();
        $unpostedDepreciation = DB::table('fixed_assets')->whereDate('acquired_on', '<=', $end)
            ->where(fn ($query) => $query->where('status', 'ACTIVE')->orWhereDate('disposed_on', '>', $end))
            ->whereNotIn('id', $postedDepreciation)->get(['acquired_on', 'useful_life_months'])
            ->filter(function ($asset) use ($year, $month): bool {
                $acquired = Carbon::parse($asset->acquired_on);
                $elapsed = ($year - $acquired->year) * 12 + $month - $acquired->month;

                return $elapsed >= 0 && $elapsed < $asset->useful_life_months;
            })->count();
        $cashBalance = (int) DB::table('journal_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.entry_id')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->where('account.code', '1-1200')->where('entry.entry_date', '<=', $end)
            ->selectRaw('COALESCE(SUM(CAST(line.debit_minor AS SIGNED) - CAST(line.credit_minor AS SIGNED)), 0) AS balance')
            ->value('balance');

        return [
            ['code' => 'settlements_pending', 'status' => $pendingSettlements ? 'blocking' : 'ok',
                'count' => $pendingSettlements, 'description' => "$pendingSettlements setoran belum disetujui."],
            ['code' => 'rider_closings_missing', 'status' => $missingSettlements ? 'blocking' : 'ok',
                'count' => $missingSettlements, 'description' => "$missingSettlements Closing Rider belum punya setoran."],
            ['code' => 'clearing_outstanding', 'status' => $clearingDays ? 'warning' : 'ok',
                'count' => $clearingDays, 'description' => "$clearingDays hari penjualan belum lunas dana QRIS/transfernya."],
            ['code' => 'stock_cost_pending', 'status' => $pendingStock ? 'warning' : 'ok',
                'count' => $pendingStock, 'description' => "$pendingStock biaya stok masih tertunda."],
            ['code' => 'depreciation_unposted', 'status' => $unpostedDepreciation ? 'warning' : 'ok',
                'count' => $unpostedDepreciation, 'description' => "$unpostedDepreciation aset belum diposting penyusutannya."],
            ['code' => 'cash_settlement_balance', 'status' => $cashBalance > 0 ? 'warning' : 'ok',
                'count' => max(0, $cashBalance), 'description' => 'Saldo Kas Setoran per akhir bulan Rp '.number_format(max(0, $cashBalance), 0, ',', '.').'.'],
        ];
    }

    public function setStatus(int $year, int $month, string $status, User $actor, ?string $reason = null): AccountingPeriod
    {
        Gate::forUser($actor)->authorize('manage-accounting-periods');
        if ($year < 2000 || $year > 9999 || $month < 1 || $month > 12 || ! in_array($status, ['OPEN', 'CLOSED'], true)) {
            throw ValidationException::withMessages(['period' => 'Periode atau status tidak valid.']);
        }

        return DB::transaction(function () use ($year, $month, $status, $actor, $reason): AccountingPeriod {
            $period = app(JournalService::class)->lockPeriod(Carbon::create($year, $month, 1));
            if ($period->status === $status && $status !== 'CLOSED') {
                return $period;
            }
            $reason = trim((string) $reason);
            if ($status === 'OPEN' && ($reason === '' || mb_strlen($reason) > 255)) {
                throw ValidationException::withMessages(['reason' => 'Alasan buka ulang wajib diisi, maksimal 255 karakter ya.']);
            }
            $warnings = [];
            if ($status === 'CLOSED') {
                $readiness = $this->closeReadiness($year, $month);
                $blocking = array_values(array_filter($readiness, fn (array $item): bool => $item['status'] === 'blocking'));
                if ($blocking !== []) {
                    throw ValidationException::withMessages(['period' => 'Periode belum bisa ditutup: '.implode(' ', array_column($blocking, 'description'))]);
                }
                $warnings = array_values(array_filter($readiness, fn (array $item): bool => $item['status'] === 'warning'));
            }
            if ($period->status === $status) {
                return $period;
            }
            $before = ['status' => $period->status];
            $period->update([
                'status' => $status,
                'closed_by' => $status === 'CLOSED' ? $actor->id : null,
                'closed_at' => $status === 'CLOSED' ? now() : null,
            ]);
            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'actor_role' => $actor->role->value,
                'action' => $status === 'CLOSED' ? 'accounting_period.closed' : 'accounting_period.reopened',
                'subject_type' => AccountingPeriod::class,
                'subject_id' => $period->id,
                'before_json' => $before,
                'after_json' => ['status' => $status, 'reason' => $status === 'OPEN' ? $reason : null,
                    ...($status === 'CLOSED' ? ['warnings' => $warnings] : [])],
            ]);

            if ($status === 'OPEN') {
                $recipients = User::managementIds();
                app(EventPublisher::class)->publish(
                    'AccountingPeriodReopened', 'Periode akuntansi dibuka lagi',
                    sprintf('Periode %04d-%02d dibuka lagi. Alasan: %s', $year, $month, $reason),
                    ['role.MANAGEMENT'], $recipients, includeActorPush: true,
                );
            }

            return $period;
        }, 3);
    }
}
