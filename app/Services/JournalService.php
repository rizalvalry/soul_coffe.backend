<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceMovement;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\PayrollRemittance;
use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** The only application writer for posted accounting entries and lines. */
class JournalService
{
    public function isAccountingDate(string $date): bool
    {
        $opening = JournalEntry::query()->where('source_type', 'opening_balance')->where('purpose', 'OPENING')
            ->whereDoesntHave('reversal')->first();

        return ! $opening || $date >= $opening->entry_date->toDateString();
    }

    public function postOpening(string $date, array $lines): JournalEntry
    {
        Validator::make(['entry_date' => $date], ['entry_date' => ['required', 'date_format:Y-m-d']])->validate();
        $assetAccountId = Account::query()->where('code', '1-1800')->value('id');
        $seenAssets = [];
        foreach ($lines as $index => $line) {
            $memo = (string) ($line['memo'] ?? '');
            if (! str_starts_with($memo, 'ASSET:')) {
                continue;
            }
            $uuid = substr($memo, 6);
            $asset = FixedAsset::query()->where('uuid', $uuid)->where('acquisition_mode', 'OPENING')->first();
            if (! $asset || isset($seenAssets[$uuid]) || $asset->acquired_on->toDateString() > $date
                || (int) ($line['account_id'] ?? 0) !== (int) $assetAccountId
                || (int) ($line['debit_minor'] ?? 0) !== (int) $asset->cost_minor
                || (int) ($line['credit_minor'] ?? 0) !== 0) {
                throw ValidationException::withMessages(["lines.$index.memo" => 'Pilih aset saldo awal dan isi debit sesuai harga perolehannya ya.']);
            }
            $seenAssets[$uuid] = true;
        }

        return DB::transaction(function () use ($date, $lines): JournalEntry {
            // One shared chart row serializes opening submissions across dates and operators.
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $active = JournalEntry::query()->where('source_type', 'opening_balance')->where('purpose', 'OPENING')
                ->whereDoesntHave('reversal')->with('lines')->first();
            if ($active) {
                $current = $active->lines->map(fn ($line): array => [
                    'account_id' => (int) $line->account_id,
                    'debit_minor' => (int) $line->debit_minor,
                    'credit_minor' => (int) $line->credit_minor,
                    'cart_id' => $line->cart_id === null ? null : (int) $line->cart_id,
                    'memo' => $line->memo,
                ])->all();
                $requested = array_map(fn (array $line): array => [
                    'account_id' => (int) ($line['account_id'] ?? 0),
                    'debit_minor' => (int) ($line['debit_minor'] ?? 0),
                    'credit_minor' => (int) ($line['credit_minor'] ?? 0),
                    'cart_id' => isset($line['cart_id']) ? (int) $line['cart_id'] : null,
                    'memo' => $line['memo'] ?? null,
                ], $lines);
                if ($active->entry_date->toDateString() === $date && $current === $requested) {
                    return $active;
                }
                throw ValidationException::withMessages(['opening' => 'Saldo awal sudah diposting. Balik jurnal sebelumnya sebelum mengganti saldo awal.']);
            }

            if (JournalEntry::query()->whereDate('entry_date', '<', $date)
                ->whereNotIn('source_type', ['opening_balance', 'journal_reversal'])->exists()) {
                throw ValidationException::withMessages(['entry_date' => 'Tanggal cutover harus mendahului semua jurnal yang sudah diposting.']);
            }

            $nextSourceId = (int) JournalEntry::query()->where('source_type', 'opening_balance')
                ->max('source_id') + 1;

            return $this->postInternal([
                'entry_date' => $date,
                'description' => 'Saldo awal per '.$date,
                'source_type' => 'opening_balance',
                'source_id' => $nextSourceId,
                'purpose' => 'OPENING',
            ], $lines);
        }, 3);
    }

    public function post(array $header, array $lines): JournalEntry
    {
        foreach (['source_type', 'purpose'] as $field) {
            $value = $header[$field] ?? null;
            // MySQL's unicode_ci collation ignores case, accents, and trailing spaces. Keep
            // identifiers ASCII and unpadded so PHP's reserved-key guard matches DB equality.
            if (! is_string($value) || preg_match('/\A[\x21-\x7E]+\z/D', $value) !== 1) {
                throw ValidationException::withMessages([$field => 'Identitas sumber jurnal harus berupa teks ASCII tanpa spasi.']);
            }
        }

        if (isset($header['reverses_entry_id'])
            || in_array(strtolower((string) ($header['source_type'] ?? '')), ['opening_balance', 'journal_reversal'], true)
            || in_array(strtoupper((string) ($header['purpose'] ?? '')), ['OPENING', 'REVERSAL'], true)) {
            throw ValidationException::withMessages(['purpose' => 'Jurnal saldo awal dan pembalik harus melalui alur khusus.']);
        }

        return $this->postInternal($header, $lines);
    }

    private function postInternal(array $header, array $lines): JournalEntry
    {
        $header = Validator::make($header, [
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'source_type' => ['required', 'string', 'max:80'],
            'source_id' => ['required', 'integer', 'min:1'],
            'purpose' => ['required', 'string', 'max:40'],
            'posted_by' => ['nullable', 'integer', 'exists:users,id'],
            'reverses_entry_id' => ['nullable', 'integer', 'exists:journal_entries,id'],
        ])->validate();

        try {
            return DB::transaction(function () use ($header, $lines): JournalEntry {
                // Every writer shares this stable chart lock before checking cutover or sequence.
                Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
                $date = Carbon::createFromFormat('Y-m-d', $header['entry_date']);
                $period = $this->lockPeriod($date);

                // Current reads after the period lock avoid a stale REPEATABLE READ snapshot.
                $existing = JournalEntry::query()->where('source_type', $header['source_type'])
                    ->where('source_id', $header['source_id'])->where('purpose', $header['purpose'])
                    ->lockForUpdate()->first();
                if ($existing) {
                    return $existing;
                }

                if ($period->status !== 'OPEN') {
                    throw ValidationException::withMessages(['entry_date' => 'Periode akuntansi sudah ditutup.']);
                }

                if ($header['purpose'] !== 'OPENING') {
                    $opening = JournalEntry::query()->where('source_type', 'opening_balance')
                        ->where('purpose', 'OPENING')->whereDoesntHave('reversal')->first();
                    if ($opening && $date->toDateString() < $opening->entry_date->toDateString()) {
                        throw ValidationException::withMessages(['entry_date' => 'Jurnal tidak boleh mendahului tanggal cutover.']);
                    }
                    if (! $opening && $header['purpose'] !== 'REVERSAL'
                        && JournalEntry::query()->where('source_type', 'opening_balance')->exists()) {
                        throw ValidationException::withMessages(['entry_date' => 'Ganti saldo awal yang dibalik sebelum memposting jurnal baru.']);
                    }
                }

                Validator::make(['lines' => $lines], [
                    'lines' => ['required', 'array', 'min:2', 'max:1000'],
                    'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
                    'lines.*.debit_minor' => ['required', 'integer', 'min:0', 'max:1000000000000'],
                    'lines.*.credit_minor' => ['required', 'integer', 'min:0', 'max:1000000000000'],
                    'lines.*.cart_id' => ['nullable', 'integer', 'exists:carts,id'],
                    'lines.*.memo' => ['nullable', 'string', 'max:255'],
                ])->validate();

                $debit = 0;
                $credit = 0;
                foreach ($lines as $index => $line) {
                    if (($line['debit_minor'] > 0) === ($line['credit_minor'] > 0)) {
                        throw ValidationException::withMessages(["lines.$index" => 'Tepat satu sisi debit atau kredit harus lebih dari nol.']);
                    }
                    $debit += $line['debit_minor'];
                    $credit += $line['credit_minor'];
                }
                if ($debit !== $credit) {
                    throw ValidationException::withMessages(['lines' => 'Jumlah debit dan kredit harus sama.']);
                }

                $accounts = Account::query()->whereIn('id', array_column($lines, 'account_id'))
                    ->lockForUpdate()->get()->keyBy('id');
                foreach ($lines as $index => $line) {
                    if (! $accounts->has($line['account_id'])
                        || ($header['purpose'] !== 'REVERSAL' && ! $accounts->get($line['account_id'])->is_active)) {
                        throw ValidationException::withMessages(["lines.$index.account_id" => 'Akun tidak aktif.']);
                    }
                }

                $last = JournalEntry::query()->where('period_id', $period->id)
                    ->orderByDesc('number')->lockForUpdate()->value('number');
                $sequence = $last ? (int) substr($last, -4) + 1 : 1;
                if ($sequence > 9999) {
                    throw ValidationException::withMessages(['entry_date' => 'Nomor jurnal bulanan telah mencapai batas.']);
                }

                $entry = JournalEntry::query()->create([
                    ...$header,
                    'number' => sprintf('JU-%04d%02d-%04d', $period->year, $period->month, $sequence),
                    'period_id' => $period->id,
                    'status' => 'POSTED',
                    'posted_by' => $header['posted_by'] ?? Auth::id(),
                ]);

                foreach ($lines as $line) {
                    $entry->lines()->create($line);
                }

                if ($header['source_type'] === 'manual' && $header['purpose'] === 'MANUAL') {
                    AuditLog::query()->create([
                        'actor_id' => Auth::id(),
                        'actor_role' => Auth::user()?->role?->value,
                        'action' => 'journal.manual.posted',
                        'subject_type' => JournalEntry::class,
                        'subject_id' => $entry->id,
                        'after_json' => ['number' => $entry->number, 'source_id' => $entry->source_id],
                        'ip' => request()->ip(),
                    ]);
                }

                return $entry;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent source replay may have won in another period. Its row is authoritative.
            $existing = JournalEntry::query()->where('source_type', $header['source_type'])
                ->where('source_id', $header['source_id'])->where('purpose', $header['purpose'])->first();
            if ($existing) {
                return $existing;
            }
            throw $exception;
        }
    }

    public function latestPayrollPayment(PayrollRun $run): ?JournalEntry
    {
        return $this->latestPayrollJournal($run, 'PAYROLL_PAYMENT', 'payroll_run_payment_retry');
    }

    public function latestPayrollAccrual(PayrollRun $run): ?JournalEntry
    {
        return $this->latestPayrollJournal($run, 'PAYROLL_ACCRUAL', 'payroll_run_accrual_retry');
    }

    private function latestPayrollJournal(PayrollRun $run, string $purpose, string $retrySource): ?JournalEntry
    {
        $entry = JournalEntry::query()->where('source_type', 'payroll_run')
            ->where('source_id', $run->id)->where('purpose', $purpose)->first();
        while ($entry) {
            $reversal = $entry->reversal()->first();
            if (! $reversal) {
                break;
            }
            $next = JournalEntry::query()->where('source_type', $retrySource)
                ->where('source_id', $reversal->id)->where('purpose', $purpose)->first();
            if (! $next) {
                break;
            }
            $entry = $next;
        }

        return $entry;
    }

    public function reverse(JournalEntry $entry, string $reason, ?PayrollRun $payrollRun = null, ?User $payrollActor = null,
        ?PayrollRemittance $remittance = null, ?User $remittanceActor = null, ?EmployeeAdvance $advance = null,
        ?EmployeeAdvanceMovement $advanceMovement = null): JournalEntry
    {
        if (trim($reason) === '' || mb_strlen($reason) > 225) {
            throw ValidationException::withMessages(['reason' => 'Alasan pembalik wajib diisi, maksimal 225 karakter.']);
        }

        return DB::transaction(function () use ($entry, $reason, $payrollRun, $payrollActor, $remittance, $remittanceActor, $advance, $advanceMovement): JournalEntry {
            if ($payrollRun || $remittance) {
                Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            }
            $run = $payrollRun ? PayrollRun::query()->whereKey($payrollRun->id)->lockForUpdate()->firstOrFail() : null;
            $original = JournalEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $isAdvance = $original->source_type === 'employee_advance' && $advance
                && $original->source_id === $advance->id;
            $isAdvanceRepayment = $original->source_type === 'employee_advance_repayment'
                && $advanceMovement && $advanceMovement->kind === 'CASH_REPAYMENT'
                && $advanceMovement->journal_entry_id === $original->id;
            if (str_starts_with($original->source_type, 'employee_advance')
                && ! $isAdvance && ! $isAdvanceRepayment) {
                throw ValidationException::withMessages(['journal' => 'Balik jurnal kasbon lewat halaman Kasbon Karyawan ya.']);
            }
            if (in_array($original->source_type, ['rider_commission_lock', 'rider_incentive_operation'], true)) {
                throw ValidationException::withMessages(['journal' => 'Koreksi saldo insentif Rider lewat mutasi baru di halaman Saldo Insentif Rider ya.']);
            }
            if (in_array($original->source_type, ['thr_run', 'thr_provision'], true)) {
                throw ValidationException::withMessages(['journal' => 'Jurnal THR ini dikelola dari halaman THR ya.']);
            }
            if ($original->source_type === 'payroll_remittance') {
                if (! $remittance || ! $remittanceActor || $remittance->id !== $original->source_id
                    || $remittance->status !== 'POSTED') {
                    throw ValidationException::withMessages(['journal' => 'Balik jurnal setoran lewat halaman Setor PPh 21 & BPJS ya.']);
                }
                Gate::forUser($remittanceActor)->authorize('manage-payroll');
            }
            $isPayroll = in_array($original->source_type, ['payroll_run', 'payroll_run_payment_retry', 'payroll_run_accrual_retry'], true);
            if ($isPayroll) {
                if (! $run || ! $payrollActor) {
                    throw ValidationException::withMessages(['journal' => 'Balik jurnal payroll lewat halaman Payroll ya.']);
                }
                Gate::forUser($payrollActor)->authorize('manage-payroll');
                $isPayment = $original->purpose === 'PAYROLL_PAYMENT'
                    && in_array($original->source_type, ['payroll_run', 'payroll_run_payment_retry'], true);
                $isAccrual = $original->purpose === 'PAYROLL_ACCRUAL'
                    && in_array($original->source_type, ['payroll_run', 'payroll_run_accrual_retry'], true)
                    && $this->latestPayrollAccrual($run)?->id === $original->id;
                if (($isPayment && ($run->status !== 'PAID' || $this->latestPayrollPayment($run)?->id !== $original->id))
                    || ($isAccrual && $run->status !== 'APPROVED') || (! $isPayment && ! $isAccrual)) {
                    throw ValidationException::withMessages(['journal' => 'Status run dan jurnal payroll tidak cocok. Cek halaman Payroll ya.']);
                }
            }
            if ($original->reverses_entry_id !== null || $original->reversal()->exists()) {
                throw ValidationException::withMessages(['journal' => 'Jurnal ini sudah dibalik atau merupakan jurnal pembalik.']);
            }

            $lines = $original->lines()->get()->map(fn ($line): array => [
                'account_id' => $line->account_id,
                'debit_minor' => $line->credit_minor,
                'credit_minor' => $line->debit_minor,
                'cart_id' => $line->cart_id,
                'memo' => $isPayroll ? 'Pembatalan payroll' : ($isAdvance || $isAdvanceRepayment ? 'Pembatalan kasbon' : $reason),
            ])->all();

            // A reversal is a new posting in the current open period, never an edit of history.
            $reversal = $this->postInternal([
                'entry_date' => $original->purpose === 'OPENING'
                    ? $original->entry_date->toDateString() : today()->toDateString(),
                'description' => $isPayroll ? 'Pembalik jurnal payroll '.$original->number
                    : 'Pembalik '.$original->number.($isAdvance || $isAdvanceRepayment ? '' : ': '.$reason),
                'source_type' => 'journal_reversal',
                'source_id' => $original->id,
                'purpose' => 'REVERSAL',
                'posted_by' => $payrollActor?->id ?? $remittanceActor?->id ?? Auth::id() ?? $original->posted_by,
                'reverses_entry_id' => $original->id,
            ], $lines);

            if ($isPayroll) {
                $before = $run->status;
                $run->update($original->purpose === 'PAYROLL_PAYMENT'
                    ? ['status' => 'APPROVED', 'paid_at' => null, 'payment_account_id' => null]
                    : ['status' => 'CANCELLED']);
                if ($run->status === 'CANCELLED') {
                    $run->items()->update(['slip_path' => null]);
                }
                AuditLog::query()->create([
                    'actor_id' => $payrollActor->id,
                    'actor_role' => $payrollActor->role->value,
                    'action' => $original->purpose === 'PAYROLL_PAYMENT' ? 'payroll_payment_cancelled' : 'payroll_run_cancelled',
                    'subject_type' => PayrollRun::class,
                    'subject_id' => $run->id,
                    'before_json' => ['status' => $before],
                    'after_json' => [
                        'status' => $run->status,
                        'reversal_entry_id' => $reversal->id,
                        '_payroll_ciphertext' => Crypt::encryptString(json_encode(['after' => ['reason' => $reason]], JSON_THROW_ON_ERROR)),
                    ],
                    'ip' => request()->ip(),
                ]);
            }

            AuditLog::query()->create([
                'actor_id' => $payrollActor?->id ?? $remittanceActor?->id ?? Auth::id(),
                'actor_role' => $payrollActor?->role->value ?? $remittanceActor?->role->value ?? Auth::user()?->role?->value,
                'action' => 'journal.reversed',
                'subject_type' => JournalEntry::class,
                'subject_id' => $original->id,
                'before_json' => ['status' => 'POSTED'],
                'after_json' => $isPayroll || $isAdvance || $isAdvanceRepayment
                    ? ['reversal_entry_id' => $reversal->id]
                    : ['reversal_entry_id' => $reversal->id, 'reason' => $reason],
                'ip' => request()->ip(),
            ]);

            return $reversal;
        }, 3);
    }

    public function lockPeriod(Carbon $date): AccountingPeriod
    {
        DB::table('accounting_periods')->insertOrIgnore([
            'year' => $date->year,
            'month' => $date->month,
            'status' => 'OPEN',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return AccountingPeriod::query()->where('year', $date->year)->where('month', $date->month)
            ->lockForUpdate()->firstOrFail();
    }

    /** Call inside the business transaction before new stock or asset state is written. */
    public function assertOpenDate(string $date): void
    {
        // Match postInternal's chart -> period order for callers that check before posting.
        Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
        if ($this->lockPeriod(Carbon::parse($date))->status !== 'OPEN') {
            throw ValidationException::withMessages(['entry_date' => 'Periode akuntansi sudah ditutup.']);
        }
    }
}
