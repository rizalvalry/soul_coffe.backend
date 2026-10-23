<?php

namespace App\Services;

use App\Enums\AttendanceCode;
use App\Enums\PanelModule;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceMovement;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeAdvanceService
{
    public function __construct(private readonly JournalService $journal) {}

    public function create(array $input, User $actor): EmployeeAdvance
    {
        $this->authorize($actor, 'create');
        $data = Validator::make($input, [
            'request_key' => ['required', 'uuid'],
            'user_id' => ['required', 'integer', 'exists:employee_profiles,user_id'],
            'type_id' => ['required', 'integer', 'exists:employee_advance_types,id'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'principal_minor' => ['required', 'integer', 'between:1,1000000000000'],
            'reason' => ['required', 'string', 'max:255'],
            'installment_kind' => ['required', Rule::in(['DAY', 'PAYROLL', 'FREE'])],
            'installment_minor' => ['nullable', 'required_unless:installment_kind,FREE', 'integer', 'between:1,1000000000000'],
            'source_kind' => ['required', Rule::in(['CASH', 'OPENING'])],
            'cash_account_id' => ['nullable', 'required_if:source_kind,CASH', 'integer', 'exists:accounts,id'],
        ])->validate();
        if ($data['start_date'] > today()->toDateString()) {
            throw ValidationException::withMessages(['start_date' => 'Tanggal kasbon belum boleh di masa depan ya.']);
        }
        if ($data['installment_kind'] === 'FREE') {
            $data['installment_minor'] = null;
        }
        if ($data['source_kind'] === 'OPENING') {
            $data['cash_account_id'] = null;
        }
        try {
            return DB::transaction(function () use ($data, $actor): EmployeeAdvance {
                $existing = EmployeeAdvance::query()->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    $this->assertSameLoan($existing, $data);

                    return $existing;
                }
                $funding = $this->account($data['source_kind'] === 'OPENING'
                    ? '3-1200' : null, $data['cash_account_id']);
                $receivable = $this->account('1-1500');
                if ($data['source_kind'] === 'OPENING' && JournalEntry::query()
                    ->where('source_type', 'opening_balance')->where('purpose', 'OPENING')
                    ->whereDoesntHave('reversal')
                    ->whereHas('lines', fn ($query) => $query->where('account_id', $receivable->id))->exists()) {
                    throw ValidationException::withMessages(['source_kind' => 'Akun 1-1500 sudah masuk jurnal Saldo Awal. Pisahkan dulu saldo kasbon agar tidak dobel ya.']);
                }
                $advance = EmployeeAdvance::query()->create([
                    ...$data, 'status' => 'ACTIVE', 'created_by' => $actor->id,
                ]);
                $journal = $this->journal->post([
                    'entry_date' => $data['start_date'],
                    'description' => ($data['source_kind'] === 'OPENING' ? 'Saldo awal' : 'Pencairan').' kasbon karyawan #'.$advance->id,
                    'source_type' => 'employee_advance', 'source_id' => $advance->id,
                    'purpose' => $data['source_kind'] === 'OPENING' ? 'ADVANCE_OPENING' : 'ADVANCE_DISBURSEMENT',
                    'posted_by' => $actor->id,
                ], [
                    ['account_id' => $receivable->id, 'debit_minor' => (int) $data['principal_minor'], 'credit_minor' => 0],
                    ['account_id' => $funding->id, 'debit_minor' => 0, 'credit_minor' => (int) $data['principal_minor']],
                ]);
                $this->movement($advance, $data['request_key'], $data['start_date'],
                    $data['source_kind'] === 'OPENING' ? 'OPENING' : 'DISBURSEMENT',
                    (int) $data['principal_minor'], $data['reason'], $journal, null, $actor, $data['cash_account_id']);

                return $advance;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = EmployeeAdvance::query()->where('request_key', $data['request_key'])->firstOrFail();
            $this->assertSameLoan($existing, $data);

            return $existing;
        }
    }

    public function repay(EmployeeAdvance $advance, array $input, User $actor): EmployeeAdvanceMovement
    {
        $this->authorize($actor, 'create');
        $data = Validator::make($input, [
            'request_key' => ['required', 'uuid'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'amount_minor' => ['required', 'integer', 'between:1,1000000000000'],
            'cash_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ])->validate();
        if ($data['entry_date'] > today()->toDateString()) {
            throw ValidationException::withMessages(['entry_date' => 'Tanggal cicilan belum boleh di masa depan ya.']);
        }

        try {
            return DB::transaction(function () use ($advance, $data, $actor): EmployeeAdvanceMovement {
                Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
                $advance = EmployeeAdvance::query()->whereKey($advance->id)->lockForUpdate()->firstOrFail();
                $existing = EmployeeAdvanceMovement::query()->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    $this->assertSameMovement($existing, $advance, $data, 'CASH_REPAYMENT');

                    return $existing;
                }
                if ($advance->status !== 'ACTIVE' || $data['entry_date'] < $this->latestMovementDate($advance)
                    || $data['amount_minor'] > $advance->balanceMinor()) {
                    throw ValidationException::withMessages(['amount_minor' => 'Cicilan harus sesuai saldo kasbon aktif dan tanggalnya ya.']);
                }
                $cash = $this->account(null, (int) $data['cash_account_id']);
                $receivable = $this->account('1-1500');
                $operationId = DB::table('employee_advance_operations')->insertGetId([
                    'request_key' => $data['request_key'], 'advance_id' => $advance->id, 'kind' => 'CASH_REPAYMENT',
                ]);
                $journal = $this->journal->post([
                    'entry_date' => $data['entry_date'], 'description' => 'Cicilan tunai kasbon #'.$advance->id,
                    'source_type' => 'employee_advance_repayment',
                    'source_id' => $operationId,
                    'purpose' => 'ADVANCE_REPAYMENT', 'posted_by' => $actor->id,
                ], [
                    ['account_id' => $cash->id, 'debit_minor' => (int) $data['amount_minor'], 'credit_minor' => 0],
                    ['account_id' => $receivable->id, 'debit_minor' => 0, 'credit_minor' => (int) $data['amount_minor']],
                ]);
                $movement = $this->movement($advance, $data['request_key'], $data['entry_date'],
                    'CASH_REPAYMENT', -(int) $data['amount_minor'], $data['reason'] ?? null, $journal, null, $actor,
                    (int) $data['cash_account_id']);
                $this->refreshStatus($advance);

                return $movement;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = EmployeeAdvanceMovement::query()->where('request_key', $data['request_key'])->firstOrFail();
            $this->assertSameMovement($existing, $advance, $data, 'CASH_REPAYMENT');

            return $existing;
        }
    }

    public function adjust(EmployeeAdvance $advance, array $input, User $actor): EmployeeAdvanceMovement
    {
        $this->authorize($actor, 'edit');
        $data = Validator::make($input, [
            'request_key' => ['required', 'uuid'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'amount_minor' => ['required', 'integer', 'not_in:0', 'between:-1000000000000,1000000000000'],
            'reason' => ['required', 'string', 'max:255'],
        ])->validate();
        if ($data['entry_date'] > today()->toDateString()) {
            throw ValidationException::withMessages(['entry_date' => 'Tanggal koreksi belum boleh di masa depan ya.']);
        }

        try {
            return DB::transaction(function () use ($advance, $data, $actor): EmployeeAdvanceMovement {
                Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
                $advance = EmployeeAdvance::query()->whereKey($advance->id)->lockForUpdate()->firstOrFail();
                $existing = EmployeeAdvanceMovement::query()->where('request_key', $data['request_key'])->first();
                if ($existing) {
                    $this->assertSameMovement($existing, $advance, $data, 'ADJUSTMENT');

                    return $existing;
                }
                $amount = (int) $data['amount_minor'];
                if ($advance->status === 'CANCELLED' || $advance->balanceMinor() + $amount < 0
                    || $data['entry_date'] < $this->latestMovementDate($advance)) {
                    throw ValidationException::withMessages(['amount_minor' => 'Penyesuaian melewati saldo atau tanggal kasbon ya.']);
                }
                $receivable = $this->account('1-1500');
                $equity = $this->account('3-1200');
                $operationId = DB::table('employee_advance_operations')->insertGetId([
                    'request_key' => $data['request_key'], 'advance_id' => $advance->id, 'kind' => 'ADJUSTMENT',
                ]);
                $journal = $this->journal->post([
                    'entry_date' => $data['entry_date'], 'description' => 'Penyesuaian kasbon #'.$advance->id,
                    'source_type' => 'employee_advance_adjustment',
                    'source_id' => $operationId,
                    'purpose' => 'ADVANCE_ADJUSTMENT', 'posted_by' => $actor->id,
                ], [
                    ['account_id' => $receivable->id, 'debit_minor' => max(0, $amount), 'credit_minor' => max(0, -$amount)],
                    ['account_id' => $equity->id, 'debit_minor' => max(0, -$amount), 'credit_minor' => max(0, $amount)],
                ]);
                $movement = $this->movement($advance, $data['request_key'], $data['entry_date'],
                    'ADJUSTMENT', $amount, $data['reason'], $journal, null, $actor);
                $this->refreshStatus($advance);

                return $movement;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = EmployeeAdvanceMovement::query()->where('request_key', $data['request_key'])->firstOrFail();
            $this->assertSameMovement($existing, $advance, $data, 'ADJUSTMENT');

            return $existing;
        }
    }

    public function reverseRepayment(EmployeeAdvanceMovement $repayment, string $requestKey, string $reason,
        User $actor): EmployeeAdvanceMovement
    {
        $this->authorize($actor, 'edit');
        Validator::make(['request_key' => $requestKey, 'reason' => $reason], [
            'request_key' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:225'],
        ])->validate();

        return DB::transaction(function () use ($repayment, $requestKey, $reason, $actor): EmployeeAdvanceMovement {
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $advance = EmployeeAdvance::query()->whereKey($repayment->advance_id)->lockForUpdate()->firstOrFail();
            $repayment = EmployeeAdvanceMovement::query()->whereKey($repayment->id)->firstOrFail();
            $existing = EmployeeAdvanceMovement::query()->where('request_key', $requestKey)->first();
            if ($existing && $existing->kind === 'REPAYMENT_REVERSAL' && $existing->advance_id === $advance->id
                && $existing->reason === $reason
                && JournalEntry::query()->whereKey($existing->journal_entry_id)
                    ->where('reverses_entry_id', $repayment->journal_entry_id)->exists()) {
                return $existing;
            }
            if ($existing || $repayment->kind !== 'CASH_REPAYMENT'
                || JournalEntry::query()->where('reverses_entry_id', $repayment->journal_entry_id)->exists()) {
                throw ValidationException::withMessages(['repayment' => 'Cicilan ini sudah dibalik atau kunci permintaan dipakai ya.']);
            }
            $journal = JournalEntry::query()->findOrFail($repayment->journal_entry_id);
            $reversal = $this->journal->reverse($journal, $reason, advanceMovement: $repayment);
            $movement = $this->movement($advance, $requestKey, $reversal->entry_date->toDateString(),
                'REPAYMENT_REVERSAL', -$repayment->amount_minor, $reason, $reversal, null, $actor,
                $repayment->cash_account_id);
            $this->refreshStatus($advance);

            return $movement;
        }, 3);
    }

    public function cancel(EmployeeAdvance $advance, string $reason, User $actor): void
    {
        $this->authorize($actor, 'edit');
        if (trim($reason) === '' || mb_strlen($reason) > 225) {
            throw ValidationException::withMessages(['reason' => 'Isi alasan pembatalan, maksimal 225 karakter ya.']);
        }
        DB::transaction(function () use ($advance, $reason, $actor): void {
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $advance = EmployeeAdvance::query()->whereKey($advance->id)->lockForUpdate()->firstOrFail();
            if ($advance->status === 'CANCELLED') {
                return;
            }
            if ($advance->movements()->count() !== 1) {
                throw ValidationException::withMessages(['advance' => 'Kasbon yang sudah punya cicilan dikoreksi lewat mutasi penyesuaian ya.']);
            }
            $opening = $advance->movements()->firstOrFail();
            $journal = JournalEntry::query()->findOrFail($opening->journal_entry_id);
            $reversal = $this->journal->reverse($journal, $reason, advance: $advance);
            $this->movement($advance, (string) Str::uuid(), $reversal->entry_date->toDateString(),
                'CANCELLATION', -$advance->balanceMinor(), $reason, $reversal, null, $actor);
            $advance->update(['status' => 'CANCELLED', 'cancel_reason' => $reason]);
        }, 3);
    }

    public function due(User $user, string $period, int $availableMinor, ?PayrollRun $run = null): array
    {
        $through = Carbon::parse($period.'-01')->endOfMonth()->toDateString();
        if ($availableMinor <= 0) {
            return [];
        }
        $rows = [];
        $advances = EmployeeAdvance::query()->where('user_id', $user->id)
            ->where('start_date', '<=', $through)->where('status', '!=', 'CANCELLED')
            ->orderBy('start_date')->orderBy('id')->get();
        foreach ($advances as $advance) {
            $balance = $this->minBalanceFrom($advance, $through);
            if ($balance <= 0) {
                continue;
            }
            $override = $run ? DB::table('employee_advance_payroll_overrides')
                ->where('payroll_run_id', $run->id)->where('advance_id', $advance->id)->value('amount_minor') : null;
            if ($override !== null) {
                $due = (int) $override;
            } elseif ($advance->installment_kind === 'DAY') {
                $due = $advance->installment_minor * $this->workedDays($user->id,
                    max($period.'-01', $advance->start_date->toDateString()), $through);
            } elseif ($advance->installment_kind === 'PAYROLL') {
                $due = $advance->installment_minor;
            } else {
                $due = 0;
            }
            $amount = min($balance, $availableMinor, $due);
            if ($amount > 0) {
                $rows[] = ['advance_id' => $advance->id, 'amount_minor' => $amount];
                $availableMinor -= $amount;
            }
        }

        return $rows;
    }

    public function setPayrollOverride(PayrollRun $run, EmployeeAdvance $advance, int $amount, User $actor): void
    {
        $this->authorize($actor, 'edit');
        if ($amount < 0) {
            throw ValidationException::withMessages(['amount_minor' => 'Potongan tidak boleh negatif ya.']);
        }
        DB::transaction(function () use ($run, $advance, $amount, $actor): void {
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $advance = EmployeeAdvance::query()->whereKey($advance->id)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'DRAFT' || $advance->status !== 'ACTIVE'
                || ! $run->items()->where('user_id', $advance->user_id)->exists()
                || $advance->start_date->format('Y-m') > $run->period_start->format('Y-m')
                || $amount > $advance->balanceMinor()) {
                throw ValidationException::withMessages(['amount_minor' => 'Potongan harus sesuai saldo kasbon aktif sebelum Approve ya.']);
            }
            $previous = DB::table('employee_advance_payroll_overrides')
                ->where('payroll_run_id', $run->id)->where('advance_id', $advance->id)->value('amount_minor');
            DB::table('employee_advance_payroll_overrides')->updateOrInsert(
                ['payroll_run_id' => $run->id, 'advance_id' => $advance->id],
                ['amount_minor' => $amount, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()],
            );
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'actor_role' => $actor->role->value,
                'action' => 'employee_advance.payroll_override',
                'subject_type' => EmployeeAdvance::class, 'subject_id' => $advance->id,
                'before_json' => ['run_id' => $run->id],
                'after_json' => ['run_id' => $run->id, '_payroll_ciphertext' => Crypt::encryptString(json_encode([
                    'before' => ['amount_minor' => $previous], 'after' => ['amount_minor' => $amount],
                ], JSON_THROW_ON_ERROR))],
                'ip' => request()->ip(),
            ]);
        }, 3);
    }

    public function recordPayroll(PayrollRun $run, JournalEntry $journal, User $actor): void
    {
        foreach ($run->items as $item) {
            foreach ($item->snapshot['advance_deductions'] ?? [] as $deduction) {
                $advance = EmployeeAdvance::query()->whereKey($deduction['advance_id'])->lockForUpdate()->firstOrFail();
                if ($advance->balanceMinor() < $deduction['amount_minor']
                    || $this->minBalanceFrom($advance, $journal->entry_date->toDateString()) < $deduction['amount_minor']) {
                    throw ValidationException::withMessages(['run' => 'Saldo kasbon berubah. Hitung ulang payroll ya.']);
                }
                $this->movement($advance, (string) Str::uuid(), $journal->entry_date->toDateString(),
                    'PAYROLL_DEDUCTION', -$deduction['amount_minor'], null, $journal, $run, $actor);
                $this->refreshStatus($advance);
            }
        }
    }

    public function reversePayroll(PayrollRun $run, JournalEntry $reversal, User $actor): void
    {
        foreach ($run->items as $item) {
            foreach ($item->snapshot['advance_deductions'] ?? [] as $deduction) {
                $advance = EmployeeAdvance::query()->whereKey($deduction['advance_id'])->lockForUpdate()->firstOrFail();
                $this->movement($advance, (string) Str::uuid(), $reversal->entry_date->toDateString(),
                    'PAYROLL_REVERSAL', $deduction['amount_minor'], 'Payroll dibatalkan', $reversal, $run, $actor);
                $this->refreshStatus($advance);
            }
        }
    }

    public function reconciliation(string $date): array
    {
        $loans = (int) DB::table('employee_advance_movements')->where('entry_date', '<=', $date)->sum('amount_minor');
        $ledger = (int) DB::table('journal_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.entry_id')
            ->join('accounts as account', 'account.id', '=', 'line.account_id')
            ->where('account.code', '1-1500')->where('entry.entry_date', '<=', $date)
            ->selectRaw('COALESCE(SUM(CAST(line.debit_minor AS SIGNED) - CAST(line.credit_minor AS SIGNED)), 0) AS balance')
            ->value('balance');

        return ['loans_minor' => $loans, 'ledger_minor' => $ledger, 'difference_minor' => $loans - $ledger];
    }

    private function movement(EmployeeAdvance $advance, string $key, string $date, string $kind, int $amount,
        ?string $reason, JournalEntry $journal, ?PayrollRun $run, User $actor, ?int $cashAccountId = null): EmployeeAdvanceMovement
    {
        return $advance->movements()->create([
            'request_key' => $key, 'entry_date' => $date, 'kind' => $kind, 'amount_minor' => $amount,
            'reason' => $reason, 'journal_entry_id' => $journal->id, 'cash_account_id' => $cashAccountId,
            'payroll_run_id' => $run?->id,
            'created_by' => $actor->id, 'created_at' => now(),
        ]);
    }

    private function refreshStatus(EmployeeAdvance $advance): void
    {
        $advance->update(['status' => $advance->balanceMinor() === 0 ? 'PAID' : 'ACTIVE']);
    }

    private function latestMovementDate(EmployeeAdvance $advance): string
    {
        return (string) $advance->movements()->max('entry_date');
    }

    private function minBalanceFrom(EmployeeAdvance $advance, string $date): int
    {
        $balance = 0;
        $minimum = null;
        foreach ($advance->movements()->selectRaw('entry_date, SUM(amount_minor) AS delta')
            ->groupBy('entry_date')->orderBy('entry_date')->get() as $day) {
            if ($day->entry_date->toDateString() > $date && $minimum === null) {
                $minimum = $balance;
            }
            $balance += (int) $day->delta;
            if ($day->entry_date->toDateString() >= $date) {
                $minimum = min($minimum ?? PHP_INT_MAX, $balance);
            }
        }

        return $minimum ?? $balance;
    }

    private function workedDays(int $userId, string $from, string $through): int
    {
        $manual = DB::table('attendance_marks')->where('user_id', $userId)
            ->whereBetween('entry_date', [$from, $through])
            ->where('code', AttendanceCode::PRESENT->value)->count();
        $clockIns = DB::table('attendances as attendance')->where('attendance.user_id', $userId)
            ->whereBetween('attendance.operating_date', [$from, $through])
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('attendance_marks as mark')
                ->whereColumn('mark.user_id', 'attendance.user_id')
                ->whereColumn('mark.entry_date', 'attendance.operating_date'))->count();

        return $manual + $clockIns;
    }

    private function assertSameLoan(EmployeeAdvance $advance, array $data): void
    {
        if ($advance->user_id !== (int) $data['user_id']
            || $advance->type_id !== (int) $data['type_id']
            || $advance->start_date->toDateString() !== $data['start_date']
            || $advance->principal_minor !== (int) $data['principal_minor']
            || $advance->reason !== $data['reason']
            || $advance->installment_kind !== $data['installment_kind']
            || $advance->installment_minor !== ($data['installment_minor'] === null ? null : (int) $data['installment_minor'])
            || $advance->source_kind !== $data['source_kind']
            || $advance->cash_account_id !== $data['cash_account_id']) {
            throw ValidationException::withMessages(['request_key' => 'Kunci permintaan sudah dipakai untuk kasbon berbeda.']);
        }
    }

    private function assertSameMovement(EmployeeAdvanceMovement $movement, EmployeeAdvance $advance, array $data,
        string $kind): void
    {
        $amount = $kind === 'CASH_REPAYMENT' ? -(int) $data['amount_minor'] : (int) $data['amount_minor'];
        if ($movement->advance_id !== $advance->id || $movement->kind !== $kind
            || $movement->entry_date->toDateString() !== $data['entry_date']
            || $movement->amount_minor !== $amount
            || $movement->reason !== ($data['reason'] ?? null)
            || $movement->cash_account_id !== ($data['cash_account_id'] ?? null)) {
            throw ValidationException::withMessages(['request_key' => 'Kunci permintaan sudah dipakai untuk mutasi berbeda.']);
        }
    }

    private function account(?string $code, ?int $id = null): Account
    {
        $account = Account::query()->when($code, fn ($q) => $q->where('code', $code))
            ->when($id, fn ($q) => $q->whereKey($id))->where('is_active', true)->first();
        if (! $account || ($id && ! in_array($account->code, ['1-1100', '1-1200', '1-1300'], true))) {
            throw ValidationException::withMessages(['cash_account_id' => 'Pilih akun kas atau bank yang aktif ya.']);
        }

        return $account;
    }

    private function authorize(User $actor, string $ability): void
    {
        abort_unless(PermissionMatrix::can($actor, PanelModule::EMPLOYEE_ADVANCES, $ability), 403);
    }
}
