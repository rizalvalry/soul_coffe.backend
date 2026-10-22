<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\PayrollItem;
use App\Models\PayrollRemittance;
use App\Models\ThrItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PayrollRemittanceService
{
    private const PROGRAMS = ['jht', 'jp', 'jkk', 'jkm', 'health'];

    public function __construct(private readonly JournalService $journals) {}

    public function status(User $actor, string $month, string $kind, ?string $program = null): array
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        $this->validatePeriod($month, $kind, $program);
        $accrued = 0;
        $bpjsAccrued = array_fill_keys(self::PROGRAMS, 0);
        $items = PayrollItem::query()->whereHas('run', fn ($query) => $query
            ->whereDate('period_start', $month.'-01')->whereIn('status', ['APPROVED', 'PAID']))->get();
        foreach ($items as $item) {
            $snapshot = $item->snapshot;
            if ($kind === 'PPH21') {
                $accrued += (int) $snapshot['pph21_minor'];
            } else {
                foreach (self::PROGRAMS as $part) {
                    $bpjsAccrued[$part] += (int) ($snapshot['bpjs_employee_minor'][$part] ?? 0)
                        + (int) ($snapshot['bpjs_employer_minor'][$part] ?? 0);
                }
            }
        }
        if ($kind === 'BPJS') {
            $accrued = $program ? $bpjsAccrued[$program] : array_sum($bpjsAccrued);
        }
        if ($kind === 'PPH21') {
            foreach (ThrItem::query()->whereHas('run', fn ($query) => $query
                ->whereYear('planned_payment_date', substr($month, 0, 4))
                ->whereMonth('planned_payment_date', (int) substr($month, 5, 2))
                ->whereIn('status', ['APPROVED', 'PAID']))->get() as $item) {
                $accrued += (int) $item->snapshot['pph21_minor'];
            }
        }
        $remittances = PayrollRemittance::query()->whereDate('month', $month.'-01')
            ->where('kind', $kind)->where('status', 'POSTED')->get();
        $paid = (int) $remittances->sum('amount_minor');
        $unallocated = 0;
        if ($program !== null) {
            $direct = [];
            $combined = 0;
            foreach ($remittances as $remittance) {
                if ($remittance->program === null) {
                    $combined += $remittance->amount_minor;
                } else {
                    $direct[$remittance->program] = ($direct[$remittance->program] ?? 0) + $remittance->amount_minor;
                }
            }
            $paid = (int) ($direct[$program] ?? 0);
            $outstanding = 0;
            foreach (self::PROGRAMS as $part) {
                $outstanding += max(0, $bpjsAccrued[$part] - (int) ($direct[$part] ?? 0));
            }
            if ($combined >= $outstanding) {
                $paid += max(0, $bpjsAccrued[$program] - $paid);
            } else {
                $unallocated = $combined;
            }
        }

        return [
            'month' => $month, 'kind' => $kind, 'program' => $program,
            'accrued_minor' => $accrued, 'paid_minor' => $paid,
            'difference_minor' => $accrued - $paid,
            'unallocated_minor' => $unallocated,
            'status' => $unallocated > 0 ? 'GABUNGAN_BELUM_RINCI'
                : ($paid === 0 ? 'TERUTANG' : ($accrued === $paid ? 'SUDAH_DISETOR' : 'SELISIH')),
        ];
    }

    public function remit(User $actor, array $input): PayrollRemittance
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        $data = Validator::make($input, [
            'month' => ['required', 'date_format:Y-m'],
            'kind' => ['required', 'in:PPH21,BPJS'],
            'program' => ['nullable', 'in:jht,jp,jkk,jkm,health'],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'receipt_number' => ['required', 'string', 'max:255'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'idempotency_key' => ['required', 'string', 'max:80'],
        ])->validate();
        $this->validatePeriod($data['month'], $data['kind'], $data['program'] ?? null);
        $data['program'] = $data['kind'] === 'PPH21' ? null : ($data['program'] ?? null);

        return DB::transaction(function () use ($actor, $data): PayrollRemittance {
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $existing = PayrollRemittance::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($existing) {
                foreach ($data as $key => $value) {
                    $stored = in_array($key, ['month', 'paid_on'], true)
                        ? $existing->{$key}->format($key === 'month' ? 'Y-m' : 'Y-m-d')
                        : $existing->{$key};
                    if ((string) $stored !== (string) $value) {
                        throw ValidationException::withMessages(['idempotency_key' => 'Kunci setor ini sudah dipakai untuk data lain ya.']);
                    }
                }

                return $existing;
            }
            $cash = Account::query()->find($data['account_id']);
            if (! $cash?->is_active || ! in_array($cash->code, ['1-1100', '1-1200', '1-1300'], true)) {
                throw ValidationException::withMessages(['account_id' => 'Pilih akun kas atau bank yang aktif ya.']);
            }
            $liability = Account::query()->where('code', $data['kind'] === 'PPH21' ? '2-1300' : '2-1200')->firstOrFail();
            $remittance = PayrollRemittance::query()->create([
                ...$data, 'month' => $data['month'].'-01', 'status' => 'POSTED', 'created_by' => $actor->id,
            ]);
            $this->journals->post([
                'entry_date' => $data['paid_on'],
                'description' => 'Setor '.$data['kind'].' masa '.$data['month'].' bukti '.$data['receipt_number'],
                'source_type' => 'payroll_remittance', 'source_id' => $remittance->id,
                'purpose' => 'PAYROLL_REMITTANCE', 'posted_by' => $actor->id,
            ], [
                ['account_id' => $liability->id, 'debit_minor' => $data['amount_minor'], 'credit_minor' => 0],
                ['account_id' => $cash->id, 'debit_minor' => 0, 'credit_minor' => $data['amount_minor']],
            ]);
            AuditLog::recordAction($actor, 'payroll.remitted', $remittance, null,
                ['month' => $data['month'], 'kind' => $data['kind'], 'program' => $data['program']]);

            return $remittance;
        }, 3);
    }

    public function void(User $actor, PayrollRemittance $remittance, string $reason): PayrollRemittance
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:225']])->validate();

        return DB::transaction(function () use ($actor, $remittance, $reason): PayrollRemittance {
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $locked = PayrollRemittance::query()->whereKey($remittance->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'VOID') {
                return $locked;
            }
            $entry = JournalEntry::query()->where('source_type', 'payroll_remittance')
                ->where('source_id', $locked->id)->firstOrFail();
            $reversal = $this->journals->reverse($entry, $reason, null, null, $locked, $actor);
            $locked->update(['status' => 'VOID', 'voided_by' => $actor->id, 'void_reason' => $reason]);
            AuditLog::recordAction($actor, 'payroll.remittance_voided', $locked,
                ['status' => 'POSTED'], ['status' => 'VOID', 'reversal_entry_id' => $reversal->id]);

            return $locked;
        }, 3);
    }

    private function validatePeriod(string $month, string $kind, ?string $program): void
    {
        Validator::make(compact('month', 'kind', 'program'), [
            'month' => ['required', 'date_format:Y-m'],
            'kind' => ['required', 'in:PPH21,BPJS'],
            'program' => ['nullable', 'in:jht,jp,jkk,jkm,health'],
        ])->validate();
        if ($kind === 'PPH21' && $program !== null) {
            throw ValidationException::withMessages(['program' => 'Program hanya untuk setor BPJS ya.']);
        }
    }
}
