<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\PayrollComponentEntry;
use App\Models\PayrollComponentType;
use App\Models\ThrRun;
use App\Models\ThrSettingVersion;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ThrService
{
    public function __construct(
        private readonly PayrollRunService $payroll,
        private readonly JournalService $journals,
    ) {}

    public function saveSettings(User $actor, array $input): ThrSettingVersion
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        $data = Validator::make($input, [
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'component_type_ids' => ['present', 'array'],
            'component_type_ids.*' => ['integer', 'distinct', 'exists:payroll_component_types,id'],
            'minimum_months' => ['required', 'integer', 'min:0', 'max:120'],
            'full_months' => ['required', 'integer', 'min:1', 'max:120'],
            'prorata_denominator' => ['required', 'integer', 'min:1', 'max:120'],
            'reminder_days' => ['required', 'integer', 'min:0', 'max:365'],
            'reserve_enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ])->validate();
        if ($data['minimum_months'] > $data['full_months']) {
            throw ValidationException::withMessages(['minimum_months' => 'Masa minimal harus paling besar sama dengan masa THR penuh ya.']);
        }
        $types = PayrollComponentType::query()->whereIn('id', $data['component_type_ids'])->get();
        foreach ($types as $type) {
            if ($type->kind !== 'EARNING' || ! $type->taxable || $type->effective_from->toDateString() > $data['effective_from']) {
                throw ValidationException::withMessages(['component_type_ids' => 'Pilih tunjangan tetap yang aktif dan kena pajak ya.']);
            }
        }
        $data['component_type_ids'] = array_map('intval', $data['component_type_ids']);
        sort($data['component_type_ids']);
        foreach (['minimum_months', 'full_months', 'prorata_denominator', 'reminder_days'] as $key) {
            $data[$key] = (int) $data[$key];
        }
        $data['reserve_enabled'] = (bool) $data['reserve_enabled'];

        return DB::transaction(function () use ($actor, $data): ThrSettingVersion {
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $existing = ThrSettingVersion::query()->whereDate('effective_from', $data['effective_from'])->first();
            if ($existing) {
                foreach ($data as $key => $value) {
                    $stored = $key === 'effective_from' ? $existing->effective_from->toDateString() : $existing->{$key};
                    if ($stored !== $value) {
                        throw ValidationException::withMessages(['effective_from' => 'Tanggal ini sudah punya versi aturan THR lain ya.']);
                    }
                }

                return $existing;
            }
            $setting = ThrSettingVersion::query()->create([...$data, 'created_by' => $actor->id]);
            AuditLog::recordAction($actor, 'thr.settings_created', $setting, null, ['effective_from' => $data['effective_from']]);

            return $setting;
        }, 3);
    }

    public function settingAt(string $date): ?ThrSettingVersion
    {
        return ThrSettingVersion::query()->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')->first();
    }

    public function paymentReminder(ThrRun $run): array
    {
        $due = $run->holiday_date->copy()->subDays($run->setting?->reminder_days ?? 7);

        return [
            'due_date' => $due->toDateString(),
            'due' => $run->status !== 'PAID' && today()->gte($due),
        ];
    }

    public function create(User $actor, array $input): ThrRun
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        $data = Validator::make($input, [
            'holiday_date' => ['required', 'date_format:Y-m-d'],
            'planned_payment_date' => ['required', 'date_format:Y-m-d'],
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'idempotency_key' => ['required', 'string', 'max:80'],
        ])->validate();
        $data['user_ids'] = array_map('intval', $data['user_ids']);
        sort($data['user_ids']);

        try {
            return DB::transaction(function () use ($actor, $data): ThrRun {
                $existing = ThrRun::query()->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    return $this->replay($existing, $data);
                }
                if (ThrRun::query()->whereDate('holiday_date', $data['holiday_date'])->exists()) {
                    throw ValidationException::withMessages(['holiday_date' => 'Run THR untuk hari raya ini sudah ada ya.']);
                }
                $setting = $this->settingAt($data['planned_payment_date']);
                $run = ThrRun::query()->create([
                    'holiday_date' => $data['holiday_date'],
                    'planned_payment_date' => $data['planned_payment_date'],
                    'setting_version_id' => $setting?->id,
                    'status' => 'DRAFT', 'idempotency_key' => $data['idempotency_key'],
                    'created_by' => $actor->id,
                ]);
                $this->calculateItems($run, $data['user_ids'], $actor);
                AuditLog::recordAction($actor, 'thr.run_created', $run, null, ['holiday_date' => $data['holiday_date'], 'user_count' => count($data['user_ids'])]);

                return $run->refresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = ThrRun::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $this->replay($existing, $data);
            }
            if (ThrRun::query()->whereDate('holiday_date', $data['holiday_date'])->exists()) {
                throw ValidationException::withMessages(['holiday_date' => 'Run THR untuk hari raya ini sudah ada ya.']);
            }

            throw $exception;
        }
    }

    private function replay(ThrRun $existing, array $data): ThrRun
    {
        $ids = $existing->items()->orderBy('user_id')->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
        if ($existing->holiday_date->toDateString() !== $data['holiday_date']
            || $existing->planned_payment_date->toDateString() !== $data['planned_payment_date']
            || $ids !== $data['user_ids']) {
            throw ValidationException::withMessages(['idempotency_key' => 'Kunci run THR ini sudah dipakai untuk data lain ya.']);
        }

        return $existing;
    }

    private function calculateItems(ThrRun $run, array $userIds, User $actor): void
    {
        $setting = $run->setting;
        $minimum = $setting?->minimum_months ?? 1;
        $full = $setting?->full_months ?? 12;
        $denominator = $setting?->prorata_denominator ?? 12;
        $componentIds = $setting?->component_type_ids ?? [];
        $period = $run->planned_payment_date->format('Y-m');
        foreach (User::query()->with('employeeProfile')->whereIn('id', $userIds)->orderBy('id')->get() as $user) {
            $profile = $user->employeeProfile;
            $salaryVersion = $profile?->salaryVersionFor($period.'-01');
            if (! $profile?->payroll_active || ! is_int($salaryVersion?->base_salary_minor) || ! $user->joined_at) {
                throw ValidationException::withMessages(['user_ids' => 'Profil, gaji, atau tanggal masuk '.$user->name.' belum siap untuk THR ya.']);
            }
            $joined = Carbon::parse($user->joined_at)->startOfDay();
            $holiday = $run->holiday_date->copy()->startOfDay();
            $months = $joined->gt($holiday) ? 0 : (int) floor($joined->diffInMonths($holiday));
            $base = $salaryVersion->base_salary_minor;
            if ($componentIds !== []) {
                $entries = PayrollComponentEntry::query()->where('user_id', $user->id)
                    ->whereDate('period_start', $period.'-01')
                    ->whereIn('component_type_id', $componentIds)->get();
                foreach ($entries as $entry) {
                    $base += $entry->amount_minor;
                }
            }
            if ($base < 0 || $base > 1000000000000) {
                throw ValidationException::withMessages(['user_ids' => 'Dasar THR '.$user->name.' terlalu besar ya.']);
            }
            $gross = $months < $minimum ? 0 : ($months >= $full ? $base : intdiv($base * $months + intdiv($denominator, 2), $denominator));
            if ($gross > 1000000000000) {
                throw ValidationException::withMessages(['user_ids' => 'Nominal THR '.$user->name.' terlalu besar ya.']);
            }
            $tax = 0;
            if ($gross > 0) {
                $baseline = $this->payroll->preview($user, $period, 0, $actor);
                $combined = $this->payroll->preview($user, $period, $gross, $actor);
                $tax = $combined['pph21_minor'] - $baseline['pph21_minor'];
            }
            $run->items()->create(['user_id' => $user->id, 'snapshot' => [
                'base_minor' => $base, 'months' => $months, 'gross_minor' => $gross,
                'pph21_minor' => $tax, 'net_minor' => $gross - $tax,
                'ptkp_status' => $profile->ptkp_status,
                'period' => $period, 'setting_version_id' => $setting?->id,
                'salary_effective_from' => $salaryVersion->effective_from->format('Y-m-d'),
            ]]);
        }
    }

    public function provision(User $actor, string $month): int
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        Validator::make(['month' => $month], ['month' => ['required', 'date_format:Y-m']])->validate();
        $setting = $this->settingAt($month.'-01');
        if (! $setting?->reserve_enabled) {
            throw ValidationException::withMessages(['month' => 'Pencadangan THR belum aktif untuk bulan ini ya.']);
        }

        return DB::transaction(function () use ($actor, $month, $setting): int {
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $existing = DB::table('thr_provisions')->where('month', $month.'-01')->first();
            if ($existing) {
                return (int) $existing->amount_minor;
            }
            $total = 0;
            $users = User::query()->with('employeeProfile')
                ->whereHas('employeeProfile', fn ($query) => $query->where('payroll_active', true))
                ->whereDate('joined_at', '<=', Carbon::parse($month.'-01')->endOfMonth())->get();
            foreach ($users as $user) {
                $base = (int) $user->employeeProfile->salaryVersionFor($month.'-01')?->base_salary_minor;
                foreach (PayrollComponentEntry::query()->where('user_id', $user->id)
                    ->whereDate('period_start', $month.'-01')
                    ->whereIn('component_type_id', $setting->component_type_ids)->get() as $entry) {
                    $base += $entry->amount_minor;
                }
                $total += intdiv($base + intdiv($setting->full_months, 2), $setting->full_months);
            }
            if ($total <= 0 || $total > 1000000000000) {
                throw ValidationException::withMessages(['month' => 'Belum ada nominal cadangan THR yang bisa dicatat ya.']);
            }
            $id = DB::table('thr_provisions')->insertGetId([
                'month' => $month.'-01', 'amount_minor' => $total,
                'setting_version_id' => $setting->id, 'created_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->journals->post([
                'entry_date' => Carbon::parse($month.'-01')->endOfMonth()->toDateString(),
                'description' => 'Cadangan THR '.$month, 'source_type' => 'thr_provision',
                'source_id' => $id, 'purpose' => 'THR_PROVISION', 'posted_by' => $actor->id,
            ], [
                $this->line('6-2200', $total, 0), $this->line('2-1700', 0, $total),
            ]);
            AuditLog::recordAction($actor, 'thr.provision_posted', $actor, null, ['month' => $month]);

            return $total;
        }, 3);
    }

    public function approve(User $actor, ThrRun $run): ThrRun
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        DB::transaction(function () use ($actor, $run): void {
            Account::query()->orderBy('id')->lockForUpdate()->firstOrFail();
            $locked = ThrRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['APPROVED', 'PAID'], true)) {
                return;
            }
            if ($locked->status !== 'DRAFT') {
                throw ValidationException::withMessages(['run' => 'Run THR ini sudah dibatalkan ya.']);
            }
            $ids = $locked->items()->pluck('user_id')->all();
            $locked->items()->delete();
            $this->calculateItems($locked, $ids, $actor);
            $gross = 0;
            $tax = 0;
            foreach ($locked->items as $item) {
                $gross += $item->snapshot['gross_minor'];
                $tax += $item->snapshot['pph21_minor'];
            }
            if ($gross < 0 || $gross > 1000000000000 || abs($tax) > $gross) {
                throw ValidationException::withMessages(['run' => 'Total THR atau PPh 21 belum valid ya.']);
            }
            if ($gross > 0) {
                $reserve = (int) DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
                    ->join('accounts as a', 'a.id', '=', 'l.account_id')
                    ->where('a.code', '2-1700')->where('j.entry_date', '<=', $locked->planned_payment_date->toDateString())
                    ->selectRaw('COALESCE(SUM(CAST(l.credit_minor AS SIGNED) - CAST(l.debit_minor AS SIGNED)), 0) AS balance')->value('balance');
                $used = min(max(0, $reserve), $gross);
                $lines = [$this->line('2-1710', 0, $gross - $tax)];
                if ($used > 0) {
                    $lines[] = $this->line('2-1700', $used, 0);
                }
                if ($gross > $used) {
                    $lines[] = $this->line('6-2200', $gross - $used, 0);
                }
                if ($tax > 0) {
                    $lines[] = $this->line('2-1300', 0, $tax);
                } elseif ($tax < 0) {
                    $lines[] = $this->line('2-1300', -$tax, 0);
                }
                $this->journals->post([
                    'entry_date' => $locked->planned_payment_date->toDateString(),
                    'description' => 'Akrual THR '.$locked->holiday_date->toDateString(),
                    'source_type' => 'thr_run', 'source_id' => $locked->id,
                    'purpose' => 'THR_ACCRUAL', 'posted_by' => $actor->id,
                ], $lines);
            }
            $locked->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now()]);
            AuditLog::recordAction($actor, 'thr.run_approved', $locked, ['status' => 'DRAFT'], ['status' => 'APPROVED']);
        }, 3);
        $this->ensureSlips($run);

        return $run->refresh();
    }

    public function pay(User $actor, ThrRun $run, string $date, int $accountId): ThrRun
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        Validator::make(['date' => $date, 'account_id' => $accountId], [
            'date' => ['required', 'date_format:Y-m-d'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
        ])->validate();

        return DB::transaction(function () use ($actor, $run, $date, $accountId): ThrRun {
            $locked = ThrRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'PAID') {
                if ($locked->paid_on?->toDateString() !== $date || $locked->payment_account_id !== $accountId) {
                    throw ValidationException::withMessages(['run' => 'THR ini sudah dibayar dengan rincian lain ya.']);
                }

                return $locked;
            }
            if ($locked->status !== 'APPROVED' || substr($date, 0, 7) !== $locked->planned_payment_date->format('Y-m')) {
                throw ValidationException::withMessages(['date' => 'Approve run dulu dan bayar pada bulan rencana yang sama ya.']);
            }
            $account = Account::query()->find($accountId);
            if (! $account?->is_active || ! in_array($account->code, ['1-1100', '1-1200', '1-1300'], true)) {
                throw ValidationException::withMessages(['account_id' => 'Pilih akun kas atau bank yang aktif ya.']);
            }
            $total = $locked->items->sum(fn ($item): int => $item->snapshot['net_minor']);
            if ($total <= 0) {
                throw ValidationException::withMessages(['run' => 'Total bayar THR harus positif ya.']);
            }
            $this->journals->post([
                'entry_date' => $date, 'description' => 'Pembayaran THR '.$locked->holiday_date->toDateString(),
                'source_type' => 'thr_run', 'source_id' => $locked->id,
                'purpose' => 'THR_PAYMENT', 'posted_by' => $actor->id,
            ], [$this->line('2-1710', $total, 0),
                ['account_id' => $accountId, 'debit_minor' => 0, 'credit_minor' => $total]]);
            $locked->update(['status' => 'PAID', 'paid_on' => $date, 'payment_account_id' => $accountId]);
            AuditLog::recordAction($actor, 'thr.run_paid', $locked, ['status' => 'APPROVED'], ['status' => 'PAID']);

            return $locked;
        }, 3);
    }

    public function retrySlips(User $actor, ThrRun $run): void
    {
        Gate::forUser($actor)->authorize('manage-payroll');
        if (! in_array($run->fresh()->status, ['APPROVED', 'PAID'], true)) {
            throw ValidationException::withMessages(['run' => 'Approve run THR dulu ya.']);
        }
        $this->ensureSlips($run);
    }

    private function ensureSlips(ThrRun $run): void
    {
        foreach ($run->fresh()->items()->with('user')->get() as $item) {
            $path = 'thr-slips/'.$run->id.'/item-'.$item->id.'.pdf';
            if (! Storage::disk('local')->exists($path)) {
                $temporary = $path.'.'.Str::uuid().'.tmp';
                try {
                    $pdf = Pdf::loadView('payroll.thr-slip', ['run' => $run, 'item' => $item, 'snapshot' => $item->snapshot]);
                    if (! Storage::disk('local')->put($temporary, $pdf->output())
                        || ! Storage::disk('local')->move($temporary, $path)) {
                        throw ValidationException::withMessages(['slip' => 'Slip THR belum tersimpan. Coba ulang ya.']);
                    }
                } finally {
                    Storage::disk('local')->delete($temporary);
                }
            }
            $item->update(['slip_path' => $path]);
        }
    }

    private function line(string $code, int $debit, int $credit): array
    {
        $id = Account::query()->where('code', $code)->value('id');
        if (! $id) {
            throw ValidationException::withMessages(['account' => 'Akun THR '.$code.' belum tersedia ya.']);
        }

        return ['account_id' => $id, 'debit_minor' => $debit, 'credit_minor' => $credit];
    }
}
