<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\PayrollItem;
use App\Models\PayrollPriorWithholding;
use App\Models\PayrollRun;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayrollRunService
{
    public function __construct(private readonly PayrollCalculator $calculator, private readonly JournalService $journal) {}

    public function create(string $period, User $actor): PayrollRun
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period)) {
            throw ValidationException::withMessages(['period' => 'Periode harus YYYY-MM ya.']);
        }

        return DB::transaction(function () use ($period, $actor): PayrollRun {
            $run = PayrollRun::query()->firstOrCreate(['period_start' => $period.'-01'], [
                'status' => 'DRAFT', 'created_by' => $actor->id,
            ]);
            if ($run->wasRecentlyCreated) {
                $this->calculate($run);
                $this->audit($run, $actor, 'payroll_run_created');
            }

            return $run->refresh();
        }, 3);
    }

    public function recalculate(PayrollRun $run, User $actor): void
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        DB::transaction(function () use ($run, $actor): void {
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $this->draftOnly($run);
            $this->calculate($run);
            $this->audit($run, $actor, 'payroll_run_recalculated');
        }, 3);
    }

    private function calculate(PayrollRun $run): void
    {
        $period = $run->period_start->format('Y-m');
        $from = $run->period_start->format('Y-m-d');
        $employees = User::query()->whereHas('employeeProfile', fn ($query) => $query->where('payroll_active', true))
            ->where(fn ($query) => $query->whereNull('joined_at')->orWhereDate('joined_at', '<=', $run->period_start->format('Y-m-t')))
            ->with('employeeProfile')->orderBy('id')->get();
        if ($employees->isEmpty()) {
            throw ValidationException::withMessages(['period' => 'Belum ada karyawan aktif untuk payroll.']);
        }
        $settings = DB::table('payroll_settings')->where('effective_from', '<=', $from)->orderByDesc('effective_from')->first();
        if (! $settings || $settings->verified_at === null) {
            throw ValidationException::withMessages(['period' => 'Tarif BPJS periode ini belum diverifikasi Management.']);
        }
        $snapshots = [];
        foreach ($employees as $user) {
            $ptkp = DB::table('ptkp_rates')->where('status', $user->employeeProfile->ptkp_status)
                ->where('effective_from', '<=', $from)->orderByDesc('effective_from')->first();
            if (! $ptkp || $ptkp->verified_at === null) {
                throw ValidationException::withMessages(['period' => 'PTKP '.$user->name.' belum diverifikasi Management.']);
            }
            $ter = DB::table('payroll_ter_versions')->where('category', $ptkp->ter_category)
                ->where('effective_from', '<=', $from)->orderByDesc('effective_from')->first();
            if (! $ter || $ter->published_at === null || $ter->verified_at === null) {
                throw ValidationException::withMessages(['period' => 'TER kategori '.$ptkp->ter_category.' belum diterbitkan dan diverifikasi.']);
            }
            try {
                $snapshot = $this->calculator->calculate($user, $period);
                if (str_ends_with($period, '-12')) {
                    $history = PayrollItem::query()->where('user_id', $user->id)
                        ->whereHas('run', fn ($query) => $query->whereYear('period_start', substr($period, 0, 4))
                            ->whereIn('status', ['APPROVED', 'PAID']))->with('run')->get();
                    $months = [];
                    foreach ($history as $item) {
                        $months[(int) $item->run->period_start->format('n')] = $item->snapshot;
                    }
                    $manual = PayrollPriorWithholding::query()->where('user_id', $user->id)
                        ->where('year', (int) substr($period, 0, 4))->first();
                    $snapshot = $this->calculator->reconcileDecember($user, $snapshot, $months, $manual);
                }
                $snapshot['bpjs_settings_id'] = $settings->id;
                $snapshot['ptkp_rate_id'] = $ptkp->id;
                $snapshot['ter_version_id'] = $ter->id;
            } catch (\DomainException $exception) {
                throw ValidationException::withMessages(['period' => $user->name.': '.$exception->getMessage()]);
            }
            foreach ($snapshot['components'] as $component) {
                if ($component['kind'] !== 'DEDUCTION') {
                    continue;
                }
                $account = $component['account_id'] ? Account::query()->find($component['account_id']) : null;
                if (! $account || ! $account->is_active || ($component['code'] === 'ADVANCE' && $account->code !== '1-1500')) {
                    throw ValidationException::withMessages(['period' => 'Akun potongan '.$component['name'].' belum valid untuk '.$user->name.'.']);
                }
            }
            if ($snapshot['take_home_minor'] < 0) {
                throw ValidationException::withMessages(['period' => 'Take-home pay '.$user->name.' negatif; cek komponennya ya.']);
            }
            $snapshots[$user->id] = $snapshot;
        }
        $run->items()->delete();
        foreach ($snapshots as $userId => $snapshot) {
            $run->items()->create(['user_id' => $userId, 'snapshot' => $snapshot]);
        }
    }

    public function approve(PayrollRun $run, User $actor): void
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        DB::transaction(function () use ($run, $actor): void {
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status === 'APPROVED' || $run->status === 'PAID') {
                return;
            }
            $this->draftOnly($run);
            // Re-evaluate rules and manual entries immediately before locking the snapshot.
            $this->calculate($run);
            $items = $run->items()->with('user')->get();
            $debits = [];
            $credits = [];
            foreach ($items as $item) {
                $s = $item->snapshot;
                $this->sum($debits, '6-1200', $s['gross_pay_minor']);
                $this->sum($debits, '6-1300', $s['bpjs_employer_total_minor']);
                $this->sum($credits, '2-1100', $s['take_home_minor']);
                $this->sum($credits, '2-1200', $this->checkedAdd($s['bpjs_employee_total_minor'], $s['bpjs_employer_total_minor']));
                if ($s['pph21_minor'] >= 0) {
                    $this->sum($credits, '2-1300', $s['pph21_minor']);
                } else {
                    $this->sum($debits, '2-1300', -$s['pph21_minor']);
                }
                foreach ($s['components'] as $component) {
                    if ($component['kind'] === 'DEDUCTION') {
                        $this->sum($credits, 'id:'.$component['account_id'], $component['amount_minor']);
                    }
                }
            }
            $lines = [];
            foreach ([['debit_minor' => $debits, 'credit_minor' => 0], ['debit_minor' => $credits, 'credit_minor' => 1]] as $side) {
                foreach ($side['debit_minor'] as $key => $amount) {
                    if ($amount > 0) {
                        $lines[] = [
                            'account_id' => $this->accountId($key),
                            'debit_minor' => $side['credit_minor'] ? 0 : $amount,
                            'credit_minor' => $side['credit_minor'] ? $amount : 0,
                        ];
                    }
                }
            }
            $this->journal->post([
                'entry_date' => $run->period_start->format('Y-m-t'),
                'description' => 'Akrual payroll '.$run->period_start->format('Y-m'),
                'source_type' => 'payroll_run', 'source_id' => $run->id,
                'purpose' => 'PAYROLL_ACCRUAL', 'posted_by' => $actor->id,
            ], $lines);
            $run->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit($run, $actor, 'payroll_run_approved');
        }, 3);
        // Rendering is retryable and happens after accounting locks are released.
        $this->ensureSlips($run);
    }

    private function ensureSlips(PayrollRun $run): void
    {
        $run = $run->fresh();
        foreach ($run->items()->with('user')->get() as $item) {
            $path = 'payroll-slips/'.$run->id.'/'.$item->user_id.'.pdf';
            if (! $this->validSlip($path)) {
                $pdf = Pdf::loadView('payroll.slip', ['run' => $run, 'item' => $item, 'snapshot' => $item->snapshot]);
                $temporary = $path.'.'.Str::uuid().'.tmp';
                try {
                    if (! Storage::disk('local')->put($temporary, $pdf->output())
                        || ! Storage::disk('local')->move($temporary, $path)) {
                        throw ValidationException::withMessages(['slip' => 'Slip gaji gagal disimpan. Pakai tombol Cek/ulang slip PDF untuk coba lagi.']);
                    }
                } finally {
                    Storage::disk('local')->delete($temporary);
                }
            }
            if ($item->slip_path !== $path) {
                $item->update(['slip_path' => $path]);
            }
        }
    }

    private function validSlip(string $path): bool
    {
        if (! Storage::disk('local')->exists($path)) {
            return false;
        }
        $content = Storage::disk('local')->get($path);

        return str_starts_with($content, '%PDF-') && str_ends_with(rtrim($content), '%%EOF');
    }

    public function markPaid(PayrollRun $run, User $actor, string $date, int $accountId): void
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        DB::transaction(function () use ($run, $actor, $date, $accountId): void {
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status === 'PAID') {
                if ($run->payment_account_id !== $accountId || $run->paid_at?->format('Y-m-d') !== $date) {
                    throw ValidationException::withMessages(['payment' => 'Run ini sudah dibayar dengan rincian berbeda.']);
                }

                return;
            }
            if ($run->status !== 'APPROVED') {
                throw ValidationException::withMessages(['payment' => 'Approve run dulu sebelum menandai Paid.']);
            }
            $account = Account::query()->find($accountId);
            if (! $account || ! $account->is_active || ! in_array($account->code, ['1-1100', '1-1200', '1-1300'], true)) {
                throw ValidationException::withMessages(['account_id' => 'Pilih akun Kas atau Bank yang aktif.']);
            }
            $total = 0;
            foreach ($run->items as $item) {
                $total = $this->checkedAdd($total, $item->snapshot['take_home_minor']);
            }
            if ($total <= 0) {
                throw ValidationException::withMessages(['payment' => 'Total pembayaran harus lebih dari nol.']);
            }
            $this->journal->post([
                'entry_date' => $date,
                'description' => 'Pembayaran payroll '.$run->period_start->format('Y-m'),
                'source_type' => 'payroll_run', 'source_id' => $run->id,
                'purpose' => 'PAYROLL_PAYMENT', 'posted_by' => $actor->id,
            ], [
                ['account_id' => $this->accountId('2-1100'), 'debit_minor' => $total, 'credit_minor' => 0],
                ['account_id' => $accountId, 'debit_minor' => 0, 'credit_minor' => $total],
            ]);
            $run->update(['status' => 'PAID', 'paid_at' => $date, 'payment_account_id' => $accountId]);
            $this->audit($run, $actor, 'payroll_run_paid');
        }, 3);
    }

    private function draftOnly(PayrollRun $run): void
    {
        if ($run->status !== 'DRAFT') {
            throw ValidationException::withMessages(['run' => 'Run sudah dikunci. Snapshot tidak bisa diubah.']);
        }
    }

    private function sum(array &$bucket, string $key, int $amount): void
    {
        $bucket[$key] = $this->checkedAdd($bucket[$key] ?? 0, $amount);
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            throw ValidationException::withMessages(['payroll' => 'Nominal payroll terlalu besar untuk dihitung.']);
        }

        return $left + $right;
    }

    private function accountId(string $key): int
    {
        $id = str_starts_with($key, 'id:') ? (int) substr($key, 3) : Account::query()->where('code', $key)->value('id');
        if (! $id) {
            throw ValidationException::withMessages(['account' => 'Akun jurnal payroll '.$key.' belum tersedia.']);
        }

        return $id;
    }

    private function audit(PayrollRun $run, User $actor, string $action): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor->id, 'actor_role' => $actor->role->value,
            'action' => $action, 'subject_type' => PayrollRun::class, 'subject_id' => $run->id,
            'after_json' => ['period' => $run->period_start->format('Y-m'), 'status' => $run->status],
            'ip' => request()->ip(),
        ]);
    }
}
