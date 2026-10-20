<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\JournalEntry;
use App\Models\TaxRemittance;
use App\Models\TaxSettingVersion;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TaxService
{
    private const TAX_DATE_SQL = 'COALESCE(closing.operating_date, original_closing.operating_date, original.entry_date, entry.entry_date)';

    public function __construct(private readonly JournalService $journals) {}

    public function settingAt(string $type, string $date, bool $currentRead = false): ?TaxSettingVersion
    {
        $query = TaxSettingVersion::query()->where('tax_type', $type)
            ->whereDate('effective_from', '<=', $date)->orderByDesc('effective_from');
        $version = ($currentRead ? $query->lockForUpdate() : $query)->first();

        return $version && ($version->effective_until === null || $version->effective_until->toDateString() >= $date)
            ? $version : null;
    }

    public function saveSetting(User $actor, array $input): TaxSettingVersion
    {
        Gate::forUser($actor)->authorize('manage-tax-settings');
        $data = Validator::make($input, [
            'tax_type' => ['required', 'in:PBJT,PPH'],
            'is_active' => ['required', 'boolean'],
            'rate_bps' => ['required', 'integer', 'between:0,10000'],
            'price_mode' => ['nullable', 'in:INCLUDED'],
            'scheme' => ['nullable', 'in:FINAL,GENERAL'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'legal_note' => ['nullable', 'string', 'max:2000'],
            'monthly_threshold_minor' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ])->validate();
        if ($data['is_active'] && $data['rate_bps'] === 0) {
            throw ValidationException::withMessages(['rate_bps' => 'Isi tarif lebih dari 0% sebelum diaktifkan, ya.']);
        }
        if ($data['tax_type'] === 'PBJT' && ($data['scheme'] ?? null) !== null) {
            throw ValidationException::withMessages(['scheme' => 'Skema hanya untuk PPh Badan.']);
        }
        if ($data['tax_type'] === 'PPH' && (($data['price_mode'] ?? null) !== null || ! in_array($data['scheme'] ?? null, ['FINAL', 'GENERAL'], true))) {
            throw ValidationException::withMessages(['scheme' => 'Pilih skema PPh Badan yang tersedia, ya.']);
        }
        if ($data['tax_type'] === 'PBJT' && ($data['price_mode'] ?? null) !== 'INCLUDED') {
            throw ValidationException::withMessages(['price_mode' => 'Saat ini pilih harga sudah termasuk pajak, ya.']);
        }
        if ($data['effective_from'] < today()->toDateString()) {
            throw ValidationException::withMessages(['effective_from' => 'Tanggal berlaku tidak boleh sebelum hari ini, ya.']);
        }

        return DB::transaction(function () use ($actor, $data): TaxSettingVersion {
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            if (TaxSettingVersion::query()->where('tax_type', $data['tax_type'])
                ->whereDate('effective_from', $data['effective_from'])->exists()) {
                throw ValidationException::withMessages(['effective_from' => 'Sudah ada versi tarif untuk tanggal ini. Pilih tanggal lain, ya.']);
            }
            if ($data['effective_from'] === today()->toDateString()
                && JournalEntry::query()->whereDate('entry_date', $data['effective_from'])
                    ->whereIn('source_type', ['settlement', 'direct_sale'])->exists()) {
                throw ValidationException::withMessages(['effective_from' => 'Sudah ada penjualan yang dijurnal hari ini. Jadwalkan perubahan pajak mulai besok, ya.']);
            }
            $previous = $this->settingAt($data['tax_type'], $data['effective_from'], true);
            $version = TaxSettingVersion::query()->create([...$data, 'created_by' => $actor->id]);
            AuditLog::recordAction($actor, 'tax.setting_changed', $version,
                $previous?->only(['is_active', 'rate_bps', 'price_mode', 'scheme', 'effective_from', 'effective_until', 'legal_note', 'monthly_threshold_minor']),
                [...$version->only(['tax_type', 'is_active', 'rate_bps', 'price_mode', 'scheme', 'effective_from', 'effective_until', 'legal_note', 'monthly_threshold_minor']), 'reason' => $data['reason']]);

            return $version;
        });
    }

    /** @return array{revenue:int,tax:int} */
    public function splitPbjt(int $gross, string $operatingDate): array
    {
        $setting = $this->settingAt('PBJT', $operatingDate, true);
        if (! $setting?->is_active || $gross <= 0) {
            return ['revenue' => $gross, 'tax' => 0];
        }
        $denominator = 10000 + $setting->rate_bps;
        $tax = intdiv($gross * $setting->rate_bps + intdiv($denominator, 2), $denominator);

        return ['revenue' => $gross - $tax, 'tax' => $tax];
    }

    public function monthlyReport(string $type, string $month): array
    {
        Validator::make(['month' => $month, 'type' => $type], [
            'month' => ['required', 'date_format:Y-m'], 'type' => ['required', 'in:PBJT,PPH'],
        ])->validate();
        $from = $month.'-01';
        $to = Carbon::parse($from)->endOfMonth()->toDateString();
        $revenueRows = $this->taxableJournalLines($from, $to)
            ->whereIn('account.code', ['4-1100', '4-1200'])
            ->groupByRaw(self::TAX_DATE_SQL)
            ->selectRaw(self::TAX_DATE_SQL.' AS tax_date, SUM(line.credit_minor) AS credits, SUM(line.debit_minor) AS debits')
            ->get();
        $base = (int) $revenueRows->sum(fn ($row): int => (int) $row->credits - (int) $row->debits);
        $liability = $type === 'PBJT' ? '2-1500' : '2-1600';
        $liabilityQuery = DB::table('journal_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.entry_id')
            ->leftJoin('journal_entries as original', 'original.id', '=', 'entry.reverses_entry_id')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->whereBetween('entry.entry_date', [$from, $to])->where('account.code', $liability);
        $posted = (clone $liabilityQuery)
            ->where('entry.source_type', '!=', 'tax_remittance')
            ->where(fn ($query) => $query->where('entry.source_type', '!=', 'journal_reversal')
                ->orWhere('original.source_type', '!=', 'tax_remittance'))
            ->selectRaw('COALESCE(SUM(line.credit_minor), 0) AS credits, COALESCE(SUM(line.debit_minor), 0) AS debits')->first();
        $due = (int) $posted->credits - (int) $posted->debits;
        $salesSources = ['settlement', 'direct_sale', 'rider_closing_revision'];
        $salesTax = (clone $liabilityQuery)
            ->where(fn ($query) => $query->whereIn('entry.source_type', $salesSources)
                ->orWhere(fn ($reversal) => $reversal->where('entry.source_type', 'journal_reversal')
                    ->whereIn('original.source_type', $salesSources)))
            ->selectRaw('COALESCE(SUM(line.credit_minor), 0) - COALESCE(SUM(line.debit_minor), 0) AS amount')->value('amount');
        $finalSources = ['tax_final', 'tax_final_adjustment'];
        $finalPosted = (clone $liabilityQuery)
            ->where(fn ($query) => $query->whereIn('entry.source_type', $finalSources)
                ->orWhere(fn ($reversal) => $reversal->where('entry.source_type', 'journal_reversal')
                    ->whereIn('original.source_type', $finalSources)))
            ->selectRaw('COALESCE(SUM(line.credit_minor), 0) - COALESCE(SUM(line.debit_minor), 0) AS amount')->value('amount');
        $setting = $this->settingAt($type, $to);
        $thresholdSetting = $type === 'PBJT' ? TaxSettingVersion::query()
            ->where('tax_type', 'PBJT')->whereDate('effective_from', '<=', $to)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $from))
            ->whereNotNull('monthly_threshold_minor')->orderByDesc('effective_from')->first() : null;
        $gross = $type === 'PBJT' ? $base + (int) $salesTax : $base;
        $finalEstimate = 0;
        $generalEstimate = 0;
        $profit = 0;
        if ($type === 'PPH') {
            $finalSegments = [];
            foreach ($revenueRows as $row) {
                $daySetting = $this->settingAt('PPH', $row->tax_date);
                if ($daySetting?->is_active && $daySetting->scheme === 'FINAL') {
                    $finalSegments[$daySetting->id]['amount'] = ($finalSegments[$daySetting->id]['amount'] ?? 0)
                        + (int) $row->credits - (int) $row->debits;
                    $finalSegments[$daySetting->id]['rate'] = $daySetting->rate_bps;
                }
            }
            foreach ($finalSegments as $segment) {
                $finalEstimate += $this->roundBps($segment['amount'], $segment['rate']);
            }
            $profitByDay = $this->taxableJournalLines($from, $to)
                ->whereIn('account.type', ['REVENUE', 'COGS', 'EXPENSE'])
                ->where('account.code', '!=', '6-1950')
                ->groupByRaw(self::TAX_DATE_SQL)
                ->selectRaw(self::TAX_DATE_SQL.' AS tax_date, SUM(line.credit_minor) AS credits, SUM(line.debit_minor) AS debits')->get();
            $generalSegments = [];
            foreach ($profitByDay as $row) {
                $daySetting = $this->settingAt('PPH', $row->tax_date);
                if (! $daySetting?->is_active || $daySetting->scheme !== 'GENERAL') {
                    continue;
                }
                $generalSegments[$daySetting->id]['profit'] = ($generalSegments[$daySetting->id]['profit'] ?? 0)
                    + (int) $row->credits - (int) $row->debits;
                $generalSegments[$daySetting->id]['rate'] = $daySetting->rate_bps;
            }
            foreach ($generalSegments as $segment) {
                $profit += $segment['profit'];
                $generalEstimate += $this->roundBps(max(0, $segment['profit']), $segment['rate']);
            }
        }
        $paid = (clone $liabilityQuery)
            ->where(fn ($query) => $query->where('entry.source_type', 'tax_remittance')
                ->orWhere(fn ($reversal) => $reversal->where('entry.source_type', 'journal_reversal')
                    ->where('original.source_type', 'tax_remittance')))
            ->selectRaw('COALESCE(SUM(line.debit_minor), 0) AS debits, COALESCE(SUM(line.credit_minor), 0) AS credits')->first();

        return [
            'month' => $month, 'gross_minor' => $gross, 'base_minor' => $base,
            'due_minor' => $due, 'paid_minor' => (int) $paid->debits - (int) $paid->credits,
            'estimate_minor' => $finalEstimate + $generalEstimate,
            'final_estimate_minor' => $finalEstimate, 'general_estimate_minor' => $generalEstimate,
            'final_posted_minor' => (int) $finalPosted, 'profit_minor' => $profit,
            'threshold_minor' => $thresholdSetting?->monthly_threshold_minor,
            'threshold_version_from' => $thresholdSetting?->effective_from?->toDateString(),
            'setting' => $setting,
        ];
    }

    public function postFinalPph(User $actor, string $month): JournalEntry
    {
        Gate::forUser($actor)->authorize('manage-tax-settings');

        return DB::transaction(function () use ($actor, $month): JournalEntry {
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $existing = JournalEntry::query()->where('source_type', 'tax_final')
                ->where('source_id', (int) str_replace('-', '', $month))->where('purpose', 'PPH_FINAL')->first();
            if ($existing?->reversal()->exists()) {
                throw ValidationException::withMessages(['month' => 'Jurnal PPh Final bulan ini sudah dibalik. Koreksi lewat Jurnal Manual, ya.']);
            }
            $report = $this->monthlyReport('PPH', $month);
            $target = $report['final_estimate_minor'];
            if (! $existing && $target === 0) {
                throw ValidationException::withMessages(['month' => 'Belum ada PPh Final aktif dan terutang untuk bulan ini, ya.']);
            }
            $latest = JournalEntry::query()->where('source_type', 'tax_final_adjustment')
                ->where('entry_date', Carbon::parse($month.'-01')->endOfMonth()->toDateString())
                ->orderByDesc('id')->first() ?? $existing;
            if ($latest?->reversal()->exists()) {
                throw ValidationException::withMessages(['month' => 'Jurnal koreksi PPh Final sudah dibalik. Cek Jurnal Manual, ya.']);
            }
            $delta = $target - $report['final_posted_minor'];
            if ($delta === 0) {
                return $latest;
            }
            $accounts = Account::query()->whereIn('code', ['6-1950', '2-1600'])->pluck('id', 'code');
            $entry = $this->journals->post([
                'entry_date' => Carbon::parse($month.'-01')->endOfMonth()->toDateString(),
                'description' => $existing ? 'Koreksi PPh Final bulan '.$month : 'PPh Final bulan '.$month,
                'source_type' => $existing ? 'tax_final_adjustment' : 'tax_final',
                'source_id' => $existing ? $latest->id : (int) str_replace('-', '', $month),
                'purpose' => $existing ? 'PPH_FINAL_ADJUSTMENT' : 'PPH_FINAL', 'posted_by' => $actor->id,
            ], [
                ['account_id' => $accounts['6-1950'], 'debit_minor' => max(0, $delta), 'credit_minor' => max(0, -$delta)],
                ['account_id' => $accounts['2-1600'], 'debit_minor' => max(0, -$delta), 'credit_minor' => max(0, $delta)],
            ]);
            AuditLog::recordAction($actor, $existing ? 'tax.final_adjusted' : 'tax.final_recorded', $entry,
                ['posted_minor' => $report['final_posted_minor']], ['month' => $month, 'posted_minor' => $target, 'delta_minor' => $delta]);

            return $entry;
        });
    }

    public function remit(User $actor, array $input): TaxRemittance
    {
        Gate::forUser($actor)->authorize('manage-tax-settings');
        $data = Validator::make($input, [
            'tax_type' => ['required', 'in:PBJT,PPH'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'receipt_number' => ['required', 'string', 'max:255'],
            'cash_account_id' => ['required', 'integer', 'exists:cash_accounts,id'],
            'idempotency_key' => ['required', 'string', 'max:80'],
        ])->validate();

        return DB::transaction(function () use ($actor, $data): TaxRemittance {
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $existing = TaxRemittance::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                foreach ($data as $key => $value) {
                    $stored = $key === 'paid_on' ? $existing->paid_on->toDateString() : (string) $existing->{$key};
                    if ($stored !== (string) $value) {
                        throw ValidationException::withMessages(['idempotency_key' => 'Kunci setor ini sudah dipakai untuk data lain, ya.']);
                    }
                }

                return $existing;
            }
            $cash = CashAccount::query()->whereKey($data['cash_account_id'])->where('is_active', true)->first();
            if (! $cash || ! in_array($cash->account?->code, ['1-1100', '1-1200', '1-1300'], true)) {
                throw ValidationException::withMessages(['cash_account_id' => 'Pilih akun kas atau bank yang aktif, ya.']);
            }
            $liabilityCode = $data['tax_type'] === 'PBJT' ? '2-1500' : '2-1600';
            $accounts = Account::query()->whereIn('code', [$liabilityCode, $cash->account->code])->pluck('id', 'code');
            $remittance = TaxRemittance::query()->create([...$data, 'status' => 'POSTED', 'created_by' => $actor->id]);
            $this->journals->post([
                'entry_date' => $data['paid_on'], 'description' => 'Setor '.$data['tax_type'].' bukti '.$data['receipt_number'],
                'source_type' => 'tax_remittance', 'source_id' => $remittance->id,
                'purpose' => 'TAX_REMITTANCE', 'posted_by' => $actor->id,
            ], [
                ['account_id' => $accounts[$liabilityCode], 'debit_minor' => $data['amount_minor'], 'credit_minor' => 0],
                ['account_id' => $accounts[$cash->account->code], 'debit_minor' => 0, 'credit_minor' => $data['amount_minor']],
            ]);
            AuditLog::recordAction($actor, 'tax.remitted', $remittance, null, $data);

            return $remittance;
        });
    }

    public function voidRemittance(User $actor, TaxRemittance $remittance, string $reason): TaxRemittance
    {
        Gate::forUser($actor)->authorize('manage-tax-settings');
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:225']])->validate();

        return DB::transaction(function () use ($actor, $remittance, $reason): TaxRemittance {
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $locked = TaxRemittance::query()->whereKey($remittance->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'VOID') {
                return $locked;
            }
            $entry = JournalEntry::query()->where('source_type', 'tax_remittance')->where('source_id', $locked->id)->firstOrFail();
            $reversal = $this->journals->reverse($entry, $reason);
            $locked->update(['status' => 'VOID', 'voided_by' => $actor->id, 'void_reason' => $reason]);
            AuditLog::recordAction($actor, 'tax.remittance_voided', $locked, ['status' => 'POSTED'],
                ['status' => 'VOID', 'reversal_entry_id' => $reversal->id, 'reason' => $reason]);

            return $locked;
        });
    }

    private function taxableJournalLines(string $from, string $to): Builder
    {
        return DB::table('journal_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.entry_id')
            ->leftJoin('journal_entries as original', 'original.id', '=', 'entry.reverses_entry_id')
            ->leftJoin('rider_closing_revisions as revision', fn (JoinClause $join) => $join
                ->on('revision.id', '=', 'entry.source_id')->where('entry.source_type', '=', 'rider_closing_revision'))
            ->leftJoin('rider_closings as closing', 'closing.id', '=', 'revision.rider_closing_id')
            ->leftJoin('rider_closing_revisions as original_revision', fn (JoinClause $join) => $join
                ->on('original_revision.id', '=', 'original.source_id')->where('original.source_type', '=', 'rider_closing_revision'))
            ->leftJoin('rider_closings as original_closing', 'original_closing.id', '=', 'original_revision.rider_closing_id')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->whereBetween('entry.entry_date', [$from, $to]);
    }

    private function roundBps(int $amount, int $rateBps): int
    {
        $rounded = intdiv(abs($amount) * $rateBps + 5000, 10000);

        return $amount < 0 ? -$rounded : $rounded;
    }
}
