<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\PayrollItem;
use App\Models\PayrollPriorWithholding;
use App\Models\PayrollRun;
use App\Models\ThrItem;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayrollRunService
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly JournalService $journal,
        private readonly RiderCommissionService $commissions,
    ) {}

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
            if ($run->status === 'CANCELLED') {
                throw ValidationException::withMessages(['period' => 'Run periode ini dibatalkan. Buka lagi run-nya untuk koreksi ya.']);
            }
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

    public function preview(User $user, string $period, int $additionalGross = 0, ?User $actor = null): array
    {
        if ($actor) {
            Gate::forUser($actor)->authorize('manage-payroll');
        } else {
            Gate::authorize('manage-payroll');
        }
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period)) {
            throw ValidationException::withMessages(['period' => 'Periode harus YYYY-MM ya.']);
        }
        if (! $user->employeeProfile?->payroll_active
            || ($user->joined_at && $user->joined_at->format('Y-m') > $period)) {
            throw ValidationException::withMessages(['period' => 'Karyawan belum aktif di periode payroll ini ya.']);
        }
        $from = $period.'-01';
        $settings = $this->verifiedSettings($from);

        return $this->snapshotFor($user, $period, $from, $settings, $additionalGross);
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
        $settings = $this->verifiedSettings($from);
        $snapshots = [];
        foreach ($employees as $user) {
            $snapshots[$user->id] = $this->snapshotFor($user, $period, $from, $settings);
        }
        $run->items()->delete();
        foreach ($snapshots as $userId => $snapshot) {
            $run->items()->create(['user_id' => $userId, 'snapshot' => $snapshot]);
        }
    }

    private function verifiedSettings(string $from): object
    {
        $settings = DB::table('payroll_settings')->where('effective_from', '<=', $from)->orderByDesc('effective_from')->first();
        if (! $settings || $settings->verified_at === null) {
            throw ValidationException::withMessages(['period' => 'Tarif BPJS periode ini belum diverifikasi Management.']);
        }

        return $settings;
    }

    private function snapshotFor(User $user, string $period, string $from, object $settings, int $additionalGross = 0): array
    {
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
            $thrItems = ThrItem::query()->where('user_id', $user->id)
                ->whereHas('run', fn ($query) => $query->whereYear('planned_payment_date', substr($period, 0, 4))
                    ->whereIn('status', ['APPROVED', 'PAID']))->with('run')->get();
            $currentThrGross = 0;
            $currentThrWithheld = 0;
            foreach ($thrItems as $item) {
                if ($item->run->planned_payment_date->format('Y-m') === $period) {
                    $currentThrGross += (int) $item->snapshot['gross_minor'];
                    $currentThrWithheld += (int) $item->snapshot['pph21_minor'];
                }
            }
            $snapshot = $this->calculator->calculate($user, $period, $additionalGross + $currentThrGross);
            if (str_ends_with($period, '-12')) {
                $history = PayrollItem::query()->where('user_id', $user->id)
                    ->whereHas('run', fn ($query) => $query->whereYear('period_start', substr($period, 0, 4))
                        ->whereIn('status', ['APPROVED', 'PAID']))->with('run')->get();
                $months = [];
                foreach ($history as $item) {
                    $months[(int) $item->run->period_start->format('n')] = $item->snapshot;
                }
                $thrHistory = [];
                foreach ($thrItems as $item) {
                    $month = (int) $item->run->planned_payment_date->format('n');
                    if ($month < 12) {
                        $thrHistory[$month]['gross'] = ($thrHistory[$month]['gross'] ?? 0) + (int) $item->snapshot['gross_minor'];
                        $thrHistory[$month]['withheld'] = ($thrHistory[$month]['withheld'] ?? 0) + (int) $item->snapshot['pph21_minor'];
                    }
                }
                foreach ($thrHistory as $month => $thr) {
                    if (isset($months[$month])) {
                        $months[$month]['taxable_gross_minor'] += $thr['gross'] - (int) ($months[$month]['thr_included_minor'] ?? 0);
                        $months[$month]['pph21_minor'] += $thr['withheld'];
                    }
                }
                $manual = PayrollPriorWithholding::query()->where('user_id', $user->id)
                    ->where('year', (int) substr($period, 0, 4))->first();
                $snapshot = $this->calculator->reconcileDecember($user, $snapshot, $months, $manual);
            }
            $snapshot['pph21_minor'] -= $currentThrWithheld;
            $snapshot['gross_pay_minor'] -= $currentThrGross;
            $snapshot['take_home_minor'] = $snapshot['gross_pay_minor'] - $snapshot['manual_deductions_minor']
                - $snapshot['bpjs_employee_total_minor'] - $snapshot['pph21_minor'];
            $snapshot['thr_included_minor'] = $currentThrGross;
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

        return $snapshot;
    }

    public function approve(PayrollRun $run, User $actor, int $expectedPriorAccrualId = 0): void
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        DB::transaction(function () use ($run, $actor, $expectedPriorAccrualId): void {
            $this->lockPostingSequence();
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $previousAccrual = $this->journal->latestPayrollAccrual($run);
            if ($run->status === 'APPROVED' || $run->status === 'PAID') {
                $predecessorId = $previousAccrual?->source_type === 'payroll_run_accrual_retry'
                    ? JournalEntry::query()->findOrFail($previousAccrual->source_id)->source_id : 0;
                if ($expectedPriorAccrualId !== $predecessorId) {
                    throw ValidationException::withMessages(['run' => 'Permintaan Approve ini sudah kedaluwarsa. Muat ulang run ya.']);
                }

                return;
            }
            $this->draftOnly($run);
            if ($run->period_start->format('Y-m-t') >= today()->toDateString()) {
                throw ValidationException::withMessages(['run' => 'Periode payroll masih berjalan. Tunggu sampai bulan selesai sebelum Approve ya.']);
            }
            if (($previousAccrual?->id ?? 0) !== $expectedPriorAccrualId) {
                throw ValidationException::withMessages(['run' => 'Permintaan Approve ini sudah kedaluwarsa. Muat ulang run ya.']);
            }
            // Re-evaluate rules and manual entries immediately before locking the snapshot.
            $this->calculate($run);
            $this->assertNoLockedDecemberDependency($run);
            $items = $run->items()->with('user')->get();
            if ($this->commissions->hasUnlockedClosing(
                $items->pluck('user_id')->map(fn ($id): int => (int) $id)->all(),
                $run->period_start->format('Y-m-d'), $run->period_start->format('Y-m-t'),
            )) {
                throw ValidationException::withMessages(['run' => 'Masih ada Closing Rider yang setoran-nya belum di-approve. Selesaikan dulu sebelum Approve payroll ya.']);
            }
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
            if ($previousAccrual && ! $previousAccrual->reversal()->exists()) {
                throw ValidationException::withMessages(['run' => 'Jurnal akrual sebelumnya masih aktif. Cek halaman Payroll ya.']);
            }
            $this->journal->post([
                'entry_date' => $run->period_start->format('Y-m-t'),
                'description' => 'Akrual payroll '.$run->period_start->format('Y-m'),
                'source_type' => $previousAccrual ? 'payroll_run_accrual_retry' : 'payroll_run',
                'source_id' => $previousAccrual ? $previousAccrual->reversal()->firstOrFail()->id : $run->id,
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
            $path = 'payroll-slips/'.$run->id.'/item-'.$item->id
                .($run->status === 'CANCELLED' ? '-cancelled' : '').'.pdf';
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
            DB::transaction(function () use ($run, $item, $path): void {
                $current = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                $wasCancelled = $run->status === 'CANCELLED';
                if (($current->status === 'CANCELLED') !== $wasCancelled
                    || ! in_array($current->status, ['APPROVED', 'PAID', 'CANCELLED'], true)) {
                    return;
                }
                PayrollItem::query()->whereKey($item->id)->where('run_id', $run->id)
                    ->update(['slip_path' => $path]);
            }, 3);
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

    public function markPaid(PayrollRun $run, User $actor, string $date, int $accountId, int $expectedPriorPaymentId = 0): void
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        DB::transaction(function () use ($run, $actor, $date, $accountId, $expectedPriorPaymentId): void {
            $this->lockPostingSequence();
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $previous = $this->journal->latestPayrollPayment($run);
            if ($run->status === 'PAID') {
                $predecessorId = $previous?->source_type === 'payroll_run_payment_retry'
                    ? JournalEntry::query()->findOrFail($previous->source_id)->source_id : 0;
                if ($expectedPriorPaymentId !== $predecessorId) {
                    throw ValidationException::withMessages(['payment' => 'Permintaan pembayaran ini sudah kedaluwarsa. Muat ulang run ya.']);
                }
                if ($run->payment_account_id !== $accountId || $run->paid_at?->format('Y-m-d') !== $date) {
                    throw ValidationException::withMessages(['payment' => 'Run ini sudah dibayar dengan rincian berbeda.']);
                }

                return;
            }
            if ($run->status !== 'APPROVED') {
                throw ValidationException::withMessages(['payment' => 'Approve run dulu sebelum menandai Paid.']);
            }
            if (($previous?->id ?? 0) !== $expectedPriorPaymentId) {
                throw ValidationException::withMessages(['payment' => 'Permintaan pembayaran ini sudah kedaluwarsa. Muat ulang run ya.']);
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
            if ($previous && ! $previous->reversal()->exists()) {
                throw ValidationException::withMessages(['payment' => 'Jurnal pembayaran sebelumnya masih aktif. Cek halaman Payroll ya.']);
            }
            $this->journal->post([
                'entry_date' => $date,
                'description' => 'Pembayaran payroll '.$run->period_start->format('Y-m'),
                'source_type' => $previous ? 'payroll_run_payment_retry' : 'payroll_run',
                'source_id' => $previous ? $previous->reversal()->firstOrFail()->id : $run->id,
                'purpose' => 'PAYROLL_PAYMENT', 'posted_by' => $actor->id,
            ], [
                ['account_id' => $this->accountId('2-1100'), 'debit_minor' => $total, 'credit_minor' => 0],
                ['account_id' => $accountId, 'debit_minor' => 0, 'credit_minor' => $total],
            ]);
            $run->update(['status' => 'PAID', 'paid_at' => $date, 'payment_account_id' => $accountId]);
            $this->audit($run, $actor, 'payroll_run_paid');
        }, 3);
    }

    public function cancelPayment(PayrollRun $run, User $actor, string $reason, int $expectedPaymentId): void
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        $this->validateCancellationReason($reason);
        DB::transaction(function () use ($run, $actor, $reason, $expectedPaymentId): void {
            $this->lockPostingSequence();
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $payment = $this->journal->latestPayrollPayment($run);
            if ($payment?->id !== $expectedPaymentId) {
                throw ValidationException::withMessages(['payment' => 'Pembayaran sudah berubah. Muat ulang run ya.']);
            }
            if ($run->status === 'APPROVED' && $payment?->reversal()->exists()) {
                return;
            }
            if ($run->status !== 'PAID' || ! $payment) {
                throw ValidationException::withMessages(['payment' => 'Belum ada pembayaran aktif yang bisa dibatalkan.']);
            }
            $this->journal->reverse($payment, $reason, $run, $actor);
        }, 3);
    }

    public function cancelRun(PayrollRun $run, User $actor, string $reason, int $expectedAccrualId): void
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        $this->validateCancellationReason($reason);
        DB::transaction(function () use ($run, $actor, $reason, $expectedAccrualId): void {
            // December approval uses the same chart lock before reading prior months.
            $this->lockPostingSequence();
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $accrual = $this->journal->latestPayrollAccrual($run);
            if ($accrual?->id !== $expectedAccrualId) {
                throw ValidationException::withMessages(['run' => 'Akrual sudah berubah. Muat ulang run ya.']);
            }
            if ($run->status === 'CANCELLED') {
                return;
            }
            if ($run->status !== 'APPROVED') {
                throw ValidationException::withMessages(['run' => $run->status === 'PAID'
                    ? 'Batalkan pembayaran dulu sebelum membatalkan run ya.'
                    : 'Approve run dulu sebelum bisa dibatalkan.']);
            }
            $this->assertNoLockedDecemberDependency($run);
            $this->journal->reverse($accrual, $reason, $run, $actor);
        }, 3);
        // The database already hides the old PDF path if rendering needs a retry.
        $this->ensureSlips($run);
    }

    public function reopenRun(PayrollRun $run, User $actor, int $expectedAccrualId): void
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        DB::transaction(function () use ($run, $actor, $expectedAccrualId): void {
            $run = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            $accrual = $this->journal->latestPayrollAccrual($run);
            if ($accrual?->id !== $expectedAccrualId || ! $accrual->reversal()->exists()) {
                throw ValidationException::withMessages(['run' => 'Run sudah berubah. Muat ulang dulu ya.']);
            }
            if ($run->status === 'DRAFT') {
                return;
            }
            if ($run->status !== 'CANCELLED') {
                throw ValidationException::withMessages(['run' => 'Batalkan run dulu sebelum dibuka lagi ya.']);
            }
            $approvedItems = $run->items()->orderBy('id')->get()
                ->map(fn (PayrollItem $item): array => [
                    'item_id' => $item->id,
                    'user_id' => $item->user_id,
                    'snapshot' => $item->snapshot,
                ])->all();
            $run->update(['status' => 'DRAFT', 'approved_by' => null, 'approved_at' => null]);
            $this->calculate($run);
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'actor_role' => $actor->role->value,
                'action' => 'payroll_run_reopened',
                'subject_type' => PayrollRun::class, 'subject_id' => $run->id,
                'before_json' => ['status' => 'CANCELLED', 'archived_item_count' => count($approvedItems)],
                'after_json' => [
                    'status' => 'DRAFT',
                    '_payroll_ciphertext' => Crypt::encryptString(json_encode([
                        'before' => ['approved_items' => $approvedItems],
                    ], JSON_THROW_ON_ERROR)),
                ],
                'ip' => request()->ip(),
            ]);
        }, 3);
    }

    private function validateCancellationReason(string $reason): void
    {
        if (trim($reason) === '' || mb_strlen($reason) > 225) {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan wajib diisi, maksimal 225 karakter ya.']);
        }
    }

    private function lockPostingSequence(): void
    {
        // Serialize approval and cancellation before either reads December dependencies.
        Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
    }

    private function assertNoLockedDecemberDependency(PayrollRun $run): void
    {
        if ($run->period_start->format('m') === '12') {
            return;
        }
        $employeeIds = $run->items()->pluck('user_id');
        $decemberLocked = PayrollRun::query()
            ->whereDate('period_start', $run->period_start->format('Y').'-12-01')
            ->whereIn('status', ['APPROVED', 'PAID'])
            ->whereHas('items', fn ($query) => $query->whereIn('user_id', $employeeIds))->exists();
        if ($decemberLocked) {
            throw ValidationException::withMessages(['run' => 'Payroll Desember sudah dikunci. Batalkan run Desember dulu ya.']);
        }
    }

    public function retrySlips(PayrollRun $run, User $actor): void
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        $run = $run->fresh();
        if (! in_array($run->status, ['APPROVED', 'PAID', 'CANCELLED'], true)) {
            throw ValidationException::withMessages(['slip' => 'Approve run dulu sebelum membuat slip ya.']);
        }
        $this->ensureSlips($run);
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
