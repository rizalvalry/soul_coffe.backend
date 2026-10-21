<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\ClearingAllocation;
use App\Models\ClearingReceipt;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ClearingService
{
    public function __construct(private readonly JournalService $journals) {}

    public function record(User $actor, array $input): ClearingReceipt
    {
        $this->authorize($actor);
        $data = Validator::make($input, [
            'uuid' => ['required', 'uuid'],
            'received_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'mdr_minor' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'bank_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'sale_dates' => ['sometimes', 'array', 'max:90'],
            'sale_dates.*' => ['date_format:Y-m-d', 'distinct'],
        ])->validate();
        $mdr = (int) ($data['mdr_minor'] ?? 0);
        $gross = (int) $data['amount_minor'] + $mdr;
        if ($gross > 1000000000000) {
            throw ValidationException::withMessages(['mdr_minor' => 'Total dana dan MDR maksimal Rp 1.000.000.000.000 ya.']);
        }
        $dates = $data['sale_dates'] ?? [];
        sort($dates);
        if (collect($dates)->contains(fn (string $date): bool => $date > $data['received_on'])) {
            throw ValidationException::withMessages(['sale_dates' => 'Pilih hari penjualan yang tidak melewati tanggal dana masuk ya.']);
        }

        return DB::transaction(function () use ($actor, $data, $dates, $mdr, $gross): ClearingReceipt {
            // The chart lock is shared by JournalService and serializes concurrent matching.
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $existing = ClearingReceipt::query()->where('uuid', $data['uuid'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->received_on->toDateString() !== $data['received_on']
                    || $existing->amount_minor !== (int) $data['amount_minor']
                    || $existing->mdr_minor !== $mdr || $existing->bank_account_id !== (int) $data['bank_account_id']
                    || ($existing->sale_dates_json ?? []) !== $dates) {
                    throw ValidationException::withMessages(['uuid' => 'ID dana masuk ini sudah dipakai untuk data lain.']);
                }

                return $existing->load('allocations');
            }
            $bank = Account::query()->findOrFail($data['bank_account_id']);
            if ($bank->code !== '1-1300' || ! $bank->is_active) {
                throw ValidationException::withMessages(['bank_account_id' => 'Rekening tujuan harus Bank yang aktif ya.']);
            }
            $accounts = Account::query()->whereIn('code', ['1-1400', '6-1990'])->pluck('id', 'code');
            $receipt = ClearingReceipt::query()->create([
                'uuid' => $data['uuid'], 'received_on' => $data['received_on'],
                'amount_minor' => $data['amount_minor'], 'mdr_minor' => $mdr,
                'sale_dates_json' => $dates, 'bank_account_id' => $bank->id,
                'recorded_by' => $actor->id, 'status' => 'POSTED',
            ]);
            $lines = [
                ['account_id' => $bank->id, 'debit_minor' => (int) $data['amount_minor'], 'credit_minor' => 0],
                ['account_id' => $accounts['1-1400'], 'debit_minor' => 0, 'credit_minor' => $gross],
            ];
            if ($mdr > 0) {
                $lines[] = ['account_id' => $accounts['6-1990'], 'debit_minor' => $mdr, 'credit_minor' => 0];
            }
            $this->journals->post([
                'entry_date' => $data['received_on'], 'description' => 'Dana QRIS/transfer masuk #'.$receipt->id,
                'source_type' => 'clearing_receipt', 'source_id' => $receipt->id,
                'purpose' => 'CLEARING_RECEIPT', 'posted_by' => $actor->id,
            ], $lines);

            // Match against current outstanding, including receipts posted later than this
            // backdated bank date. Otherwise a late entry can allocate the same sale twice.
            $openByDate = collect($this->aging(today()->toDateString())['rows'])->keyBy('sale_date');
            $remaining = $gross;
            foreach ($dates as $date) {
                $open = max(0, (int) ($openByDate->get($date)['outstanding_minor'] ?? 0));
                $allocated = min($open, $remaining);
                if ($allocated > 0) {
                    ClearingAllocation::query()->create([
                        'clearing_receipt_id' => $receipt->id, 'sale_date' => $date, 'amount_minor' => $allocated,
                        'effective_on' => $data['received_on'],
                    ]);
                    $remaining -= $allocated;
                }
            }
            AuditLog::recordAction($actor, 'clearing_receipt.created', $receipt, null, [
                'received_on' => $data['received_on'], 'amount_minor' => (int) $data['amount_minor'],
                'mdr_minor' => $mdr, 'sale_dates' => $dates, 'unmatched_minor' => $remaining,
            ]);

            return $receipt->load('allocations');
        }, 3);
    }

    public function void(User $actor, ClearingReceipt $receipt, string $reason): ClearingReceipt
    {
        $this->authorize($actor);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 225) {
            throw ValidationException::withMessages(['reason' => 'Isi alasan pembatalan, maksimal 225 karakter ya.']);
        }

        return DB::transaction(function () use ($actor, $receipt, $reason): ClearingReceipt {
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $locked = ClearingReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            if ($locked->status !== 'POSTED') {
                if ($locked->status === 'VOID' && $locked->void_reason === $reason) {
                    return $locked->load('allocations');
                }
                throw ValidationException::withMessages(['receipt' => 'Dana masuk ini sudah dibatalkan.']);
            }
            $entry = JournalEntry::query()->where('source_type', 'clearing_receipt')
                ->where('source_id', $locked->id)->where('purpose', 'CLEARING_RECEIPT')->firstOrFail();
            $this->journals->reverse($entry, $reason);
            $locked->update(['status' => 'VOID', 'voided_by' => $actor->id,
                'voided_at' => now(), 'void_reason' => $reason]);
            AuditLog::recordAction($actor, 'clearing_receipt.voided', $locked,
                ['status' => 'POSTED'], ['status' => 'VOID', 'reason' => $reason]);

            return $locked->fresh('allocations');
        }, 3);
    }

    public function match(User $actor, ClearingReceipt $receipt, array $input): ClearingReceipt
    {
        $this->authorize($actor);
        $data = Validator::make($input, [
            'uuid' => ['required', 'uuid'],
            'sale_dates' => ['required', 'array', 'min:1', 'max:90'],
            'sale_dates.*' => ['required', 'date_format:Y-m-d', 'distinct'],
        ])->validate();
        $dates = $data['sale_dates'];
        sort($dates);

        return DB::transaction(function () use ($actor, $receipt, $data, $dates): ClearingReceipt {
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $locked = ClearingReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $previous = DB::table('clearing_match_requests')->where('uuid', $data['uuid'])->first();
            if ($previous) {
                if ((int) $previous->clearing_receipt_id !== $locked->id
                    || json_decode($previous->sale_dates_json, true) !== $dates) {
                    throw ValidationException::withMessages(['uuid' => 'ID pencocokan ini sudah dipakai untuk data lain.']);
                }

                return $locked->load('allocations');
            }
            if ($locked->status !== 'POSTED') {
                throw ValidationException::withMessages(['receipt' => 'Dana yang dibatalkan tidak bisa dipasangkan.']);
            }
            if (collect($dates)->contains(fn (string $date): bool => $date > $locked->received_on->toDateString())) {
                throw ValidationException::withMessages(['sale_dates' => 'Hari penjualan tidak boleh lewat dari tanggal dana masuk ya.']);
            }
            $remaining = (int) $locked->amount_minor + (int) $locked->mdr_minor
                - (int) $locked->allocations()->sum('amount_minor');
            if ($remaining <= 0) {
                throw ValidationException::withMessages(['receipt' => 'Semua dana ini sudah dipasangkan.']);
            }
            $openByDate = collect($this->aging(today()->toDateString())['rows'])->keyBy('sale_date');
            $matched = 0;
            foreach ($dates as $date) {
                $open = max(0, (int) ($openByDate->get($date)['outstanding_minor'] ?? 0));
                $amount = min($open, $remaining);
                if ($amount > 0) {
                    ClearingAllocation::query()->create([
                        'clearing_receipt_id' => $locked->id, 'sale_date' => $date, 'amount_minor' => $amount,
                        'effective_on' => today()->toDateString(),
                    ]);
                    $remaining -= $amount;
                    $matched += $amount;
                }
            }
            if ($matched === 0) {
                throw ValidationException::withMessages(['sale_dates' => 'Hari yang dipilih belum punya saldo kliring. Pilih hari lain ya.']);
            }
            DB::table('clearing_match_requests')->insert([
                'uuid' => $data['uuid'], 'clearing_receipt_id' => $locked->id,
                'sale_dates_json' => json_encode($dates, JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            AuditLog::recordAction($actor, 'clearing_receipt.matched', $locked, null,
                ['sale_dates' => $dates, 'amount_minor' => $matched]);

            return $locked->fresh('allocations');
        }, 3);
    }

    public function aging(?string $asOf = null): array
    {
        $date = $asOf ?? today()->toDateString();
        Validator::make(['as_of' => $date], ['as_of' => ['required', 'date_format:Y-m-d']])->validate();
        $clearingAccountId = Account::query()->where('code', '1-1400')->value('id');
        $sourceRows = DB::table('journal_entries as j')
            ->join('journal_lines as l', 'l.entry_id', '=', 'j.id')
            ->where('l.account_id', $clearingAccountId)
            ->whereIn('j.source_type', ['settlement', 'direct_sale'])
            ->whereIn('j.purpose', ['SETTLEMENT', 'DIRECT_SALE'])
            ->where('j.entry_date', '<=', $date)
            ->whereNotIn('j.id', DB::table('journal_entries as r')->where('r.source_type', 'journal_reversal')
                ->where('r.entry_date', '<=', $date)->select('r.reverses_entry_id'))
            ->groupBy('j.entry_date')->select('j.entry_date')
            ->selectRaw('SUM(CAST(l.debit_minor AS SIGNED) - CAST(l.credit_minor AS SIGNED)) AS gross_minor')->get();
        $allocated = DB::table('clearing_allocations as a')
            ->join('clearing_receipts as r', 'r.id', '=', 'a.clearing_receipt_id')
            ->where('r.received_on', '<=', $date)->where('a.effective_on', '<=', $date)
            ->where(fn ($query) => $query->whereNull('r.voided_at')->orWhereDate('r.voided_at', '>', $date))
            ->groupBy('a.sale_date')->select('a.sale_date')
            ->selectRaw('SUM(a.amount_minor) AS total_minor')->pluck('total_minor', 'sale_date');
        $rows = [];
        $buckets = ['0_1_minor' => 0, '2_3_minor' => 0, 'over_3_minor' => 0];
        foreach ($sourceRows as $source) {
            $balance = (int) $source->gross_minor - (int) ($allocated[$source->entry_date] ?? 0);
            if ($balance <= 0) {
                continue;
            }
            $days = (int) Carbon::parse($source->entry_date)->diffInDays(Carbon::parse($date));
            $bucket = $days <= 1 ? '0_1_minor' : ($days <= 3 ? '2_3_minor' : 'over_3_minor');
            $buckets[$bucket] += $balance;
            $rows[] = ['sale_date' => $source->entry_date, 'outstanding_minor' => $balance,
                'age_days' => $days, 'bucket' => $bucket, 'flagged' => $days > 3];
        }
        usort($rows, fn (array $a, array $b): int => strcmp($a['sale_date'], $b['sale_date']));
        $receiptGross = (int) ClearingReceipt::query()->whereDate('received_on', '<=', $date)
            ->where(fn ($query) => $query->whereNull('voided_at')->orWhereDate('voided_at', '>', $date))
            ->selectRaw('COALESCE(SUM(amount_minor + mdr_minor), 0) AS gross_minor')->value('gross_minor');
        $unmatched = $receiptGross
            - (int) DB::table('clearing_allocations as a')->join('clearing_receipts as r', 'r.id', '=', 'a.clearing_receipt_id')
                ->where('r.received_on', '<=', $date)->where('a.effective_on', '<=', $date)
                ->where(fn ($query) => $query->whereNull('r.voided_at')->orWhereDate('r.voided_at', '>', $date))
                ->sum('a.amount_minor');

        $ledgerBalance = (int) DB::table('journal_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
            ->where('l.account_id', $clearingAccountId)->where('j.entry_date', '<=', $date)
            ->selectRaw('COALESCE(SUM(CAST(l.debit_minor AS SIGNED) - CAST(l.credit_minor AS SIGNED)), 0) AS balance')->value('balance');
        $unattributed = $ledgerBalance - (array_sum($buckets) - $unmatched);

        return ['as_of' => $date, 'rows' => $rows, 'buckets' => $buckets,
            'unmatched_credit_minor' => $unmatched,
            'unattributed_minor' => $unattributed,
            'net_balance_minor' => $ledgerBalance];
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->role->grants(Role::FINANCE), 403);
    }
}
