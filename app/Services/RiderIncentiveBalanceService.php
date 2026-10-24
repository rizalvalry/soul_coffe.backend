<?php

namespace App\Services;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\Account;
use App\Models\EmployeeAdvance;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RiderIncentiveBalanceService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly EmployeeAdvanceService $advances,
    ) {}

    public function accrue(object $lock, ?int $actorId = null): void
    {
        $amount = (int) $lock->commission_minor;
        if ($amount === 0 || DB::table('rider_incentive_movements')->where('commission_lock_id', $lock->id)->exists()) {
            return;
        }
        $journal = $this->journals->post([
            'entry_date' => $lock->operating_date,
            'description' => 'Akrual insentif Rider #'.$lock->rider_id.' '.$lock->operating_date,
            'source_type' => 'rider_commission_lock', 'source_id' => $lock->id,
            'purpose' => 'RIDER_INCENTIVE_ACCRUAL', 'posted_by' => $actorId,
        ], [
            $this->line('6-2300', $amount, 0),
            $this->line('2-1800', 0, $amount),
        ]);
        DB::table('rider_incentive_movements')->insertOrIgnore([
            'rider_id' => $lock->rider_id, 'entry_date' => $lock->operating_date,
            'kind' => 'ACCRUAL', 'amount_minor' => $amount,
            'commission_lock_id' => $lock->id, 'journal_entry_id' => $journal->id,
            'created_by' => $actorId, 'created_at' => now(),
        ]);
    }

    public function balance(int $riderId, ?string $through = null): int
    {
        $query = DB::table('rider_incentive_movements')->where('rider_id', $riderId);
        if ($through !== null) {
            $query->where('entry_date', '<=', $through);
        }

        return (int) $query->sum('amount_minor');
    }

    /** Canceled payroll movements remain in the audit ledger but do not consume payable balance. */
    public function payableBalance(int $riderId, string $through): int
    {
        return (int) DB::table('rider_incentive_movements as movement')
            ->leftJoin('payroll_runs as run', 'run.id', '=', 'movement.payroll_run_id')
            ->where('movement.rider_id', $riderId)->where('movement.entry_date', '<=', $through)
            ->where(function ($query): void {
                $query->whereNotIn('movement.kind', ['PAYROLL_PAYMENT', 'PAYROLL_REVERSAL'])
                    ->orWhere(function ($payment): void {
                        $payment->where('movement.kind', 'PAYROLL_PAYMENT')
                            ->whereIn('run.status', ['APPROVED', 'PAID'])
                            ->whereNotExists(fn ($reversal) => $reversal->selectRaw('1')
                                ->from('journal_entries as reversal')
                                ->whereColumn('reversal.reverses_entry_id', 'movement.journal_entry_id'));
                    });
            })->sum('movement.amount_minor');
    }

    /** @return array<int, int> */
    public function balances(array $riderIds, ?string $through = null): array
    {
        if ($riderIds === []) {
            return [];
        }
        $query = DB::table('rider_incentive_movements')->whereIn('rider_id', $riderIds);
        if ($through !== null) {
            $query->where('entry_date', '<=', $through);
        }

        return $query->groupBy('rider_id')->selectRaw('rider_id, SUM(amount_minor) AS balance_minor')
            ->pluck('balance_minor', 'rider_id')->map(fn ($amount): int => (int) $amount)->all();
    }

    /** Pre-feature locks have no accrual journal; pay them once as salary expense. */
    public function legacyAmount(int $riderId, string $from, string $through): int
    {
        return (int) DB::table('rider_daily_commission_locks as lock')
            ->leftJoin('rider_incentive_movements as movement', 'movement.commission_lock_id', '=', 'lock.id')
            ->where('lock.rider_id', $riderId)->whereBetween('lock.operating_date', [$from, $through])
            ->whereNull('movement.id')->sum('lock.commission_minor');
    }

    public function withdraw(array $input, User $actor): object
    {
        return $this->debit($input, $actor, 'WITHDRAWAL');
    }

    public function deduct(array $input, User $actor): object
    {
        return $this->debit($input, $actor, 'MANUAL_DEDUCTION');
    }

    public function repayAdvance(array $input, User $actor): object
    {
        return $this->debit($input, $actor, 'ADVANCE_REPAYMENT');
    }

    public function correct(array $input, User $actor): object
    {
        abort_unless(PermissionMatrix::can($actor, PanelModule::RIDER_INCENTIVE_BALANCE, 'edit'), 403);
        $data = Validator::make($input, [
            'request_key' => ['required', 'uuid'],
            'corrects_movement_id' => ['required', 'integer', 'exists:rider_incentive_movements,id'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:255'],
        ])->validate();
        $data['reason'] = trim($data['reason']);
        if ($data['reason'] === '' || $data['entry_date'] > today()->toDateString()) {
            throw ValidationException::withMessages(['reason' => 'Isi alasan dan tanggal koreksi yang valid ya.']);
        }
        $hash = hash('sha256', json_encode(['CORRECTION', $actor->id, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $actor, $hash): object {
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $original = DB::table('rider_incentive_movements')->where('id', $data['corrects_movement_id'])
                ->lockForUpdate()->firstOrFail();
            User::query()->whereKey($original->rider_id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('rider_incentive_operations')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                if ($existing->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['request_key' => 'Kunci permintaan ini sudah dipakai untuk koreksi lain ya.']);
                }

                return DB::table('rider_incentive_movements')->where('request_key', $data['request_key'])->first();
            }
            if (! in_array($original->kind, ['WITHDRAWAL', 'MANUAL_DEDUCTION', 'ADVANCE_REPAYMENT'], true)
                || $data['entry_date'] < $original->entry_date
                || DB::table('rider_incentive_movements')->where('corrects_movement_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['corrects_movement_id' => 'Mutasi ini tidak bisa dikoreksi lagi ya.']);
            }
            if ($original->kind === 'ADVANCE_REPAYMENT'
                && ! PermissionMatrix::can($actor, PanelModule::EMPLOYEE_ADVANCES, 'edit')) {
                abort(403);
            }
            $latest = DB::table('rider_incentive_movements')->where('rider_id', $original->rider_id)->max('entry_date');
            if ($latest !== null && $data['entry_date'] < $latest) {
                throw ValidationException::withMessages(['entry_date' => 'Tanggal koreksi harus sama atau setelah mutasi terakhir ya.']);
            }
            $counter = DB::table('journal_lines')->where('entry_id', $original->journal_entry_id)
                ->where('credit_minor', '>', 0)->first();
            if (! $counter) {
                throw ValidationException::withMessages(['corrects_movement_id' => 'Akun jurnal mutasi asli tidak ditemukan ya.']);
            }
            $amount = -(int) $original->amount_minor;
            $operationId = DB::table('rider_incentive_operations')->insertGetId([
                'request_key' => $data['request_key'], 'payload_hash' => $hash,
            ]);
            $journal = $this->journals->post([
                'entry_date' => $data['entry_date'],
                'description' => 'Koreksi mutasi insentif Rider #'.$original->rider_id,
                'source_type' => 'rider_incentive_operation', 'source_id' => $operationId,
                'purpose' => 'CORRECTION', 'posted_by' => $actor->id,
            ], [
                ['account_id' => $counter->account_id, 'debit_minor' => $amount, 'credit_minor' => 0],
                $this->line('2-1800', 0, $amount),
            ]);
            if ($original->kind === 'ADVANCE_REPAYMENT') {
                $advance = EmployeeAdvance::query()->findOrFail($original->advance_id);
                $this->advances->recordIncentiveRepaymentCorrection($advance, $original->request_key,
                    $data['request_key'], $data['entry_date'], $amount, $journal, $actor);
            }
            $id = DB::table('rider_incentive_movements')->insertGetId([
                'rider_id' => $original->rider_id, 'entry_date' => $data['entry_date'],
                'kind' => 'CORRECTION', 'amount_minor' => $amount,
                'reason' => $data['reason'], 'request_key' => $data['request_key'],
                'journal_entry_id' => $journal->id, 'corrects_movement_id' => $original->id,
                'created_by' => $actor->id, 'created_at' => now(),
            ]);

            return DB::table('rider_incentive_movements')->where('id', $id)->first();
        }, 3);
    }

    private function debit(array $input, User $actor, string $kind): object
    {
        abort_unless(PermissionMatrix::can($actor, PanelModule::RIDER_INCENTIVE_BALANCE, 'create'), 403);
        if ($kind === 'ADVANCE_REPAYMENT') {
            abort_unless(PermissionMatrix::can($actor, PanelModule::EMPLOYEE_ADVANCES, 'create'), 403);
        }
        $data = Validator::make($input, [
            'request_key' => ['required', 'uuid'],
            'rider_id' => ['required', 'integer', 'exists:users,id'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'amount_minor' => ['required', 'integer', 'between:1,1000000000000'],
            'reason' => [$kind === 'MANUAL_DEDUCTION' ? 'required' : 'nullable', 'string', 'max:255'],
            'cash_account_id' => [$kind === 'WITHDRAWAL' ? 'required' : 'nullable', 'integer', 'exists:accounts,id'],
            'advance_id' => [$kind === 'ADVANCE_REPAYMENT' ? 'required' : 'nullable', 'integer', 'exists:employee_advances,id'],
        ])->validate();
        if ($data['entry_date'] > today()->toDateString()) {
            throw ValidationException::withMessages(['entry_date' => 'Tanggal mutasi belum boleh di masa depan ya.']);
        }
        $data['reason'] = isset($data['reason']) ? trim($data['reason']) : null;
        if ($kind === 'MANUAL_DEDUCTION' && $data['reason'] === '') {
            throw ValidationException::withMessages(['reason' => 'Alasan potongan wajib diisi ya.']);
        }
        $data['cash_account_id'] = $kind === 'WITHDRAWAL' ? (int) $data['cash_account_id'] : null;
        $data['advance_id'] = $kind === 'ADVANCE_REPAYMENT' ? (int) $data['advance_id'] : null;
        $hash = hash('sha256', json_encode([$kind, $actor->id, $data], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $actor, $kind, $hash): object {
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $rider = User::query()->whereKey($data['rider_id'])->lockForUpdate()->firstOrFail();
            if ($rider->role !== Role::STAFF && ! DB::table('rider_incentive_movements')->where('rider_id', $rider->id)->exists()) {
                throw ValidationException::withMessages(['rider_id' => 'Pilih Rider yang punya saldo insentif ya.']);
            }
            $existing = DB::table('rider_incentive_operations')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                if ($existing->payload_hash !== $hash) {
                    throw ValidationException::withMessages(['request_key' => 'Kunci permintaan ini sudah dipakai untuk mutasi lain ya.']);
                }

                return DB::table('rider_incentive_movements')->where('request_key', $data['request_key'])->first();
            }
            if ($data['amount_minor'] > $this->balance($rider->id)) {
                throw ValidationException::withMessages(['amount_minor' => 'Saldo insentif Rider tidak cukup untuk pengambilan atau potongan ini ya.']);
            }
            $latest = DB::table('rider_incentive_movements')->where('rider_id', $rider->id)->max('entry_date');
            if ($latest !== null && $data['entry_date'] < $latest) {
                throw ValidationException::withMessages(['entry_date' => 'Tanggal mutasi harus sama atau setelah mutasi terakhir ya.']);
            }
            $previousMonthEnd = Carbon::parse($data['entry_date'])->startOfMonth()->subDay()->toDateString();
            $reserved = max(0, $this->payableBalance($rider->id, $previousMonthEnd));
            if ($data['amount_minor'] > $this->balance($rider->id) - $reserved) {
                throw ValidationException::withMessages(['amount_minor' => 'Saldo bulan lalu sudah disiapkan untuk payroll. Ambil saldo bulan ini saja ya.']);
            }
            $counter = match ($kind) {
                'WITHDRAWAL' => Account::query()->find($data['cash_account_id']),
                'ADVANCE_REPAYMENT' => Account::query()->where('code', '1-1500')->first(),
                default => Account::query()->where('code', '4-1300')->first(),
            };
            if (! $counter || ! $counter->is_active || ($kind === 'WITHDRAWAL'
                && ! in_array($counter->code, ['1-1100', '1-1200', '1-1300'], true))) {
                throw ValidationException::withMessages(['cash_account_id' => 'Pilih akun Kas atau Bank yang aktif ya.']);
            }
            $operationId = DB::table('rider_incentive_operations')->insertGetId([
                'request_key' => $data['request_key'], 'payload_hash' => $hash,
            ]);
            $journal = $this->journals->post([
                'entry_date' => $data['entry_date'],
                'description' => match ($kind) {
                    'WITHDRAWAL' => 'Pengambilan insentif Rider #'.$rider->id,
                    'ADVANCE_REPAYMENT' => 'Cicilan kasbon dari insentif Rider #'.$rider->id,
                    default => 'Potongan insentif Rider #'.$rider->id,
                },
                'source_type' => 'rider_incentive_operation', 'source_id' => $operationId,
                'purpose' => $kind, 'posted_by' => $actor->id,
            ], [
                $this->line('2-1800', $data['amount_minor'], 0),
                ['account_id' => $counter->id, 'debit_minor' => 0, 'credit_minor' => $data['amount_minor']],
            ]);
            if ($kind === 'ADVANCE_REPAYMENT') {
                $advance = EmployeeAdvance::query()->findOrFail($data['advance_id']);
                $this->advances->recordIncentiveRepayment($advance, $rider->id, $data['request_key'],
                    $data['entry_date'], $data['amount_minor'], $journal, $actor);
            }
            $id = DB::table('rider_incentive_movements')->insertGetId([
                'rider_id' => $rider->id, 'entry_date' => $data['entry_date'],
                'kind' => $kind, 'amount_minor' => -$data['amount_minor'],
                'reason' => $data['reason'], 'request_key' => $data['request_key'],
                'journal_entry_id' => $journal->id, 'advance_id' => $data['advance_id'],
                'created_by' => $actor->id, 'created_at' => now(),
            ]);

            return DB::table('rider_incentive_movements')->where('id', $id)->first();
        }, 3);
    }

    public function recordPayroll(PayrollRun $run, JournalEntry $journal, User $actor): void
    {
        foreach ($run->items as $item) {
            $amount = (int) ($item->snapshot['rider_incentive_balance_minor'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            User::query()->whereKey($item->user_id)->lockForUpdate()->firstOrFail();
            if ($this->balance($item->user_id) < $amount
                || $this->payableBalance($item->user_id, $journal->entry_date->toDateString()) < $amount) {
                throw ValidationException::withMessages(['run' => 'Saldo insentif Rider berubah. Hitung ulang payroll ya.']);
            }
            DB::table('rider_incentive_movements')->insert([
                'rider_id' => $item->user_id, 'entry_date' => $journal->entry_date->toDateString(),
                'kind' => 'PAYROLL_PAYMENT', 'amount_minor' => -$amount,
                'journal_entry_id' => $journal->id, 'payroll_run_id' => $run->id,
                'created_by' => $actor->id, 'created_at' => now(),
            ]);
        }
    }

    public function reversePayroll(PayrollRun $run, JournalEntry $journal, User $actor): void
    {
        foreach ($run->items as $item) {
            $amount = (int) ($item->snapshot['rider_incentive_balance_minor'] ?? 0);
            if ($amount > 0) {
                User::query()->whereKey($item->user_id)->lockForUpdate()->firstOrFail();
                DB::table('rider_incentive_movements')->insert([
                    'rider_id' => $item->user_id, 'entry_date' => $journal->entry_date->toDateString(),
                    'kind' => 'PAYROLL_REVERSAL', 'amount_minor' => $amount,
                    'reason' => 'Payroll dibatalkan', 'journal_entry_id' => $journal->id,
                    'payroll_run_id' => $run->id, 'created_by' => $actor->id, 'created_at' => now(),
                ]);
            }
        }
    }

    public function reconciliation(string $date): array
    {
        $balances = (int) DB::table('rider_incentive_movements')->where('entry_date', '<=', $date)->sum('amount_minor');
        $ledger = (int) DB::table('journal_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.entry_id')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->where('account.code', '2-1800')->where('entry.entry_date', '<=', $date)
            ->selectRaw('COALESCE(SUM(CAST(line.credit_minor AS SIGNED) - CAST(line.debit_minor AS SIGNED)), 0) AS balance')
            ->value('balance');

        return ['balances_minor' => $balances, 'ledger_minor' => $ledger, 'difference_minor' => $balances - $ledger];
    }

    private function line(string $code, int $debit, int $credit): array
    {
        $id = Account::query()->where('code', $code)->where('is_active', true)->value('id');
        if (! $id) {
            throw ValidationException::withMessages(['account' => 'Akun insentif '.$code.' belum tersedia ya.']);
        }

        return ['account_id' => $id, 'debit_minor' => $debit, 'credit_minor' => $credit];
    }
}
