<?php

namespace App\Services;

use App\Models\PayrollComponentEntry;
use App\Models\User;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PayrollCalculator
{
    /**
     * Monthly TER estimate. December still needs the annual reconciliation in T-013.
     * All money is integer rupiah; every percentage rounds half up to one rupiah.
     *
     * @return array<string, mixed>
     */
    public function calculate(User $user, string $period): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period)) {
            throw new InvalidArgumentException('Period must be YYYY-MM.');
        }

        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $period.'-01');
        if (! $start || $start->format('Y-m') !== $period) {
            throw new InvalidArgumentException('Invalid payroll period.');
        }
        $from = $start->format('Y-m-d');
        $through = $start->format('Y-m-t');

        $profile = $user->employeeProfile;
        if (! $profile || ! $profile->payroll_active || $profile->base_salary_minor === null) {
            throw new DomainException('Active payroll profile and base salary are required.');
        }
        $baseSalary = $profile->base_salary_minor;
        if (! is_int($baseSalary) || $baseSalary < 0) {
            throw new DomainException('Base salary must be nonnegative integer rupiah.');
        }

        $cups = 0;
        $incentive = 0;
        // SaleService permits only STAFF to record a cart sale. The sale is the historical
        // evidence of Rider work, even if that person's role changes before payroll runs.
        $cups = (int) DB::table('sale_lines')
            ->join('sales', 'sales.id', '=', 'sale_lines.sale_id')
            ->join('products', 'products.id', '=', 'sale_lines.product_id')
            ->where('sales.staff_id', $user->id)
            ->where('products.unit', 'cup')
            ->whereNull('sales.voided_at')
            ->whereBetween('sales.operating_date', [$from, $through])
            ->sum('sale_lines.qty');

        $rule = DB::table('rider_incentive_rules')
            ->where('effective_from', '<=', $from)->orderByDesc('effective_from')->first();
        if ($rule) {
            $incentive = self::multiply($cups, (int) $rule->amount_per_cup_minor);
            if ((int) $rule->target_cups > 0 && $cups >= (int) $rule->target_cups) {
                $incentive = self::add($incentive, (int) $rule->bonus_minor);
            }
        }

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
                throw new DomainException('Invalid payroll component entry.');
            }
            if ($type->kind === 'EARNING') {
                $manualEarnings = self::add($manualEarnings, $amount);
                if ($type->taxable) {
                    $taxableEarnings = self::add($taxableEarnings, $amount);
                }
            } elseif ($type->kind === 'DEDUCTION') {
                $manualDeductions = self::add($manualDeductions, $amount);
            } else {
                throw new DomainException('Invalid payroll component kind.');
            }
            $components[] = [
                'entry_id' => $entry->id, 'name' => $type->name, 'kind' => $type->kind,
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
                throw new DomainException('BPJS settings are required for enrolled employees.');
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
            throw new DomainException('Verified PTKP and TER category are required.');
        }

        $taxableGross = self::add(self::add($baseSalary, $incentive), $taxableEarnings);
        $bracketVersion = DB::table('payroll_ter_versions')->where('category', $ptkp->ter_category)
            ->whereNotNull('published_at')->where('effective_from', '<=', $from)->max('effective_from');
        $bracket = $bracketVersion === null ? null : DB::table('pph21_ter_brackets')
            ->where('category', $ptkp->ter_category)->where('effective_from', $bracketVersion)
            ->where('lower_gross_minor', '<=', $taxableGross)
            ->where(fn ($query) => $query->whereNull('upper_gross_minor')
                ->orWhere('upper_gross_minor', '>=', $taxableGross))
            ->orderByDesc('lower_gross_minor')->first();
        if (! $bracket) {
            throw new DomainException('Verified TER bracket is required for taxable gross.');
        }
        $pph21 = self::percent($taxableGross, (int) $bracket->rate_bps);
        $grossPay = self::add(self::add($baseSalary, $incentive), $manualEarnings);
        $employeeContribution = array_reduce($bpjsEmployee, fn (int $sum, int $amount): int => self::add($sum, $amount), 0);
        $employerContribution = array_reduce($bpjsEmployer, fn (int $sum, int $amount): int => self::add($sum, $amount), 0);
        $totalDeductions = self::add(self::add($manualDeductions, $employeeContribution), $pph21);
        $takeHome = $grossPay - $totalDeductions;

        return [
            'period' => $period, 'user_id' => $user->id, 'base_salary_minor' => $baseSalary,
            'rider_cups' => $cups, 'rider_incentive_minor' => $incentive, 'components' => $components,
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

    private static function percent(int $amount, int $basisPoints): int
    {
        if ($basisPoints < 0 || $basisPoints > 10000) {
            throw new DomainException('Rate must be between 0 and 10000 basis points.');
        }

        return self::add(
            self::multiply(intdiv($amount, 10000), $basisPoints),
            intdiv(($amount % 10000) * $basisPoints + 5000, 10000),
        );
    }

    private static function add(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            throw new DomainException('Payroll amount is outside integer range.');
        }

        return $left + $right;
    }

    private static function multiply(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || ($right !== 0 && $left > intdiv(PHP_INT_MAX, $right))) {
            throw new DomainException('Payroll amount is outside integer range.');
        }

        return $left * $right;
    }
}
