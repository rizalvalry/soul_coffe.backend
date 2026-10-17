<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Cart;
use App\Models\PayrollComponentEntry;
use App\Models\PayrollComponentType;
use App\Models\Product;
use App\Models\User;
use App\Services\PayrollCalculator;
use Database\Seeders\PayrollConfigurationSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PayrollCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PayrollConfigurationSeeder::class);
    }

    private function employee(Role $role = Role::MANAGEMENT, int $salary = 10000000, string $status = 'TK/0', bool $tk = false, bool $health = false): User
    {
        $user = User::factory()->role($role)->create();
        $user->employeeProfile()->create([
            'base_salary_minor' => $salary, 'ptkp_status' => $status,
            'bpjs_tk_enrolled' => $tk, 'bpjs_kes_enrolled' => $health, 'payroll_active' => true,
        ]);

        return $user->refresh();
    }

    private function tax(string $status = 'TK/0', string $category = 'A', int $rate = 500): void
    {
        DB::table('payroll_ter_versions')->insertOrIgnore([
            'category' => $category, 'effective_from' => '2026-01-01', 'published_at' => now(),
        ]);
        DB::table('ptkp_rates')->insert([
            'status' => $status, 'ter_category' => $category, 'annual_amount_minor' => 54000000,
            'effective_from' => '2026-01-01', 'source_note' => 'Synthetic test fixture',
        ]);
        DB::table('pph21_ter_brackets')->insert([
            'category' => $category, 'lower_gross_minor' => 0, 'upper_gross_minor' => null,
            'rate_bps' => $rate, 'effective_from' => '2026-01-01', 'source_note' => 'Synthetic test fixture',
        ]);
    }

    private function settings(): void
    {
        DB::table('payroll_settings')->insert([
            'effective_from' => '2026-01-01', 'jht_employer_bps' => 370, 'jht_employee_bps' => 200,
            'jp_employer_bps' => 200, 'jp_employee_bps' => 100,
            'jkk_employer_bps' => 24, 'jkk_employee_bps' => 0,
            'jkm_employer_bps' => 30, 'jkm_employee_bps' => 0,
            'health_employer_bps' => 400, 'health_employee_bps' => 100,
            'jp_wage_cap_minor' => 10000000, 'health_wage_cap_minor' => 12000000,
            'source_note' => 'Synthetic test fixture',
        ]);
    }

    public static function basicCases(): array
    {
        return [
            'no BPJS' => [10000000, false, false, 500, 500000, 9500000],
            'BPJS above caps' => [20000000, true, true, 500, 1000000, 18380000],
            'half-up tax rounding' => [10, false, false, 500, 1, 9],
        ];
    }

    #[DataProvider('basicCases')]
    public function test_integer_monthly_cases(int $salary, bool $tk, bool $health, int $rate, int $tax, int $takeHome): void
    {
        $this->tax(rate: $rate);
        $this->settings();
        $result = app(PayrollCalculator::class)->calculate($this->employee(salary: $salary, tk: $tk, health: $health), '2026-10');

        $this->assertSame($salary, $result['base_salary_minor']);
        $this->assertSame($tax, $result['pph21_minor']);
        $this->assertSame($takeHome, $result['take_home_minor']);
        foreach (['base_salary_minor', 'rider_cups', 'rider_incentive_minor',
            'manual_earnings_minor', 'manual_deductions_minor', 'gross_pay_minor',
            'taxable_gross_minor', 'ptkp_annual_minor', 'ter_rate_bps',
            'bpjs_employee_total_minor', 'bpjs_employer_total_minor', 'pph21_minor',
            'take_home_minor'] as $field) {
            $this->assertIsInt($result[$field], $field);
        }
        foreach ([...$result['bpjs_employee_minor'], ...$result['bpjs_employer_minor']] as $amount) {
            $this->assertIsInt($amount);
        }
        if ($tk && $health) {
            $this->assertSame(400000 + 100000 + 120000, $result['bpjs_employee_total_minor']);
            $this->assertSame(740000 + 200000 + 48000 + 60000 + 480000, $result['bpjs_employer_total_minor']);
        }
    }

    public function test_rider_incentive_excludes_void_sales_and_applies_target_bonus(): void
    {
        $this->tax();
        DB::table('rider_incentive_rules')->insert([
            'effective_from' => '2026-10-01', 'amount_per_cup_minor' => 1000,
            'target_cups' => 10, 'bonus_minor' => 5000, 'source_note' => 'Synthetic test fixture',
        ]);
        $rider = $this->employee(Role::STAFF);
        $cart = Cart::query()->create(['code' => 'TEST-012']);
        $product = Product::query()->create(['code' => 'TEST-012', 'name' => 'Cup Test', 'unit' => 'cup', 'sort_order' => 1]);
        $pack = Product::query()->create(['code' => 'PACK-012', 'name' => 'Pack Test', 'unit' => 'pack', 'sort_order' => 2]);
        $unsold = Product::query()->create(['code' => 'UNSOLD-012', 'name' => 'Unsold Test', 'unit' => 'cup', 'sort_order' => 3]);
        $this->assertTrue($unsold->update(['unit' => 'pack']));
        $this->assertSame('pack', $unsold->fresh()->unit);
        foreach ([['2026-10-10', 10, null, $product], ['2026-10-10', 100, null, $pack],
            ['2026-10-11', 50, '2026-10-11', $product], ['2026-09-30', 30, null, $product]] as [$date, $qty, $voided, $soldProduct]) {
            $saleId = DB::table('sales')->insertGetId([
                'uuid' => (string) Str::uuid(), 'operating_date' => $date,
                'cart_id' => $cart->id, 'staff_id' => $rider->id, 'occurred_at' => $date.' 10:00:00',
                'total_qty' => $qty, 'total_amount_minor' => 0, 'voided_at' => $voided,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('sale_lines')->insert([
                'sale_id' => $saleId, 'product_id' => $soldProduct->id, 'qty' => $qty,
                'unit_price_minor' => 0, 'subtotal_minor' => 0,
            ]);
        }
        try {
            $product->update(['unit' => 'pack']);
            $this->fail('A sold cup product must keep its historical unit.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('unit', $exception->errors());
        }
        $this->assertSame('cup', $product->fresh()->unit);
        $rider->update(['role' => Role::MANAGEMENT]);
        $result = app(PayrollCalculator::class)->calculate($rider->refresh(), '2026-10');

        $this->assertSame(10, $result['rider_cups']);
        $this->assertSame(15000, $result['rider_incentive_minor']);
        $this->assertSame(10015000, $result['taxable_gross_minor']);
        $this->assertSame(9514250, $result['take_home_minor']);
    }

    public function test_manual_kasbon_and_taxable_earning_affect_take_home(): void
    {
        $this->tax();
        $user = $this->employee();
        foreach ([['ADVANCE', 'DEDUCTION', false, 1000000], ['BONUS', 'EARNING', true, 500001]] as [$code, $kind, $taxable, $amount]) {
            $type = PayrollComponentType::query()->create([
                'code' => $code, 'name' => $code, 'kind' => $kind, 'taxable' => $taxable,
                'effective_from' => '2026-10-01', 'source_note' => 'Synthetic test fixture',
            ]);
            PayrollComponentEntry::query()->create([
                'user_id' => $user->id, 'component_type_id' => $type->id, 'period_start' => '2026-10-01',
                'request_key' => (string) Str::uuid(),
                'amount_minor' => $amount, 'created_by' => $user->id,
            ]);
        }
        $result = app(PayrollCalculator::class)->calculate($user, '2026-10');

        $this->assertSame(500001, $result['manual_earnings_minor']);
        $this->assertSame(1000000, $result['manual_deductions_minor']);
        $this->assertSame(10500001, $result['taxable_gross_minor']);
        $this->assertSame(525000, $result['pph21_minor']);
        $this->assertSame(8975001, $result['take_home_minor']);
        $this->assertIsInt($result['components'][0]['amount_minor']);
        $this->assertNotSame('1000000', DB::table('payroll_component_entries')->first()->amount_minor);
    }

    public function test_seeded_cash_allowances_are_included_in_taxable_gross(): void
    {
        $this->tax();
        $user = $this->employee();
        foreach (['MEAL' => 100001, 'TRANSPORT' => 200002] as $code => $amount) {
            $type = PayrollComponentType::query()->where('code', $code)->firstOrFail();
            $this->assertTrue($type->taxable);
            PayrollComponentEntry::query()->create([
                'user_id' => $user->id, 'component_type_id' => $type->id,
                'period_start' => '2026-10-01', 'request_key' => (string) Str::uuid(),
                'amount_minor' => $amount, 'created_by' => $user->id,
            ]);
        }

        $result = app(PayrollCalculator::class)->calculate($user, '2026-10');
        $this->assertSame(10300003, $result['taxable_gross_minor']);
        $this->assertSame(515000, $result['pph21_minor']);
    }

    public function test_ptkp_status_selects_ter_category_and_december_is_flagged(): void
    {
        $this->tax('TK/0', 'A', 500);
        $this->tax('K/1', 'B', 300);
        $single = app(PayrollCalculator::class)->calculate($this->employee(status: 'TK/0'), '2026-12');
        $married = app(PayrollCalculator::class)->calculate($this->employee(status: 'K/1'), '2026-12');

        $this->assertSame('A', $single['ter_category']);
        $this->assertSame('B', $married['ter_category']);
        $this->assertSame(500000, $single['pph21_minor']);
        $this->assertSame(300000, $married['pph21_minor']);
        $this->assertTrue($single['december_annual_reconciliation_required']);
    }

    public function test_missing_verified_tax_data_fails_closed(): void
    {
        $this->expectException(DomainException::class);
        app(PayrollCalculator::class)->calculate($this->employee(), '2026-10');
    }

    public function test_unpublished_partial_ter_version_does_not_replace_complete_version(): void
    {
        $this->tax(rate: 500);
        DB::table('payroll_ter_versions')->insert([
            'category' => 'A', 'effective_from' => '2026-10-01', 'published_at' => null,
        ]);
        DB::table('pph21_ter_brackets')->insert([
            'category' => 'A', 'lower_gross_minor' => 0, 'upper_gross_minor' => 100,
            'rate_bps' => 0, 'effective_from' => '2026-10-01', 'source_note' => 'Incomplete draft',
        ]);
        $result = app(PayrollCalculator::class)->calculate($this->employee(), '2026-10');
        $this->assertSame(500000, $result['pph21_minor']);
    }
}
