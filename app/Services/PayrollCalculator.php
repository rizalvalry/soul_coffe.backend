<?php

namespace App\Services;

use App\Models\PayrollComponentEntry;
use App\Models\PayrollPriorWithholding;
use App\Models\User;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PayrollCalculator
{
    public function __construct(
        private readonly RiderCommissionService $commissions,
        private readonly RiderIncentiveBalanceService $balances,
    ) {}

    /**
     * Monthly TER estimate. December still needs the annual reconciliation in T-013.
     * All money is integer rupiah; every percentage rounds half up to one rupiah.
     *
     * @return array<string, mixed>
     */
    public function calculate(User $user, string $period, int $additionalGross = 0): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period)) {
            throw new InvalidArgumentException('Periode payroll harus format YYYY-MM ya.');
        }

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $period.'-01');
        if (! $start || $start->format('Y-m') !== $period) {
            throw new InvalidArgumentException('Periode payroll belum valid ya.');
        }
        if ($additionalGross < 0) {
            throw new InvalidArgumentException('Tambahan bruto payroll harus rupiah bulat ya.');
        }
        $from = $start->format('Y-m-d');
        $through = $start->format('Y-m-t');

        $profile = $user->employeeProfile;
        if (! $profile || ! $profile->payroll_active || $profile->base_salary_minor === null) {
            throw new DomainException('Profil payroll belum aktif atau gaji pokok belum diisi.');
        }
        $baseSalary = $profile->base_salary_minor;
        if (! is_int($baseSalary) || $baseSalary < 0) {
            throw new DomainException('Gaji pokok harus rupiah bulat dan tidak boleh negatif.');
        }

        $cups = 0;
        // Cups remain informational on the slip. Only posted balance plus pre-feature locks
        // is payable; unapproved Closing estimates never create payroll income.
        foreach ($this->commissions->summaries([$user->id], $from, $through)[$user->id] ?? [] as $day) {
            $cups = self::add($cups, $day['cups_sold']);
        }
        $balanceIncentive = $this->balances->payableBalance($user->id, $through);
        $legacyIncentive = $this->balances->legacyAmount($user->id, $from, $through);
        $incentive = self::add($balanceIncentive, $legacyIncentive);

        $components = [];
        $manualEarnings = 0;
        $taxableEarnings = 0;
        $manualDeductions = 0;
        $entries = PayrollComponentEntry::query()->with('type')
            ->where('user_id', $user->id)->whereDate('period_start', $from)->orderBy('id')->get();
        foreach ($entries as $entry) {
            $type = $entry->type;
            $amount = $entry->amount_minor;
            if (! $type || ! is_int($amount) || $amount < 0 || $type->effective_from->format('Y-m-d') > $from) {
                throw new DomainException('Komponen payroll belum valid ya.');
            }
            if ($type->kind === 'EARNING') {
                $manualEarnings = self::add($manualEarnings, $amount);
                if ($type->taxable) {
                    $taxableEarnings = self::add($taxableEarnings, $amount);
                }
            } elseif ($type->kind === 'DEDUCTION') {
                $manualDeductions = self::add($manualDeductions, $amount);
            } else {
                throw new DomainException('Jenis komponen payroll belum valid ya.');
            }
            $components[] = [
                'entry_id' => $entry->id, 'name' => $type->name, 'kind' => $type->kind,
                'code' => $type->code, 'account_id' => $type->account_id,
                'taxable' => $type->taxable, 'amount_minor' => $amount,
            ];
        }

        $settings = DB::table('payroll_settings')
            ->where('effective_from', '<=', $from)->orderByDesc('effective_from')->first();
        $bpjsEmployee = [];
        $bpjsEmployer = [];
        foreach (['jht', 'jp', 'jkk', 'jkm', 'health'] as $part) {
            $enrolled = $part === 'health' ? $profile->bpjs_kes_enrolled : $profile->bpjs_tk_enrolled;
            if ($enrolled && ! $settings) {
                throw new DomainException('Tarif BPJS belum diisi dan diverifikasi akuntan, jadi payroll belum bisa dihitung.');
            }
            $wage = $baseSalary;
            if ($part === 'jp' && $settings && (int) $settings->jp_wage_cap_minor > 0) {
                $wage = min($wage, (int) $settings->jp_wage_cap_minor);
            }
            if ($part === 'health' && $settings && (int) $settings->health_wage_cap_minor > 0) {
                $wage = min($wage, (int) $settings->health_wage_cap_minor);
            }
            $bpjsEmployee[$part] = $enrolled ? self::percent($wage, (int) $settings->{$part.'_employee_bps'}) : 0;
            $bpjsEmployer[$part] = $enrolled ? self::percent($wage, (int) $settings->{$part.'_employer_bps'}) : 0;
        }

        $ptkp = DB::table('ptkp_rates')->where('status', $profile->ptkp_status)
            ->where('effective_from', '<=', $from)->orderByDesc('effective_from')->first();
        if (! $ptkp || ! in_array($ptkp->ter_category, ['A', 'B', 'C'], true)) {
            throw new DomainException('Tarif PTKP/TER belum diisi dan diverifikasi akuntan, jadi payroll belum bisa dihitung.');
        }

        $taxableGross = self::add(self::add(self::add($baseSalary, $incentive), $taxableEarnings), $additionalGross);
        $bracketVersion = DB::table('payroll_ter_versions')->where('category', $ptkp->ter_category)
            ->whereNotNull('published_at')->where('effective_from', '<=', $from)->max('effective_from');
        $bracket = $bracketVersion === null ? null : DB::table('pph21_ter_brackets')
            ->where('category', $ptkp->ter_category)->where('effective_from', $bracketVersion)
            ->where('lower_gross_minor', '<=', $taxableGross)
            ->where(fn ($query) => $query->whereNull('upper_gross_minor')
                ->orWhere('upper_gross_minor', '>=', $taxableGross))
            ->orderByDesc('lower_gross_minor')->first();
        if (! $bracket) {
            throw new DomainException('Tarif TER belum diisi dan diverifikasi akuntan untuk gaji ini, jadi payroll belum bisa dihitung.');
        }
        $pph21 = self::percent($taxableGross, (int) $bracket->rate_bps);
        $grossPay = self::add(self::add(self::add($baseSalary, $incentive), $manualEarnings), $additionalGross);
        $employeeContribution = array_reduce($bpjsEmployee, fn (int $sum, int $amount): int => self::add($sum, $amount), 0);
        $employerContribution = array_reduce($bpjsEmployer, fn (int $sum, int $amount): int => self::add($sum, $amount), 0);
        $totalDeductions = self::add(self::add($manualDeductions, $employeeContribution), $pph21);
        $takeHome = $grossPay - $totalDeductions;

        return [
            'period' => $period, 'user_id' => $user->id, 'base_salary_minor' => $baseSalary,
            'rider_cups' => $cups, 'rider_incentive_minor' => $incentive,
            'rider_incentive_balance_minor' => $balanceIncentive,
            'rider_incentive_legacy_minor' => $legacyIncentive, 'components' => $components,
            'manual_earnings_minor' => $manualEarnings, 'manual_deductions_minor' => $manualDeductions,
            'gross_pay_minor' => $grossPay, 'taxable_gross_minor' => $taxableGross,
            'ptkp_status' => $profile->ptkp_status, 'ptkp_annual_minor' => (int) $ptkp->annual_amount_minor,
            'ter_category' => $ptkp->ter_category, 'ter_rate_bps' => (int) $bracket->rate_bps,
            'bpjs_employee_minor' => $bpjsEmployee, 'bpjs_employer_minor' => $bpjsEmployer,
            'bpjs_employee_total_minor' => $employeeContribution,
            'bpjs_employer_total_minor' => $employerContribution,
            'pph21_minor' => $pph21, 'take_home_minor' => $takeHome,
            'december_annual_reconciliation_required' => $start->format('m') === '12',
        ];
    }

    /** @param array<int, array<string, mixed>> $months Approved Jan-Nov snapshots keyed by month. */
    public function reconcileDecember(User $user, array $december, array $months, ?PayrollPriorWithholding $prior = null): array
    {
        $year = (int) substr($december['period'], 0, 4);
        $joined = $user->joined_at;
        $firstMonth = $joined && (int) $joined->format('Y') === $year ? (int) $joined->format('n') : 1;
        if ($joined && (int) $joined->format('Y') > $year) {
            throw new DomainException('Tanggal masuk karyawan lebih baru dari periode payroll.');
        }
        $gross = $december['taxable_gross_minor'];
        $contribution = self::add($december['bpjs_employee_minor']['jht'], $december['bpjs_employee_minor']['jp']);
        $withheld = 0;
        $manualMonths = $prior?->months ?? [];
        foreach ($manualMonths as $month) {
            if (isset($months[$month]) || ! is_int($month) || $month < 1 || $month > 12) {
                throw new DomainException('Bulan PPh manual bentrok dengan riwayat payroll.');
            }
        }
        for ($month = 1; $month <= 11; $month++) {
            if (! isset($months[$month])) {
                if ($month < $firstMonth || in_array($month, $manualMonths, true)) {
                    continue;
                }
                throw new DomainException('Riwayat payroll Jan–Nov belum lengkap untuk '.$user->name.' (bulan '.$month.').');
            }
            $snapshot = $months[$month];
            $gross = self::add($gross, (int) $snapshot['taxable_gross_minor']);
            $contribution = self::add($contribution,
                self::add((int) $snapshot['bpjs_employee_minor']['jht'], (int) $snapshot['bpjs_employee_minor']['jp']));
            $withheld = self::add($withheld, (int) $snapshot['pph21_minor']);
        }
        if ($prior) {
            $gross = self::add($gross, $prior->gross_minor);
            $contribution = self::add($contribution, $prior->jht_jp_minor);
            $withheld = self::add($withheld, $prior->amount_minor);
        }
        // A prior employer may have withheld PPh in December too. Its income joins this
        // month's annual reconciliation, but December still counts as one worked month.
        $workingMonths = count(array_unique([12, ...array_keys($months), ...$manualMonths]));
        if ($workingMonths > 12) {
            throw new DomainException('Jumlah bulan kerja payroll lebih dari satu tahun.');
        }
        $jobExpenseCap = intdiv(6000000 * $workingMonths, 12);
        $jobExpense = min(self::percent($gross, 500), $jobExpenseCap);
        $taxable = max(0, $gross - $jobExpense - $contribution - $december['ptkp_annual_minor']);
        $version = DB::table('payroll_annual_tax_versions')->where('effective_from', '<=', $year.'-12-01')
            ->orderByDesc('effective_from')->first();
        if (! $version || $version->verified_at === null) {
            throw new DomainException('Tarif Pasal 17 untuk Desember belum diverifikasi.');
        }
        $brackets = DB::table('payroll_annual_tax_brackets')->where('version_id', $version->id)
            ->orderBy('lower_taxable_minor')->get();
        $annualTax = 0;
        foreach ($brackets as $bracket) {
            $lower = (int) $bracket->lower_taxable_minor;
            $upper = $bracket->upper_taxable_minor === null ? $taxable : min($taxable, (int) $bracket->upper_taxable_minor);
            $start = $lower === 0 ? 0 : $lower - 1;
            if ($upper > $start) {
                $annualTax = self::add($annualTax, self::percent($upper - $start, (int) $bracket->rate_bps));
            }
        }
        $pph21 = $annualTax - $withheld;
        $december['pph21_minor'] = $pph21;
        $december['take_home_minor'] = $december['gross_pay_minor'] - $december['manual_deductions_minor']
            - $december['bpjs_employee_total_minor'] - $pph21;
        $december['annual_taxable_minor'] = $taxable;
        $december['annual_tax_minor'] = $annualTax;
        $december['annual_tax_version_id'] = $version->id;
        $december['prior_withheld_minor'] = $withheld;
        $december['working_months'] = $workingMonths;
        $december['december_annual_reconciliation_required'] = false;

        return $december;
    }

    private static function percent(int $amount, int $basisPoints): int
    {
        if ($basisPoints < 0 || $basisPoints > 10000) {
            throw new DomainException('Tarif payroll harus antara 0 dan 10000 basis poin ya.');
        }

        return self::add(
            self::multiply(intdiv($amount, 10000), $basisPoints),
            intdiv(($amount % 10000) * $basisPoints + 5000, 10000),
        );
    }

    private static function add(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            throw new DomainException('Nominal payroll terlalu besar. Cek lagi angkanya ya.');
        }

        return $left + $right;
    }

    private static function multiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || ($right !== 0 && $left > intdiv(PHP_INT_MAX, $right))) {
            throw new DomainException('Nominal payroll terlalu besar. Cek lagi angkanya ya.');
        }

        return $left * $right;
    }
}
