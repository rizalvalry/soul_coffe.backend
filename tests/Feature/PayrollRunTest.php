<?php

namespace Tests\Feature;

use App\Enums\AttendanceCode;
use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Exports\PayrollRunExport;
use App\Filament\Pages\GeneralLedger;
use App\Filament\Pages\PayrollConfiguration;
use App\Filament\Pages\PayrollPriorWithholding;
use App\Filament\Pages\PayrollRuns;
use App\Filament\Resources\Journals\Pages\ListJournals;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CentralKitchen;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Location;
use App\Models\PayrollComponentEntry;
use App\Models\PayrollItem;
use App\Models\PayrollPriorWithholding as PayrollPriorWithholdingRecord;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\RawMaterial;
use App\Models\RiderClosing;
use App\Models\StaffAssignment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\AccountingPeriodService;
use App\Services\AttendanceService;
use App\Services\AttendanceSheetService;
use App\Services\CashTransactionService;
use App\Services\DirectSaleService;
use App\Services\JournalService;
use App\Services\PayrollCalculator;
use App\Services\PayrollRunService;
use App\Services\PurchaseOrderService;
use App\Services\Reporting\ManagementReportService;
use App\Services\RiderCommissionService;
use App\Services\StockLedgerService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\PayrollConfigurationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Mockery;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PayrollRunTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-01-01 12:00:00'));
        Storage::fake('local');
        $this->seed(AccountSeeder::class);
        $this->seed(PayrollConfigurationSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->manager = User::factory()->role(Role::MANAGEMENT)->create();
        $this->employee = User::factory()->role(Role::STAFF)->create(['joined_at' => '2026-01-01']);
        $this->employee->employeeProfile()->create([
            'base_salary_minor' => 10000000, 'ptkp_status' => 'TK/0',
            'bpjs_tk_enrolled' => false, 'bpjs_kes_enrolled' => false, 'payroll_active' => true,
        ]);
        $this->actingAs($this->manager);
        $this->rates();
    }

    public function test_view_only_prior_withholding_account_cannot_save(): void
    {
        $viewer = User::factory()->role(Role::BARISTA)->create();
        PermissionMatrix::set(Role::BARISTA, PanelModule::PAYROLL_PRIOR_WITHHOLDING, ['view']);
        $this->actingAs($viewer)->get(PayrollPriorWithholding::getUrl())->assertOk();
        Livewire::test(PayrollPriorWithholding::class)->call('save')->assertForbidden();
        $this->assertSame(0, PayrollPriorWithholdingRecord::query()->count());
    }

    private function rates(bool $verified = true): void
    {
        DB::table('payroll_settings')->insert([
            'effective_from' => '2026-01-01', 'source_note' => 'Synthetic fixture',
            'verified_by' => $verified ? $this->manager->id : null,
            'verified_at' => $verified ? now() : null,
        ]);
        DB::table('ptkp_rates')->insert([
            'status' => 'TK/0', 'ter_category' => 'A', 'annual_amount_minor' => 0,
            'effective_from' => '2026-01-01', 'source_note' => 'Synthetic fixture',
            'verified_by' => $verified ? $this->manager->id : null,
            'verified_at' => $verified ? now() : null,
        ]);
        DB::table('payroll_ter_versions')->insert([
            'category' => 'A', 'effective_from' => '2026-01-01', 'published_at' => now(),
            'source_note' => 'Synthetic fixture',
            'verified_by' => $verified ? $this->manager->id : null,
            'verified_at' => $verified ? now() : null,
        ]);
        DB::table('pph21_ter_brackets')->insert([
            'category' => 'A', 'lower_gross_minor' => 0, 'upper_gross_minor' => null,
            'rate_bps' => 500, 'effective_from' => '2026-01-01', 'source_note' => 'Synthetic fixture',
        ]);
        $versionId = DB::table('payroll_annual_tax_versions')->insertGetId([
            'effective_from' => '2026-01-01', 'source_note' => 'Synthetic fixture',
            'verified_by' => $verified ? $this->manager->id : null,
            'verified_at' => $verified ? now() : null,
        ]);
        DB::table('payroll_annual_tax_brackets')->insert([
            'version_id' => $versionId, 'lower_taxable_minor' => 0, 'upper_taxable_minor' => null,
            'rate_bps' => 500,
        ]);
    }

    private function payrollClosing(string $date, int $cups): RiderClosing
    {
        $cart = Cart::query()->firstOrCreate(['code' => 'PAY-ATTENDANCE'], ['status' => 'active']);
        $product = Product::query()->firstOrCreate(['code' => 'PAY-ATTENDANCE-CUP'],
            ['name' => 'Cup Payroll', 'unit' => 'cup', 'sort_order' => 1]);
        $closing = RiderClosing::query()->create([
            'rider_id' => $this->employee->id, 'cart_id' => $cart->id,
            'operating_date' => $date, 'total_minor' => 0, 'cogs_minor' => 0,
            'qris_minor' => 0, 'transfer_minor' => 0, 'cash_minor' => 0,
            'closed_at' => $date.' 18:00:00',
        ]);
        $closing->lines()->create([
            'product_id' => $product->id, 'qty_in' => $cups, 'qty_carry' => 0,
            'qty_remaining' => 0, 'qty_wasted' => 0, 'qty_sold' => $cups,
            'qty_sale_posted' => $cups, 'qty_waste_posted' => 0,
            'unit_price_minor' => 0, 'unit_cost_minor' => 0, 'subtotal_minor' => 0,
        ]);

        return $closing;
    }

    public function test_daily_salary_uses_eighteen_m_days_and_approved_snapshot_stays_fixed(): void
    {
        $this->employee->employeeProfile->setSalaryEffectiveFrom('2026-10-01');
        $this->employee->employeeProfile->update([
            'base_salary_minor' => 1000000, 'salary_basis' => 'harian', 'daily_rate_minor' => 38462,
        ]);
        $sheet = app(AttendanceSheetService::class);
        foreach (range(1, 18) as $day) {
            $sheet->setCode($this->employee, Carbon::parse('2026-10-'.sprintf('%02d', $day)), AttendanceCode::PRESENT, $this->manager);
        }
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        $snapshot = $run->items()->firstOrFail()->snapshot;
        $this->assertSame(692316, $snapshot['base_salary_minor']);
        $this->assertSame(38462, $snapshot['daily_rate_minor']);
        $this->assertSame(18, $snapshot['attendance_days']);
        $service->approve($run, $this->manager);
        $item = $run->fresh()->items()->firstOrFail();
        $this->assertStringContainsString('38.462 × 18 hari M', view('payroll.slip', [
            'run' => $run->fresh(), 'item' => $item, 'snapshot' => $item->snapshot,
        ])->render());
        $sheet->setCode($this->employee, Carbon::parse('2026-10-01'), AttendanceCode::ABSENT, $this->manager);
        $this->assertSame(692316, $item->fresh()->snapshot['base_salary_minor']);
        $this->assertSame(17, $sheet->monthlySheet(Carbon::parse('2026-10-01'), null, [$this->employee->id])->first()['summary']['hadir']);
    }

    public function test_monthly_salary_and_legacy_profile_keep_the_same_pay_independent_of_attendance(): void
    {
        $this->employee->employeeProfile->setSalaryEffectiveFrom('2026-10-01');
        $this->employee->employeeProfile->update(['base_salary_minor' => 500000]);
        $service = app(PayrollRunService::class);
        $before = $service->create('2026-10', $this->manager)->items()->firstOrFail()->snapshot;
        app(AttendanceSheetService::class)->setCode($this->employee, Carbon::parse('2026-10-05'), AttendanceCode::ABSENT);
        $after = $service->preview($this->employee->fresh(), '2026-10', actor: $this->manager);
        $this->assertSame('bulanan', $after['salary_basis']);
        $this->assertSame(500000, $before['base_salary_minor']);
        $this->assertSame(500000, $before['gross_pay_minor']);
        $this->assertSame(25000, $before['pph21_minor']);
        $this->assertSame(475000, $before['take_home_minor']);
        $this->assertSame($before['base_salary_minor'], $after['base_salary_minor']);
        $this->assertSame($before['gross_pay_minor'], $after['gross_pay_minor']);
        $this->assertSame($before['pph21_minor'], $after['pph21_minor']);
        $this->assertSame($before['take_home_minor'], $after['take_home_minor']);
    }

    public function test_migration_backfill_keeps_preexisting_monthly_payroll_amount(): void
    {
        $legacy = User::factory()->role(Role::BARISTA)->create(['joined_at' => '2026-01-01']);
        $ciphertext = Crypt::encryptString('500000');
        $profileId = DB::table('employee_profiles')->insertGetId([
            'user_id' => $legacy->id, 'base_salary_minor' => $ciphertext,
            'salary_basis' => 'bulanan', 'payroll_active' => true,
            'ptkp_status' => 'TK/0', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_10_24_000083_create_employee_salary_versions.php');
        $migration->backfillProfiles();

        $version = $legacy->employeeProfile->salaryVersionFor('2026-10-01');
        $snapshot = app(PayrollCalculator::class)->calculate($legacy->fresh(), '2026-10');
        $this->assertSame('2026-10-01', $version->effective_from->format('Y-m-d'));
        $this->assertSame($ciphertext, DB::table('employee_salary_versions')
            ->where('employee_profile_id', $profileId)->value('base_salary_minor'));
        $this->assertSame(500000, $snapshot['base_salary_minor']);
        $this->assertSame(475000, $snapshot['take_home_minor']);
    }

    public function test_late_closing_suggestion_is_overridden_by_finance_and_below_target_only(): void
    {
        $this->employee->employeeProfile->setSalaryEffectiveFrom('2026-10-01');
        $this->employee->employeeProfile->update(['salary_basis' => 'harian', 'daily_rate_minor' => 38462]);
        foreach ([10 => 30, 11 => 45] as $day => $cups) {
            $date = '2026-10-'.$day;
            Attendance::query()->create([
                'operating_date' => $date, 'user_id' => $this->employee->id,
                'role' => Role::STAFF, 'clocked_in_at' => Carbon::parse($date.' 09:15'),
            ]);
            $this->payrollClosing($date, $cups);
        }
        $sheet = app(AttendanceSheetService::class);
        $row = $sheet->monthlySheet(Carbon::parse('2026-10-01'), null, [$this->employee->id])->first();
        $this->assertSame('system', $row['cells'][10]['source']);
        $this->assertSame(AttendanceCode::LATE, $row['cells'][10]['code']);
        $this->assertSame(AttendanceCode::PRESENT, $row['cells'][11]['code']);
        $this->assertSame(1, $row['summary']['hadir']);
        $this->assertSame(38462, app(PayrollCalculator::class)->calculate($this->employee->fresh(), '2026-10')['base_salary_minor']);
        $sheet->setCode($this->employee, Carbon::parse('2026-10-10'), AttendanceCode::PRESENT, $this->manager);
        $row = $sheet->monthlySheet(Carbon::parse('2026-10-01'), null, [$this->employee->id])->first();
        $this->assertSame('manual', $row['cells'][10]['source']);
        $this->assertSame(2, $row['summary']['hadir']);
        $this->assertSame(76924, app(PayrollCalculator::class)->calculate($this->employee->fresh(), '2026-10')['base_salary_minor']);
    }

    public function test_automatic_t_uses_seconds_after_nine_wib_and_exposes_inactive_payroll_roster(): void
    {
        foreach (['2026-10-10 09:00:00', '2026-10-11 09:00:01'] as $stamp) {
            $date = substr($stamp, 0, 10);
            Attendance::query()->create([
                'operating_date' => $date, 'user_id' => $this->employee->id,
                'role' => Role::STAFF, 'clocked_in_at' => Carbon::parse($stamp),
            ]);
            $this->payrollClosing($date, 30);
        }
        $this->employee->update(['is_active' => false]);
        $row = app(AttendanceSheetService::class)->monthlySheet(Carbon::parse('2026-10-01'), Role::STAFF)
            ->firstWhere('user.id', $this->employee->id);
        $this->assertNotNull($row);
        $this->assertSame(AttendanceCode::PRESENT, $row['cells'][10]['code']);
        $this->assertSame(AttendanceCode::LATE, $row['cells'][11]['code']);
        $this->assertSame(1, $row['summary']['hadir']);
    }

    public function test_role_change_keeps_historical_t_and_monthly_bonus(): void
    {
        $this->employee->employeeProfile->setSalaryEffectiveFrom('2026-10-01');
        $this->employee->employeeProfile->update(['salary_basis' => 'harian', 'daily_rate_minor' => 38462]);
        DB::table('payroll_monthly_bonus_rules')->insert(['min_cups' => 20, 'amount_minor' => 200000]);
        Attendance::query()->create([
            'operating_date' => '2026-10-10', 'user_id' => $this->employee->id,
            'role' => Role::STAFF, 'clocked_in_at' => Carbon::parse('2026-10-10 09:15'),
        ]);
        $this->payrollClosing('2026-10-10', 30);

        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $this->actingAs($super);
        $this->employee->update(['role' => Role::BARISTA]);
        $this->actingAs($this->manager);
        $row = app(AttendanceSheetService::class)
            ->monthlySheet(Carbon::parse('2026-10-01'), null, [$this->employee->id])->first();
        $this->assertSame(AttendanceCode::LATE, $row['cells'][10]['code']);
        $this->assertSame('system', $row['cells'][10]['source']);
        $this->assertSame(0, $row['summary']['hadir']);
        $snapshot = app(PayrollCalculator::class)->calculate($this->employee->fresh(), '2026-10');
        $this->assertSame(0, $snapshot['base_salary_minor']);
        $this->assertSame(200000, $snapshot['monthly_bonus_minor']);
    }

    public function test_runner_role_change_requires_monthly_salary_basis(): void
    {
        $this->employee->employeeProfile->setSalaryEffectiveFrom('2026-10-01');
        $this->employee->employeeProfile->update(['salary_basis' => 'harian', 'daily_rate_minor' => 38462]);
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $this->actingAs($super);

        try {
            $this->employee->update(['role' => Role::RIDER]);
            $this->fail('Akun dengan gaji harian tidak boleh diubah jadi Runner.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('role', $exception->errors());
        }

        $this->employee->employeeProfile->setSalaryEffectiveFrom('2027-02-01');
        $this->employee->employeeProfile->update(['salary_basis' => 'bulanan']);
        $this->employee->refresh()->update(['role' => Role::RIDER]);
        $this->assertSame(Role::RIDER, $this->employee->fresh()->role);
        $this->assertSame('harian', app(PayrollCalculator::class)->calculate($this->employee->fresh(), '2026-10')['salary_basis']);
    }

    public function test_salary_version_keeps_draft_october_daily_when_november_becomes_monthly(): void
    {
        $profile = $this->employee->employeeProfile;
        $profile->setSalaryEffectiveFrom('2026-10-01');
        $profile->update(['base_salary_minor' => 1000000, 'salary_basis' => 'harian', 'daily_rate_minor' => 38462]);
        app(AttendanceSheetService::class)->setCode(
            $this->employee, Carbon::parse('2026-10-10'), AttendanceCode::PRESENT, $this->manager
        );

        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        $this->assertSame(38462, $run->items()->firstOrFail()->snapshot['base_salary_minor']);

        $profile->setSalaryEffectiveFrom('2026-11-01');
        $profile->update(['base_salary_minor' => 500000, 'salary_basis' => 'bulanan']);
        $service->approve($run, $this->manager);
        $october = $run->fresh()->items()->firstOrFail()->snapshot;
        $november = $service->preview($this->employee->fresh(), '2026-11', actor: $this->manager);

        $this->assertSame('harian', $october['salary_basis']);
        $this->assertSame('2026-10-01', $october['salary_effective_from']);
        $this->assertSame(38462, $october['base_salary_minor']);
        $this->assertSame('bulanan', $november['salary_basis']);
        $this->assertSame('2026-11-01', $november['salary_effective_from']);
        $this->assertSame(500000, $november['base_salary_minor']);
    }

    public function test_no_attendance_in_october_does_not_take_november_monthly_salary(): void
    {
        $profile = $this->employee->employeeProfile;
        $profile->setSalaryEffectiveFrom('2026-10-01');
        $profile->update(['base_salary_minor' => 1000000, 'salary_basis' => 'harian', 'daily_rate_minor' => 38462]);
        $profile->setSalaryEffectiveFrom('2026-11-01');
        $profile->update(['base_salary_minor' => 500000, 'salary_basis' => 'bulanan']);

        $october = app(PayrollCalculator::class)->calculate($this->employee->fresh(), '2026-10');
        $this->assertSame('harian', $october['salary_basis']);
        $this->assertSame(0, $october['attendance_days']);
        $this->assertSame(0, $october['base_salary_minor']);
    }

    public function test_monthly_bonus_uses_highest_closing_cup_tier_and_appears_on_slip(): void
    {
        DB::table('payroll_monthly_bonus_rules')->insert([
            ['min_cups' => 1000, 'amount_minor' => 200000],
            ['min_cups' => 1500, 'amount_minor' => 300000],
        ]);
        $this->payrollClosing('2026-10-10', 1200);
        $snapshot = app(PayrollCalculator::class)->calculate($this->employee->fresh(), '2026-10');
        $this->assertSame(1200, $snapshot['monthly_bonus_cups']);
        $this->assertSame(1000, $snapshot['monthly_bonus_min_cups']);
        $this->assertSame(200000, $snapshot['monthly_bonus_minor']);
        $this->assertSame(10200000, $snapshot['gross_pay_minor']);
        $run = app(PayrollRunService::class)->create('2026-10', $this->manager);
        $item = $run->items()->firstOrFail();
        $this->assertStringContainsString('Bonus bulanan (1200 cup; target 1000 cup)', view('payroll.slip', [
            'run' => $run, 'item' => $item, 'snapshot' => $item->snapshot,
        ])->render());
    }

    public function test_selected_payroll_run_uses_filament_table_with_integer_rupiah(): void
    {
        $run = PayrollRun::query()->create([
            'period_start' => '2026-10-01', 'status' => 'DRAFT', 'created_by' => $this->manager->id,
        ]);
        PayrollItem::query()->create([
            'run_id' => $run->id, 'user_id' => $this->employee->id,
            'snapshot' => [
                'gross_pay_minor' => 10000000, 'bpjs_employee_total_minor' => 0,
                'pph21_minor' => 250000, 'take_home_minor' => 9750000,
                'components' => [['name' => 'Gaji pokok', 'amount_minor' => 10000000]],
            ],
        ]);

        Livewire::test(PayrollRuns::class)->call('selectRun', $run->id)
            ->assertSee($this->employee->name)
            ->assertSee('Rp 10.000.000')->assertSee('Rp 250.000')->assertSee('Rp 9.750.000');
    }

    public function test_run_approval_payment_slips_and_journals_are_idempotent_and_private(): void
    {
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        $this->assertSame('DRAFT', $run->status);
        $this->assertSame(1, $run->items()->count());
        $this->assertSame($run->id, $service->create('2026-10', $this->manager)->id);
        $service->approve($run, $this->manager);
        $service->approve($run, $this->manager);
        $run->refresh();
        $this->assertSame('APPROVED', $run->status);
        $item = $run->items()->firstOrFail();
        Storage::disk('local')->assertExists($item->slip_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($item->slip_path));
        Storage::disk('local')->delete($item->slip_path);
        $service->approve($run, $this->manager);
        Storage::disk('local')->assertExists($item->slip_path);
        $this->assertSame(1, DB::table('journal_entries')->where('purpose', 'PAYROLL_ACCRUAL')->count());
        $this->get(route('admin.payroll-slips.download', $item))->assertSuccessful();
        $this->getJson('/api/v1/payroll/my-slips')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->employee);
        $this->getJson('/api/v1/payroll/my-slips')->assertOk()->assertJsonCount(1, 'data');
        $this->get(route('payroll.my-slips.download', $item))->assertSuccessful();
        $this->actingAs(User::factory()->role(Role::STAFF)->create());
        $this->get(route('payroll.my-slips.download', $item))->assertForbidden();
        $this->get(route('admin.payroll-slips.download', $item))->assertForbidden();
        $this->actingAs($this->manager);
        $this->expectException(ValidationException::class);
        $service->recalculate($run, $this->manager);
    }

    public function test_daily_commission_sums_into_payroll_slip_and_balanced_expense_journal(): void
    {
        app(RiderCommissionService::class)->save([$this->employee->id], '2026-10-01', [
            ['min_cups' => 100, 'amount_per_cup_minor' => 1000],
            ['min_cups' => 200, 'amount_per_cup_minor' => 1500],
        ], $this->manager, (string) Str::uuid());
        $cart = Cart::query()->create(['code' => 'PAY-COMMISSION', 'status' => 'active']);
        $product = Product::query()->create(['code' => 'PAY-CUP', 'name' => 'Cup Payroll', 'unit' => 'cup', 'sort_order' => 1]);
        foreach (['2026-10-10' => 100, '2026-10-11' => 200] as $date => $cups) {
            $closing = RiderClosing::query()->create([
                'rider_id' => $this->employee->id, 'cart_id' => $cart->id,
                'operating_date' => $date, 'total_minor' => 0, 'cogs_minor' => 0,
                'qris_minor' => 0, 'transfer_minor' => 0, 'cash_minor' => 0,
                'closed_at' => $date.' 18:00:00',
            ]);
            $closing->lines()->create([
                'product_id' => $product->id, 'qty_in' => $cups, 'qty_carry' => 0,
                'qty_remaining' => 0, 'qty_wasted' => 0, 'qty_sold' => $cups,
                'qty_sale_posted' => $cups, 'qty_waste_posted' => 0,
                'unit_price_minor' => 0, 'unit_cost_minor' => 0, 'subtotal_minor' => 0,
            ]);
            app(RiderCommissionService::class)->lockForSettlement($cart->id, $date);
        }
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        $service->approve($run, $this->manager);
        $item = $run->fresh()->items()->firstOrFail();
        $snapshot = $item->snapshot;
        $this->assertSame(300, $snapshot['rider_cups']);
        $this->assertSame(400000, $snapshot['rider_incentive_minor']);
        $this->assertSame(10400000, $snapshot['gross_pay_minor']);
        $this->assertStringContainsString('Komisi target harian', view('payroll.slip', [
            'run' => $run->fresh(), 'item' => $item, 'snapshot' => $snapshot,
        ])->render());
        $this->assertSame('Komisi target harian', (new PayrollRunExport($run))->headings()[3]);
        Storage::disk('local')->assertExists($item->slip_path);
        $journalId = DB::table('journal_entries')->where('purpose', 'PAYROLL_ACCRUAL')->value('id');
        $lines = DB::table('journal_lines')->where('entry_id', $journalId);
        $this->assertSame((int) $lines->sum('debit_minor'), (int) $lines->sum('credit_minor'));
        $expenseId = DB::table('accounts')->where('code', '6-1200')->value('id');
        $this->assertSame(10000000, (int) DB::table('journal_lines')->where('entry_id', $journalId)
            ->where('account_id', $expenseId)->sum('debit_minor'));
        $liabilityId = DB::table('accounts')->where('code', '2-1800')->value('id');
        $this->assertSame(400000, (int) DB::table('journal_lines')->where('entry_id', $journalId)
            ->where('account_id', $liabilityId)->sum('debit_minor'));
    }

    public function test_payroll_approval_waits_for_closing_commission_lock(): void
    {
        $cart = Cart::query()->create(['code' => 'PAY-OPEN', 'status' => 'active']);
        $product = Product::query()->create(['code' => 'PAY-OPEN-CUP', 'name' => 'Cup Open', 'unit' => 'cup', 'sort_order' => 1]);
        $closing = RiderClosing::query()->create([
            'rider_id' => $this->employee->id, 'cart_id' => $cart->id,
            'operating_date' => '2026-10-12', 'total_minor' => 0, 'cogs_minor' => 0,
            'qris_minor' => 0, 'transfer_minor' => 0, 'cash_minor' => 0,
            'closed_at' => '2026-10-12 18:00:00',
        ]);
        $closing->lines()->create([
            'product_id' => $product->id, 'qty_in' => 1, 'qty_carry' => 0,
            'qty_remaining' => 0, 'qty_wasted' => 0, 'qty_sold' => 1,
            'qty_sale_posted' => 1, 'qty_waste_posted' => 0,
            'unit_price_minor' => 0, 'unit_cost_minor' => 0, 'subtotal_minor' => 0,
        ]);
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        try {
            $service->approve($run, $this->manager);
            $this->fail('Payroll approval must wait for setoran approval.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('run', $exception->errors());
        }
        $this->assertSame('DRAFT', $run->fresh()->status);
        $this->assertSame(0, DB::table('journal_entries')->where('purpose', 'PAYROLL_ACCRUAL')->count());
        app(RiderCommissionService::class)->lockForSettlement($cart->id, '2026-10-12');
        $service->approve($run, $this->manager);
        $this->assertSame('APPROVED', $run->fresh()->status);
    }

    public function test_current_month_payroll_cannot_approve_before_later_closings_exist(): void
    {
        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        $this->assertSame(0, $run->items()->firstOrFail()->snapshot['rider_incentive_minor']);
        try {
            $service->approve($run, $this->manager);
            $this->fail('An unfinished month must not lock payroll.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('run', $exception->errors());
        }
        $this->assertSame('DRAFT', $run->fresh()->status);
        $this->assertSame(0, DB::table('journal_entries')->where('purpose', 'PAYROLL_ACCRUAL')->count());
    }

    public function test_payment_journal_balances_and_closed_period_rejects_posting(): void
    {
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        AccountingPeriod::query()->create(['year' => 2026, 'month' => 10, 'status' => 'CLOSED']);
        try {
            $service->approve($run, $this->manager);
            $this->fail('Closed period must reject payroll accrual.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entry_date', $exception->errors());
        }
        $this->assertSame('DRAFT', $run->fresh()->status);
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'payroll_run')->count());
        AccountingPeriod::query()->where('year', 2026)->where('month', 10)->update(['status' => 'OPEN']);
        $service->approve($run, $this->manager);
        $bankId = DB::table('accounts')->where('code', '1-1300')->value('id');
        $service->markPaid($run, $this->manager, '2026-11-01', $bankId);
        $service->markPaid($run, $this->manager, '2026-11-01', $bankId);
        $this->assertSame('PAID', $run->fresh()->status);
        $this->assertSame(2, DB::table('journal_entries')->where('source_type', 'payroll_run')->count());
        foreach (DB::table('journal_entries')->where('source_type', 'payroll_run')->get() as $journal) {
            $lines = DB::table('journal_lines')->where('entry_id', $journal->id);
            $this->assertSame((int) $lines->sum('debit_minor'), (int) $lines->sum('credit_minor'));
        }
        $this->assertSame(1, DB::table('audit_log')->where('action', 'payroll_run_approved')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'payroll_run_paid')->count());
    }

    public function test_unverified_rates_and_missing_deduction_account_fail_with_clear_validation(): void
    {
        DB::table('payroll_settings')->update(['verified_at' => null, 'verified_by' => null]);
        try {
            app(PayrollRunService::class)->create('2026-10', $this->manager);
            $this->fail('Unverified BPJS must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('belum diverifikasi', $exception->errors()['period'][0]);
        }
        DB::table('payroll_settings')->update(['verified_at' => now(), 'verified_by' => $this->manager->id]);
        $typeId = DB::table('payroll_component_types')->where('code', 'OTHER_DEDUCTION')->value('id');
        DB::table('payroll_component_types')->where('id', $typeId)->update(['account_id' => null]);
        PayrollComponentEntry::query()->create([
            'user_id' => $this->employee->id, 'component_type_id' => $typeId,
            'period_start' => '2026-10-01', 'request_key' => (string) Str::uuid(),
            'amount_minor' => 100000, 'created_by' => $this->manager->id,
        ]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Akun potongan');
        app(PayrollRunService::class)->create('2026-10', $this->manager);
    }

    public function test_manual_deduction_posts_to_configured_account_and_component_change_is_audited(): void
    {
        $typeId = DB::table('payroll_component_types')->where('code', 'OTHER_DEDUCTION')->value('id');
        $revenueId = DB::table('accounts')->where('code', '4-1300')->value('id');
        $this->assertSame($revenueId, DB::table('payroll_component_types')->where('id', $typeId)->value('account_id'));
        $entry = PayrollComponentEntry::query()->create([
            'user_id' => $this->employee->id, 'component_type_id' => $typeId,
            'period_start' => '2026-10-01', 'request_key' => (string) Str::uuid(),
            'amount_minor' => 100000, 'created_by' => $this->manager->id,
        ]);
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        Livewire::test(PayrollConfiguration::class)->call('updateEntry', $entry->id, 200000, 'Koreksi denda')->assertHasNoErrors();
        $service->recalculate($run, $this->manager);
        $this->assertSame(200000, $run->fresh()->items()->firstOrFail()->snapshot['manual_deductions_minor']);
        $service->approve($run, $this->manager);
        $journalId = DB::table('journal_entries')->where('source_type', 'payroll_run')->value('id');
        $this->assertSame(200000, (int) DB::table('journal_lines')->where('entry_id', $journalId)
            ->where('account_id', $revenueId)->sum('credit_minor'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'payroll_component_entry_updated')->count());
        $audit = AuditLog::query()->where('action', 'payroll_component_entry_updated')->firstOrFail();
        $this->assertSame(100000, $audit->displayChanges('before')['amount_minor']);
        $this->assertSame(200000, $audit->displayChanges('after')['amount_minor']);
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());
        $this->assertSame(200000, $audit->displayChanges('after')['amount_minor']);
        $this->actingAs($this->manager);
        Livewire::test(PayrollConfiguration::class)->call('updateEntry', $entry->id, 300000, 'Terlambat')
            ->assertHasErrors(['entry.period_start']);
    }

    public function test_management_can_verify_new_effective_rate_before_run(): void
    {
        DB::table('payroll_settings')->insert([
            'effective_from' => '2026-10-01', 'source_note' => 'Synthetic new rate',
        ]);
        try {
            app(PayrollRunService::class)->create('2026-10', $this->manager);
            $this->fail('The latest unverified rate must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('BPJS', $exception->errors()['period'][0]);
        }
        $id = DB::table('payroll_settings')->where('effective_from', '2026-10-01')->value('id');
        Livewire::test(PayrollConfiguration::class)->set('verificationNote', 'Dicek terhadap sumber regulasi')
            ->call('verifyVersion', 'payroll_settings', $id)->assertHasNoErrors();
        $this->assertSame($this->manager->id, DB::table('payroll_settings')->where('id', $id)->value('verified_by'));
        $this->assertSame('DRAFT', app(PayrollRunService::class)->create('2026-10', $this->manager)->status);
    }

    public function test_future_joiner_is_excluded_from_earlier_period(): void
    {
        $future = User::factory()->role(Role::STAFF)->create(['joined_at' => '2026-12-01']);
        $future->employeeProfile()->create([
            'base_salary_minor' => 10000000, 'ptkp_status' => 'TK/0', 'payroll_active' => true,
        ]);
        $run = app(PayrollRunService::class)->create('2026-10', $this->manager);
        $this->assertSame([$this->employee->id], $run->items()->pluck('user_id')->all());
    }

    public function test_payment_in_closed_period_writes_no_payment_journal(): void
    {
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        $service->approve($run, $this->manager);
        AccountingPeriod::query()->create(['year' => 2026, 'month' => 11, 'status' => 'CLOSED']);
        try {
            $service->markPaid($run, $this->manager, '2026-11-01', DB::table('accounts')->where('code', '1-1300')->value('id'));
            $this->fail('Closed payment period must fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entry_date', $exception->errors());
        }
        $this->assertSame('APPROVED', $run->fresh()->status);
        $this->assertSame(0, DB::table('journal_entries')->where('purpose', 'PAYROLL_PAYMENT')->count());
    }

    public function test_failed_pdf_write_is_recoverable_without_blocking_payment(): void
    {
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        $filesystem = Storage::getFacadeRoot();
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->andReturn(false);
        $disk->shouldReceive('put')->andReturn(false);
        $disk->shouldReceive('delete')->andReturn(true);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);
        try {
            $service->approve($run, $this->manager);
            $this->fail('A failed PDF write must be reported.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('slip', $exception->errors());
        } finally {
            Storage::swap($filesystem);
        }
        $this->assertSame('APPROVED', $run->fresh()->status);
        $this->assertNull($run->items()->firstOrFail()->slip_path);
        $this->assertSame(1, DB::table('journal_entries')->where('purpose', 'PAYROLL_ACCRUAL')->count());
        $service->markPaid($run, $this->manager, '2026-11-01', DB::table('accounts')->where('code', '1-1300')->value('id'));
        $this->assertSame('PAID', $run->fresh()->status);
        $service->approve($run, $this->manager);
        $item = $run->fresh()->items()->firstOrFail();
        Storage::disk('local')->assertExists($item->slip_path);
        $this->assertSame(1, DB::table('journal_entries')->where('purpose', 'PAYROLL_ACCRUAL')->count());
    }

    public function test_corrupt_pdf_is_regenerated_on_retry(): void
    {
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        $service->approve($run, $this->manager);
        $item = $run->fresh()->items()->firstOrFail();
        Storage::disk('local')->put($item->slip_path, '%PDF- truncated');
        $service->markPaid($run, $this->manager, '2026-11-01', DB::table('accounts')->where('code', '1-1300')->value('id'));
        $service->approve($run, $this->manager);
        $this->assertStringEndsWith('%%EOF', rtrim(Storage::disk('local')->get($item->slip_path)));
    }

    public function test_approval_pdf_finishing_after_cancellation_cannot_restore_old_slip_path(): void
    {
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        $realFacade = Storage::getFacadeRoot();
        $realDisk = Storage::disk('local');
        $proxy = new class($realDisk, $service, $run, $this->manager)
        {
            private bool $triggered = false;

            public function __construct(private $disk, private PayrollRunService $service,
                private PayrollRun $run, private User $actor) {}

            public function exists(string $path): bool
            {
                return $this->disk->exists($path);
            }

            public function get(string $path): string
            {
                return $this->disk->get($path);
            }

            public function put(string $path, string $contents): bool
            {
                if (! $this->triggered && ! str_contains($path, '-cancelled')) {
                    $this->triggered = true;
                    $accrualId = app(JournalService::class)->latestPayrollAccrual($this->run)->id;
                    $this->service->cancelRun($this->run, $this->actor, 'Koreksi saat PDF dibuat', $accrualId);
                }

                return $this->disk->put($path, $contents);
            }

            public function move(string $from, string $to): bool
            {
                return $this->disk->move($from, $to);
            }

            public function delete(string $path): bool
            {
                return $this->disk->delete($path);
            }
        };
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        try {
            $service->approve($run, $this->manager);
        } finally {
            Storage::swap($realFacade);
        }
        $item = $run->fresh()->items()->firstOrFail();
        $this->assertSame('CANCELLED', $run->fresh()->status);
        $this->assertStringEndsWith('-cancelled.pdf', $item->slip_path);
        Storage::disk('local')->assertExists($item->slip_path);
        $this->get(route('admin.payroll-slips.download', $item))->assertSuccessful();
    }

    public function test_existing_user_id_slip_path_remains_downloadable_until_regenerated(): void
    {
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        $service->approve($run, $this->manager);
        $item = $run->items()->firstOrFail();
        $legacyPath = 'payroll-slips/'.$run->id.'/'.$item->user_id.'.pdf';
        Storage::disk('local')->put($legacyPath, Storage::disk('local')->get($item->slip_path));
        $item->update(['slip_path' => $legacyPath]);
        $this->get(route('admin.payroll-slips.download', $item))->assertSuccessful();
        $this->actingAs($this->employee);
        $this->get(route('payroll.my-slips.download', $item))->assertSuccessful();
        $this->actingAs($this->manager);
        $service->retrySlips($run, $this->manager);
        $this->assertStringContainsString('/item-', $item->fresh()->slip_path);
    }

    public function test_excel_name_is_text_and_cms_export_requires_management(): void
    {
        PermissionMatrix::set(Role::FINANCE, PanelModule::PAYROLL, []);
        $this->employee->update(['name' => '=1+1']);
        $run = app(PayrollRunService::class)->create('2026-10', $this->manager);
        $bytes = Excel::raw(new PayrollRunExport($run), ExcelFormat::XLSX);
        $file = tmpfile();
        fwrite($file, $bytes);
        $sheet = IOFactory::load(stream_get_meta_data($file)['uri'])->getActiveSheet();
        $this->assertSame('=1+1', $sheet->getCell('B2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('B2')->getDataType());
        fclose($file);
        $this->get(PayrollRuns::getUrl())->assertSuccessful();
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        $this->get(PayrollRuns::getUrl())->assertForbidden();
    }

    public function test_december_uses_verified_annual_brackets_and_prior_withholding(): void
    {
        $this->employee->update(['joined_at' => '2026-10-01']);
        foreach ([10, 11] as $month) {
            $period = '2026-'.sprintf('%02d', $month);
            $run = PayrollRun::query()->create([
                'period_start' => $period.'-01', 'status' => 'APPROVED', 'created_by' => $this->manager->id,
            ]);
            $run->items()->create([
                'user_id' => $this->employee->id,
                'snapshot' => app(PayrollCalculator::class)->calculate($this->employee->fresh(), $period),
            ]);
        }
        $preview = app(PayrollRunService::class)->preview($this->employee->fresh(), '2026-12');
        $run = app(PayrollRunService::class)->create('2026-12', $this->manager);
        $s = $run->items()->firstOrFail()->snapshot;
        $this->assertSame($s['pph21_minor'], $preview['pph21_minor']);
        $this->assertSame($s['take_home_minor'], $preview['take_home_minor']);
        $this->assertFalse($preview['december_annual_reconciliation_required']);
        $this->assertSame(3, $s['working_months']);
        $this->assertSame(28500000, $s['annual_taxable_minor']);
        $this->assertSame(1425000, $s['annual_tax_minor']);
        $this->assertSame(425000, $s['pph21_minor']);
        $this->assertFalse($s['december_annual_reconciliation_required']);
    }

    public function test_preview_rejects_present_but_unverified_rates_and_matches_run(): void
    {
        $service = app(PayrollRunService::class);
        $preview = $service->preview($this->employee, '2026-10');
        $run = $service->create('2026-10', $this->manager);
        $this->assertSame($run->items()->firstOrFail()->snapshot['take_home_minor'], $preview['take_home_minor']);
        $this->assertSame(0, DB::table('journal_entries')->count());

        DB::table('payroll_settings')->update(['verified_at' => null]);
        Livewire::test(PayrollConfiguration::class)
            ->set('preview.user_id', $this->employee->id)
            ->set('preview.period', '2026-10')
            ->call('previewPayroll')
            ->assertSee('Tarif BPJS periode ini belum diverifikasi')
            ->assertDontSee('Dibawa pulang');
        DB::table('payroll_settings')->update(['verified_at' => now()]);

        DB::table('ptkp_rates')->update(['verified_at' => null]);
        try {
            $service->preview($this->employee, '2026-10');
            $this->fail('Preview accepted unverified PTKP.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('PTKP', $exception->errors()['period'][0]);
        }
        DB::table('ptkp_rates')->update(['verified_at' => now()]);

        DB::table('payroll_ter_versions')->update(['verified_at' => null]);
        try {
            $service->preview($this->employee, '2026-10');
            $this->fail('Preview accepted unverified TER.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('TER', $exception->errors()['period'][0]);
        }
    }

    public function test_payroll_journals_cannot_be_reversed_while_run_stays_approved_or_paid(): void
    {
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        $service->approve($run, $this->manager);
        $accrual = JournalEntry::query()->where('purpose', 'PAYROLL_ACCRUAL')->firstOrFail();
        Livewire::test(ListJournals::class)->assertTableActionHidden('reverse', $accrual);
        try {
            app(JournalService::class)->reverse($accrual, 'Koreksi');
            $this->fail('Approved payroll accrual was reversed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('journal', $exception->errors());
        }

        $paymentAccountId = DB::table('accounts')->where('code', '1-1100')->value('id');
        $service->markPaid($run, $this->manager, '2026-10-31', $paymentAccountId);
        $payment = JournalEntry::query()->where('purpose', 'PAYROLL_PAYMENT')->firstOrFail();
        Livewire::test(ListJournals::class)->assertTableActionHidden('reverse', $payment);
        try {
            app(JournalService::class)->reverse($payment, 'Koreksi');
            $this->fail('Paid payroll payment was reversed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('journal', $exception->errors());
        }
        $this->assertSame('PAID', $run->fresh()->status);
        $this->assertSame(2, JournalEntry::query()->count());
    }

    public function test_cancel_payment_reverses_ledger_and_allows_corrected_payment_idempotently(): void
    {
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        $service->approve($run, $this->manager);
        $cashId = (int) DB::table('accounts')->where('code', '1-1100')->value('id');
        $bankId = (int) DB::table('accounts')->where('code', '1-1300')->value('id');
        $service->markPaid($run, $this->manager, '2026-10-31', $cashId);
        $first = app(JournalService::class)->latestPayrollPayment($run);
        $this->assertSame('PAYROLL_PAYMENT', $first->purpose);

        $service->cancelPayment($run, $this->manager, 'Akun bayar keliru', $first->id);
        $service->cancelPayment($run, $this->manager, 'Akun bayar keliru', $first->id);
        $this->assertSame('APPROVED', $run->fresh()->status);
        $this->assertNull($run->fresh()->paid_at);
        $this->assertNull($run->fresh()->payment_account_id);
        $this->assertSame($first->id, $first->fresh()->reversal->reverses_entry_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'payroll_payment_cancelled')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'journal.reversed')->count());
        $paymentAudit = AuditLog::query()->where('action', 'payroll_payment_cancelled')->firstOrFail();
        $this->assertArrayNotHasKey('reason', $paymentAudit->after_json);
        $this->assertSame('Akun bayar keliru', $paymentAudit->displayChanges('after')['reason']);

        try {
            $service->markPaid($run, $this->manager, '2026-10-31', $cashId);
            $this->fail('Delayed retry of old payment was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('kedaluwarsa', $exception->errors()['payment'][0]);
        }

        $service->markPaid($run, $this->manager, '2026-11-01', $bankId, $first->id);
        $service->markPaid($run, $this->manager, '2026-11-01', $bankId, $first->id);
        $second = app(JournalService::class)->latestPayrollPayment($run);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('PAYROLL_PAYMENT', $second->purpose);
        $this->assertSame('payroll_run_payment_retry', $second->source_type);
        $this->assertSame($first->fresh()->reversal->id, $second->source_id);
        $this->assertSame('PAID', $run->fresh()->status);
        $this->assertSame($bankId, $run->fresh()->payment_account_id);
        $this->assertSame(2, JournalEntry::query()->where('purpose', 'PAYROLL_PAYMENT')->count());
        try {
            $service->cancelPayment($run, $this->manager, 'Delayed request', $first->id);
            $this->fail('Delayed cancellation reversed the corrected payment.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('sudah berubah', $exception->errors()['payment'][0]);
        }
        Livewire::test(ListJournals::class)->assertTableActionHidden('reverse', $second);
        try {
            app(JournalService::class)->reverse($second, 'Lewat menu jurnal');
            $this->fail('Generic journal reversal accepted a payroll retry.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('halaman Payroll', $exception->errors()['journal'][0]);
        }
        $this->assertSame('PAID', $run->fresh()->status);
        $this->assertSame(2, JournalEntry::query()->where('purpose', 'PAYROLL_PAYMENT')->count());
    }

    public function test_cancel_run_reverses_accrual_and_replaces_slip_with_cancelled_pdf(): void
    {
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        $service->approve($run, $this->manager);
        $item = $run->items()->firstOrFail();
        $oldPath = $item->slip_path;
        $accrualId = app(JournalService::class)->latestPayrollAccrual($run)->id;
        $service->cancelRun($run, $this->manager, 'Periode salah', $accrualId);
        $service->cancelRun($run, $this->manager, 'Periode salah', $accrualId);
        $this->assertSame('CANCELLED', $run->fresh()->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'payroll_run_cancelled')->count());
        $this->assertSame(1, JournalEntry::query()->where('purpose', 'REVERSAL')->count());
        $accrual = JournalEntry::query()->where('purpose', 'PAYROLL_ACCRUAL')->firstOrFail();
        $this->assertSame($accrual->id, $accrual->reversal->reverses_entry_id);
        $this->assertNotSame($oldPath, $item->fresh()->slip_path);
        $this->assertStringEndsWith('-cancelled.pdf', $item->fresh()->slip_path);
        Storage::disk('local')->assertExists($item->fresh()->slip_path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($item->fresh()->slip_path));
        $this->assertNotSame(Storage::disk('local')->get($oldPath), Storage::disk('local')->get($item->fresh()->slip_path));
        $this->assertStringContainsString('Dibatalkan', view('payroll.slip', [
            'run' => $run->fresh(), 'item' => $item->fresh(), 'snapshot' => $item->snapshot,
        ])->render());
        $this->get(route('admin.payroll-slips.download', $item))->assertSuccessful();
        $this->actingAs($this->employee);
        $this->getJson('/api/v1/payroll/my-slips')->assertOk()->assertJsonPath('data.0.status', 'CANCELLED');
        $this->get(route('payroll.my-slips.download', $item))->assertSuccessful();
        $this->actingAs($this->manager);
        try {
            $service->create('2026-10', $this->manager);
            $this->fail('Cancelled period was reused.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('period', $exception->errors());
        }
    }

    public function test_payment_must_be_cancelled_before_run_and_wrong_role_gets_403(): void
    {
        PermissionMatrix::set(Role::FINANCE, PanelModule::PAYROLL, []);
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        $service->approve($run, $this->manager);
        $service->markPaid($run, $this->manager, '2026-10-31', (int) DB::table('accounts')->where('code', '1-1100')->value('id'));
        try {
            $service->cancelRun($run, $this->manager, 'Batalkan', app(JournalService::class)->latestPayrollAccrual($run)->id);
            $this->fail('Paid run was cancelled before payment reversal.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Batalkan pembayaran dulu', $exception->errors()['run'][0]);
        }
        $finance = User::factory()->role(Role::FINANCE)->create();
        $page = Livewire::test(PayrollRuns::class)->set('selectedRunId', $run->id)
            ->set('cancellationReason', 'Tidak boleh');
        $this->actingAs($finance);
        $page->call('cancelPayment')->assertForbidden();
        $this->actingAs($this->manager);
        $page = Livewire::test(PayrollRuns::class)->set('selectedRunId', $run->id)
            ->set('cancellationReason', 'Tidak boleh');
        $this->actingAs($finance);
        $page->call('cancelRun')->assertForbidden();
        $this->actingAs($this->manager);
        $service->cancelPayment($run, $this->manager, 'Kas salah', app(JournalService::class)->latestPayrollPayment($run)->id);
        $service->cancelRun($run, $this->manager, 'Run salah', app(JournalService::class)->latestPayrollAccrual($run)->id);
        $this->assertSame('CANCELLED', $run->fresh()->status);
        $this->assertSame(2, JournalEntry::query()->where('purpose', 'REVERSAL')->count());
    }

    public function test_cancelled_run_can_be_reopened_and_reapproved_without_reusing_reversed_accrual(): void
    {
        $service = app(PayrollRunService::class);
        $this->employee->update(['joined_at' => '2026-10-01']);
        $run = $service->create('2026-10', $this->manager);
        $service->approve($run, $this->manager);
        $first = app(JournalService::class)->latestPayrollAccrual($run);
        $service->cancelRun($run, $this->manager, 'Perbaiki komponen', $first->id);
        $cancelledItem = $run->items()->firstOrFail();
        $this->assertSame('CANCELLED', $run->fresh()->status);

        $service->reopenRun($run, $this->manager, $first->id);
        $service->reopenRun($run, $this->manager, $first->id);
        $this->assertSame('DRAFT', $run->fresh()->status);
        $this->assertNotSame($cancelledItem->id, $run->items()->firstOrFail()->id);
        $this->assertSame(1, AuditLog::query()->where('action', 'payroll_run_reopened')->count());
        $archive = AuditLog::query()->where('action', 'payroll_run_reopened')->firstOrFail();
        $this->assertSame($cancelledItem->snapshot,
            $archive->displayChanges('before')['approved_items'][0]['snapshot']);
        try {
            $service->approve($run, $this->manager);
            $this->fail('Delayed approval of the old generation was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('kedaluwarsa', $exception->errors()['run'][0]);
        }
        $this->assertSame('DRAFT', $run->fresh()->status);
        $service->approve($run, $this->manager, $first->id);
        $second = app(JournalService::class)->latestPayrollAccrual($run);
        $this->assertSame('APPROVED', $run->fresh()->status);
        $this->assertSame('payroll_run_accrual_retry', $second->source_type);
        $this->assertSame($first->reversal->id, $second->source_id);
        $this->assertSame(2, JournalEntry::query()->where('purpose', 'PAYROLL_ACCRUAL')->count());
        PermissionMatrix::set(Role::FINANCE, PanelModule::PAYROLL, []);
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        $this->assertArrayNotHasKey('approved_items', $archive->displayChanges('before'));
        $this->actingAs($this->manager);
        try {
            $service->cancelRun($run, $this->manager, 'Permintaan lama', $first->id);
            $this->fail('Stale cancellation reversed corrected accrual.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('sudah berubah', $exception->errors()['run'][0]);
        }
        $november = $service->create('2026-11', $this->manager);
        $service->approve($november, $this->manager);
        $december = $service->create('2026-12', $this->manager);
        $this->assertSame(3, $december->items()->firstOrFail()->snapshot['working_months']);
    }

    public function test_locked_december_prevents_cancelling_earlier_worked_month(): void
    {
        $service = app(PayrollRunService::class);
        $this->employee->update(['joined_at' => '2026-10-01']);
        foreach (['2026-10', '2026-11', '2026-12'] as $period) {
            $run = $service->create($period, $this->manager);
            $service->approve($run, $this->manager);
        }
        $october = PayrollRun::query()->whereDate('period_start', '2026-10-01')->firstOrFail();
        $octoberAccrual = app(JournalService::class)->latestPayrollAccrual($october);
        try {
            $service->cancelRun($october, $this->manager, 'Koreksi Oktober', $octoberAccrual->id);
            $this->fail('December retained stale annual tax after October cancellation.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Desember sudah dikunci', $exception->errors()['run'][0]);
        }
        $this->assertSame('APPROVED', $october->fresh()->status);
        $this->assertNull($octoberAccrual->fresh()->reversal);
        $december = PayrollRun::query()->whereDate('period_start', '2026-12-01')->firstOrFail();
        $service->cancelRun($december, $this->manager, 'Koreksi Oktober dulu', app(JournalService::class)->latestPayrollAccrual($december)->id);
        $service->cancelRun($october, $this->manager, 'Koreksi Oktober', $octoberAccrual->id);
        $this->assertSame('CANCELLED', $october->fresh()->status);
    }

    public function test_locked_december_prevents_approval_of_reopened_prior_month(): void
    {
        $service = app(PayrollRunService::class);
        $this->employee->update(['joined_at' => '2026-10-01']);
        $october = $service->create('2026-10', $this->manager);
        $service->approve($october, $this->manager);
        $november = $service->create('2026-11', $this->manager);
        $service->approve($november, $this->manager);
        $novemberAccrualId = app(JournalService::class)->latestPayrollAccrual($november)->id;
        $service->cancelRun($november, $this->manager, 'Ganti riwayat November', $novemberAccrualId);
        PayrollPriorWithholdingRecord::query()->create([
            'user_id' => $this->employee->id, 'year' => 2026,
            'amount_minor' => 500000, 'gross_minor' => 10000000, 'jht_jp_minor' => 0,
            'months' => [11], 'reason' => 'Riwayat dari sistem lama', 'created_by' => $this->manager->id,
        ]);
        $december = $service->create('2026-12', $this->manager);
        $service->approve($december, $this->manager);
        $service->reopenRun($november, $this->manager, $novemberAccrualId);
        try {
            $service->approve($november, $this->manager, $novemberAccrualId);
            $this->fail('November was approved after December locked manual November history.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Desember sudah dikunci', $exception->errors()['run'][0]);
        }
        $this->assertSame('DRAFT', $november->fresh()->status);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'payroll_run_accrual_retry')->count());
    }

    public function test_december_guard_uses_recalculated_roster_before_earlier_approval(): void
    {
        $service = app(PayrollRunService::class);
        $october = $service->create('2026-10', $this->manager);
        $this->employee->employeeProfile->update(['payroll_active' => false]);
        $newEmployee = User::factory()->role(Role::STAFF)->create(['joined_at' => '2026-10-01']);
        $newEmployee->employeeProfile()->create([
            'base_salary_minor' => 10000000, 'ptkp_status' => 'TK/0',
            'bpjs_tk_enrolled' => false, 'bpjs_kes_enrolled' => false, 'payroll_active' => true,
        ]);
        PayrollPriorWithholdingRecord::query()->create([
            'user_id' => $newEmployee->id, 'year' => 2026,
            'amount_minor' => 1000000, 'gross_minor' => 20000000, 'jht_jp_minor' => 0,
            'months' => [10, 11], 'reason' => 'Riwayat sistem lama', 'created_by' => $this->manager->id,
        ]);
        $december = $service->create('2026-12', $this->manager);
        $service->approve($december, $this->manager);
        try {
            $service->approve($october, $this->manager);
            $this->fail('Changed roster bypassed December dependency gate.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Desember sudah dikunci', $exception->errors()['run'][0]);
        }
        $this->assertSame('DRAFT', $october->fresh()->status);
        $this->assertSame([$this->employee->id], $october->items()->pluck('user_id')->all());
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'payroll_run')->where('purpose', 'PAYROLL_ACCRUAL')
            ->where('source_id', $october->id)->count());
    }

    public function test_payroll_reversal_handles_inactive_historical_account_and_keeps_reason_private(): void
    {
        $customAccountId = DB::table('accounts')->insertGetId([
            'code' => '4-1399', 'name' => 'Potongan khusus', 'type' => 'REVENUE',
            'normal_balance' => 'CREDIT', 'is_active' => true, 'is_system' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $typeId = DB::table('payroll_component_types')->where('code', 'OTHER_DEDUCTION')->value('id');
        DB::table('payroll_component_types')->where('id', $typeId)->update(['account_id' => $customAccountId]);
        PayrollComponentEntry::query()->create([
            'user_id' => $this->employee->id, 'component_type_id' => $typeId,
            'period_start' => '2026-10-01', 'request_key' => (string) Str::uuid(),
            'amount_minor' => 50000, 'created_by' => $this->manager->id,
        ]);
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->manager);
        $service->approve($run, $this->manager);
        DB::table('accounts')->where('id', $customAccountId)->update(['is_active' => false]);
        $accrual = app(JournalService::class)->latestPayrollAccrual($run);
        $reason = 'Koreksi gaji karyawan rahasia';
        $service->cancelRun($run, $this->manager, $reason, $accrual->id);
        $this->assertSame('CANCELLED', $run->fresh()->status);
        $reversal = $accrual->fresh()->reversal;
        $this->assertNotNull($reversal);
        $this->assertStringNotContainsString($reason, $reversal->description);
        $this->assertFalse($reversal->lines()->where('memo', 'like', '%rahasia%')->exists());
        $audit = AuditLog::query()->where('action', 'payroll_run_cancelled')->firstOrFail();
        $this->assertSame($reason, $audit->displayChanges('after')['reason']);
        PermissionMatrix::set(Role::FINANCE, PanelModule::PAYROLL, []);
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        $this->assertArrayNotHasKey('reason', $audit->displayChanges('after'));
        $this->assertStringNotContainsString($reason, json_encode($audit->after_json));
        $this->actingAs($this->manager);
        $bytes = Excel::raw(new PayrollRunExport($run->fresh()), ExcelFormat::XLSX);
        $file = tmpfile();
        fwrite($file, $bytes);
        $sheet = IOFactory::load(stream_get_meta_data($file)['uri'])->getActiveSheet();
        $this->assertSame('Dibatalkan', $sheet->getCell('K2')->getValue());
        fclose($file);
    }

    public function test_december_uses_progressive_pasal_17_brackets_and_annual_job_expense_cap(): void
    {
        $versionId = DB::table('payroll_annual_tax_versions')->insertGetId([
            'effective_from' => '2026-12-01', 'source_note' => 'Synthetic progressive fixture',
            'verified_by' => $this->manager->id, 'verified_at' => now(),
        ]);
        DB::table('payroll_annual_tax_brackets')->insert([
            ['version_id' => $versionId, 'lower_taxable_minor' => 0, 'upper_taxable_minor' => 50000000, 'rate_bps' => 500],
            ['version_id' => $versionId, 'lower_taxable_minor' => 50000001, 'upper_taxable_minor' => null, 'rate_bps' => 1000],
        ]);
        for ($month = 1; $month <= 11; $month++) {
            $period = '2026-'.sprintf('%02d', $month);
            $run = PayrollRun::query()->create([
                'period_start' => $period.'-01', 'status' => 'APPROVED', 'created_by' => $this->manager->id,
            ]);
            $run->items()->create([
                'user_id' => $this->employee->id,
                'snapshot' => app(PayrollCalculator::class)->calculate($this->employee->fresh(), $period),
            ]);
        }
        $snapshot = app(PayrollRunService::class)->create('2026-12', $this->manager)->items()->firstOrFail()->snapshot;
        $this->assertSame(12, $snapshot['working_months']);
        $this->assertSame(114000000, $snapshot['annual_taxable_minor']);
        $this->assertSame(8900000, $snapshot['annual_tax_minor']);
        $this->assertSame(3400000, $snapshot['pph21_minor']);
    }

    public function test_missing_january_november_history_is_rejected_and_manual_withholding_requires_reason(): void
    {
        try {
            app(PayrollRunService::class)->create('2026-12', $this->manager);
            $this->fail('Incomplete history must fail.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('belum lengkap', $exception->errors()['period'][0]);
        }
        $this->get(PayrollPriorWithholding::getUrl())->assertSuccessful();
        $finance = User::factory()->role(Role::FINANCE)->create();
        $this->actingAs($finance);
        $this->get(PayrollPriorWithholding::getUrl())->assertSuccessful();
        Livewire::test(PayrollPriorWithholding::class)
            ->set('input.user_id', $this->employee->id)->set('input.year', 2026)
            ->set('input.months', '1,2')->set('input.gross_minor', 20000000)
            ->set('input.jht_jp_minor', 0)->set('input.amount_minor', 100000)->set('input.reason', '')
            ->call('save')->assertHasErrors(['input.reason']);
        Livewire::test(PayrollPriorWithholding::class)
            ->set('input.user_id', $this->employee->id)->set('input.year', 2026)
            ->set('input.months', '1,2')->set('input.gross_minor', 20000000)
            ->set('input.jht_jp_minor', 0)->set('input.amount_minor', 100000)->set('input.reason', 'PPh dari sistem lama')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(1, DB::table('payroll_prior_withholdings')->count());
    }

    public function test_prior_withholding_form_validates_months_integer_amounts_and_shows_saved_actor(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $this->actingAs($finance);
        $valid = [
            'user_id' => $this->employee->id, 'year' => 2026, 'months' => '1,12',
            'gross_minor' => 20000000, 'jht_jp_minor' => 100000,
            'amount_minor' => 250000, 'reason' => 'Data dari sistem lama',
        ];

        foreach (['0', '13', '1,1'] as $months) {
            Livewire::test(PayrollPriorWithholding::class)
                ->set('input', [...$valid, 'months' => $months])
                ->call('save')->assertHasErrors(['input.months']);
        }
        foreach (['-1', '2.5'] as $amount) {
            Livewire::test(PayrollPriorWithholding::class)
                ->set('input', [...$valid, 'amount_minor' => $amount])
                ->call('save')->assertHasErrors(['input.amount_minor']);
        }
        Livewire::test(PayrollPriorWithholding::class)
            ->set('input', [...$valid, 'reason' => ''])
            ->call('save')->assertHasErrors(['input.reason']);
        $notOnPayroll = User::factory()->role(Role::STAFF)->create();
        Livewire::test(PayrollPriorWithholding::class)
            ->set('input', [...$valid, 'user_id' => $notOnPayroll->id])
            ->call('save')->assertHasErrors(['input.user_id']);
        $this->assertSame(0, PayrollPriorWithholdingRecord::query()->count());

        Livewire::test(PayrollPriorWithholding::class)
            ->set('input', $valid)->call('save')->assertHasNoErrors()
            ->assertSee($this->employee->name)->assertSee($finance->name)
            ->assertSee('Rp 250.000')->assertSee('Data dari sistem lama');
        $record = PayrollPriorWithholdingRecord::query()->firstOrFail();
        $this->assertSame([1, 12], $record->months);
        $this->assertSame($finance->id, $record->created_by);
    }

    public function test_prior_withholding_allows_administrator_and_rejects_field_roles_with_403(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());
        $this->get(PayrollPriorWithholding::getUrl())->assertOk();
        foreach ([Role::BARISTA, Role::RIDER, Role::STAFF, Role::CONTENT_CREATOR] as $role) {
            $this->actingAs(User::factory()->role($role)->create());
            $this->get(PayrollPriorWithholding::getUrl())->assertForbidden();
            $this->actingAs($this->manager);
            $page = Livewire::test(PayrollPriorWithholding::class);
            $this->actingAs(User::factory()->role($role)->create());
            $page->call('save')->assertForbidden();
        }
        $this->assertSame(0, PayrollPriorWithholdingRecord::query()->count());
    }

    public function test_prior_employer_december_withholding_reconciles_without_counting_december_twice(): void
    {
        $this->employee->update(['joined_at' => '2026-12-01']);
        PayrollPriorWithholdingRecord::query()->create([
            'user_id' => $this->employee->id, 'year' => 2026, 'months' => [12],
            'gross_minor' => 5000000, 'jht_jp_minor' => 0, 'amount_minor' => 100000,
            'reason' => 'PPh Desember dari tempat kerja sebelumnya', 'created_by' => $this->manager->id,
        ]);

        $snapshot = app(PayrollRunService::class)->create('2026-12', $this->manager)
            ->items()->firstOrFail()->snapshot;
        $this->assertSame(1, $snapshot['working_months']);
        $this->assertSame(14500000, $snapshot['annual_taxable_minor']);
        $this->assertSame(725000, $snapshot['annual_tax_minor']);
        $this->assertSame(100000, $snapshot['prior_withheld_minor']);
        $this->assertSame(625000, $snapshot['pph21_minor']);
    }

    public function test_manual_legacy_history_covers_missing_month_without_guessing_gross(): void
    {
        $this->employee->update(['joined_at' => '2026-10-01']);
        $october = PayrollRun::query()->create([
            'period_start' => '2026-10-01', 'status' => 'APPROVED', 'created_by' => $this->manager->id,
        ]);
        $october->items()->create([
            'user_id' => $this->employee->id,
            'snapshot' => app(PayrollCalculator::class)->calculate($this->employee->fresh(), '2026-10'),
        ]);
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        Livewire::test(PayrollPriorWithholding::class)
            ->set('input.user_id', $this->employee->id)->set('input.year', 2026)
            ->set('input.months', '11')->set('input.gross_minor', 10000000)
            ->set('input.jht_jp_minor', 0)->set('input.amount_minor', 500000)
            ->set('input.reason', 'Payroll November dari sistem lama')
            ->call('save')->assertHasNoErrors();
        $this->actingAs($this->manager);
        $run = app(PayrollRunService::class)->create('2026-12', $this->manager);
        $s = $run->items()->firstOrFail()->snapshot;
        $this->assertSame(3, $s['working_months']);
        $this->assertSame(30000000, $s['taxable_gross_minor'] + 20000000);
        $this->assertSame(425000, $s['pph21_minor']);
    }

    public function test_other_roles_get_403_for_configuration_verification_runs_and_cross_employee_slips(): void
    {
        PermissionMatrix::set(Role::FINANCE, PanelModule::PAYROLL, []);
        $run = app(PayrollRunService::class)->create('2026-10', $this->manager);
        $item = $run->items()->firstOrFail();
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());
        $this->get(PayrollRuns::getUrl())->assertOk();
        $this->get(PayrollConfiguration::getUrl())->assertOk();
        foreach ([Role::FINANCE] as $role) {
            $this->actingAs($this->manager);
            $page = Livewire::test(PayrollConfiguration::class);
            $this->actingAs(User::factory()->role($role)->create());
            $this->get(PayrollRuns::getUrl())->assertForbidden();
            $this->get(PayrollConfiguration::getUrl())->assertForbidden();
            $page->call('verifyVersion', 'payroll_settings', DB::table('payroll_settings')->value('id'))
                ->assertForbidden();
            $this->get(route('payroll.my-slips.download', $item))->assertForbidden();
        }
        $this->actingAs($this->manager);
        app(PayrollRunService::class)->approve($run, $this->manager);
        $item = $run->fresh()->items()->firstOrFail();
        $this->manager->update(['is_active' => false]);
        $this->get(route('admin.payroll-slips.download', $item))->assertForbidden();
        $this->actingAs($this->employee);
        $this->employee->update(['is_active' => false]);
        $this->getJson('/api/v1/payroll/my-slips')->assertForbidden();
        $this->get(route('payroll.my-slips.download', $item))->assertForbidden();
    }

    public function test_finance_gets_403_on_payroll_write_actions(): void
    {
        PermissionMatrix::set(Role::FINANCE, PanelModule::PAYROLL, []);
        $run = app(PayrollRunService::class)->create('2026-10', $this->manager);
        $page = Livewire::test(PayrollRuns::class)->set('selectedRunId', $run->id);
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        $page->call('approve')->assertForbidden();
        $this->actingAs($this->manager);
        $page = Livewire::test(PayrollRuns::class)->set('selectedRunId', $run->id);
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        $page->call('markPaid')->assertForbidden();
    }

    public function test_current_month_accounting_cycle_reconciles_ledger_trial_balance_and_management_profit(): void
    {
        config(['outbox.publish_mode' => 'queue']);
        $this->travelTo(Carbon::parse('2026-10-19 10:00:00'));
        $finance = User::factory()->role(Role::FINANCE)->create();
        PermissionMatrix::forget();
        PermissionMatrix::set(Role::FINANCE, PanelModule::PURCHASE_ORDERS, ['view', 'edit']);
        $barista = User::factory()->role(Role::BARISTA)->create();
        $cash = CashAccount::query()->where('name', 'Kas Kecil Kantor')->firstOrFail();
        $bank = CashAccount::query()->where('name', 'Bank')->firstOrFail();
        $accounts = Account::query()->get()->keyBy('code');
        $opening = app(JournalService::class)->postOpening('2026-10-01', [
            ['account_id' => $accounts['1-1100']->id, 'debit_minor' => 1000000, 'credit_minor' => 0],
            ['account_id' => $accounts['3-1200']->id, 'debit_minor' => 0, 'credit_minor' => 1000000],
        ]);
        $this->assertSame('OPENING', $opening->purpose);

        $kitchen = CentralKitchen::query()->create([
            'name' => 'Dapur Siklus', 'address' => 'Jl. Uji', 'open_at' => '05:00',
            'close_at' => '20:00', 'is_active' => true,
        ]);
        $cart = Cart::query()->create(['code' => 'E2E-048', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $directCart = Cart::query()->create(['code' => 'E2E-048-D', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $location = Location::query()->create(['name' => 'Lokasi E2E', 'lat' => -6.18, 'lng' => 106.88]);
        $product = Product::query()->create([
            'code' => 'E2E-048-P', 'name' => 'Kopi E2E', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);
        ProductPriceVersion::query()->create([
            'product_id' => $product->id, 'cost_price_minor' => 8000,
            'sell_price_minor' => 20000, 'effective_from' => today()->subDay(),
        ]);
        StaffAssignment::query()->create([
            'source' => 'hand_to_cart', 'user_id' => $this->employee->id,
            'cart_id' => $cart->id, 'location_id' => $location->id,
            'operating_date' => today()->toDateString(), 'assigned_by' => $barista->id,
            'kitchen_id' => $kitchen->id,
        ]);
        $attendance = app(AttendanceService::class);
        $attendance->clockIn($barista);
        $attendance->openStaffWindow($barista);
        $attendance->clockIn($this->employee);
        foreach ([[$cart, 10], [$directCart, 2]] as [$stockCart, $qty]) {
            app(StockLedgerService::class)->post(StockLedgerService::CART, $stockCart->id,
                $product->id, MovementType::ALLOCATION_IN, $qty, $barista->id, $kitchen->id);
        }
        $closingBody = [
            'lines' => [['product_id' => $product->id, 'qty_remaining' => 4, 'qty_wasted' => 0]],
            'qris' => 40000, 'transfer' => 20000, 'gps_lat' => -6.18, 'gps_lng' => 106.88,
        ];
        $closingKey = (string) Str::uuid();
        $this->actingAs($this->employee, 'sanctum')->withHeader('Idempotency-Key', $closingKey)
            ->postJson('/api/v1/me/closing', $closingBody)->assertCreated()
            ->assertJsonPath('data.total_minor', 120000);
        $this->actingAs($this->employee, 'sanctum')->withHeader('Idempotency-Key', $closingKey)
            ->postJson('/api/v1/me/closing', $closingBody)->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');
        $settlementId = $this->actingAs($finance, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $cart->id, 'cash' => 60000, 'qris' => 40000, 'transfer' => 20000,
            ])->assertCreated()->json('data.id');
        $this->actingAs($finance, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/settlements/{$settlementId}/approve", [
                'lines' => [['product_id' => $product->id, 'qty_returned' => 4, 'qty_rejected' => 0]],
            ])->assertOk();
        $settlementRevenue = JournalEntry::query()->where('source_type', 'settlement')
            ->where('source_id', $settlementId)->where('purpose', 'SETTLEMENT')->sole();
        $entryLines = static fn (JournalEntry $entry): array => $entry->lines()->with('account')->get()
            ->mapWithKeys(fn ($line): array => [$line->account->code => [(int) $line->debit_minor, (int) $line->credit_minor]])->all();
        $this->assertSame(['1-1200', '1-1400', '4-1100'],
            $settlementRevenue->lines()->with('account')->get()->pluck('account.code')->sort()->values()->all());
        $this->assertSame([
            '1-1200' => [60000, 0], '1-1400' => [60000, 0], '4-1100' => [0, 120000],
        ], $entryLines($settlementRevenue));
        $this->assertSame('2026-10-19', $settlementRevenue->entry_date->toDateString());
        $this->assertSame(48000, (int) JournalLine::query()->whereHas('entry', fn ($query) => $query
            ->where('source_type', 'stock_ledger')->where('purpose', 'SALE_COGS'))
            ->where('account_id', $accounts['5-1100']->id)->sum('debit_minor'));

        $supplier = Supplier::query()->create(['code' => 'E2E-048-S', 'name' => 'Supplier E2E', 'is_active' => true]);
        $material = RawMaterial::query()->create(['code' => 'E2E-048-M', 'name' => 'Bahan E2E',
            'unit' => 'g', 'is_active' => true, 'sort_order' => 1]);
        $purchase = app(PurchaseOrderService::class);
        foreach (['DIRECT', 'CREDIT'] as $method) {
            $po = $purchase->create($supplier->id, $kitchen->id,
                [['raw_material_id' => $material->id, 'qty_ordered' => 10, 'unit_cost_minor' => 2500]], $finance);
            $po = $purchase->markOrdered($po, $finance);
            $po = $purchase->receive($po, [], $finance, $method, $method === 'DIRECT' ? $cash->id : null);
            $receipt = $po->receiptJournal()->firstOrFail();
            $this->assertSame(25000, (int) $receipt->lines()->sum('debit_minor'));
            $this->assertSame([
                '1-1700' => [25000, 0], $method === 'DIRECT' ? '1-1100' : '2-1400' => [0, 25000],
            ], $entryLines($receipt));
            $this->assertSame($po->received_at->toDateString(), $receipt->entry_date->toDateString());
            if ($method === 'CREDIT') {
                $payment = ['uuid' => (string) Str::uuid(), 'paid_on' => today()->toDateString(),
                    'cash_account_id' => $cash->id, 'amount_minor' => 25000];
                $this->assertTrue(PermissionMatrix::can($finance, PanelModule::PURCHASE_ORDERS, 'edit'));
                $paid = $purchase->payDebt($po, $finance, $payment);
                $this->assertSame($paid->id, $purchase->payDebt($po, $finance, $payment)->id);
                $this->assertSame(0, $po->unpaidMinor());
                $this->assertSame(['2-1400' => [25000, 0], '1-1100' => [0, 25000]],
                    $entryLines($paid->journal()->firstOrFail()));
            }
        }
        $cashService = app(CashTransactionService::class);
        foreach (['Listrik', 'Pendapatan Lain-lain'] as $category) {
            $input = ['uuid' => (string) Str::uuid(), 'date' => today()->toDateString(),
                'cash_category_id' => CashCategory::query()->where('name', $category)->firstOrFail()->id,
                'cash_account_id' => $bank->id, 'amount_minor' => 125000,
                'description' => 'Siklus akuntansi E2E'];
            $tx = $cashService->record($finance, $input);
            $this->assertSame($tx->id, $cashService->record($finance, $input)->id);
            $this->assertSame($category === 'Listrik'
                ? ['6-1400' => [125000, 0], '1-1300' => [0, 125000]]
                : ['1-1300' => [125000, 0], '4-1300' => [0, 125000]],
                $entryLines(JournalEntry::query()->where('source_type', 'cash_transaction')
                    ->where('source_id', $tx->id)->sole()));
        }
        $direct = app(DirectSaleService::class)->record($finance, $directCart->id,
            [['product_id' => $product->id, 'qty' => 1]], 'transfer');
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'direct_sale')
            ->where('source_id', $direct->id)->count());
        $this->assertSame(['1-1400' => [20000, 0], '4-1200' => [0, 20000]],
            $entryLines(JournalEntry::query()->where('source_type', 'direct_sale')
                ->where('source_id', $direct->id)->where('purpose', 'DIRECT_SALE')->sole()));
        $this->assertSame(['5-1100' => [8000, 0], '1-1600' => [0, 8000]],
            $entryLines(JournalEntry::query()->where('source_type', 'stock_ledger')
                ->where('source_id', DB::table('stock_ledger')->where('ref_type', 'direct_sale')
                    ->where('ref_id', $direct->id)->where('movement_type', 'SALE_OUT')->value('id'))
                ->where('purpose', 'SALE_COGS')->sole()));

        $payroll = app(PayrollRunService::class);
        $run = $payroll->create('2026-10', $this->manager);
        // T-045 permits approval only after the payroll month ends.
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00'));
        $payroll->approve($run, $this->manager);
        $payroll->markPaid($run, $this->manager, today()->toDateString(), $bank->account_id);
        $this->travelTo(Carbon::parse('2026-10-19 10:00:00'));
        $this->assertSame('PAID', $run->fresh()->status);
        $this->assertSame(2, JournalEntry::query()->where('source_type', 'payroll_run')->count());
        $accrual = JournalEntry::query()->where('source_type', 'payroll_run')->where('purpose', 'PAYROLL_ACCRUAL')->sole();
        $payment = JournalEntry::query()->where('source_type', 'payroll_run')->where('purpose', 'PAYROLL_PAYMENT')->sole();
        $this->assertSame('2026-10-31', $accrual->entry_date->toDateString());
        $this->assertSame('2026-11-01', $payment->entry_date->toDateString());
        $this->assertSame(10000000, $entryLines($accrual)['6-1200'][0]);
        $this->assertArrayHasKey('2-1100', $entryLines($accrual));
        $this->assertArrayHasKey('2-1300', $entryLines($accrual));
        $this->assertArrayHasKey('1-1300', $entryLines($payment));

        $manual = app(JournalService::class)->post([
            'entry_date' => today()->toDateString(), 'description' => 'Koreksi sementara',
            'source_type' => 'manual', 'source_id' => 48048, 'purpose' => 'MANUAL',
        ], [
            ['account_id' => $accounts['1-1100']->id, 'debit_minor' => 500, 'credit_minor' => 0],
            ['account_id' => $accounts['3-1200']->id, 'debit_minor' => 0, 'credit_minor' => 500],
        ]);
        $reversal = app(JournalService::class)->reverse($manual, 'Koreksi tidak diperlukan');
        $this->assertSame('REVERSAL', $reversal->purpose);
        try {
            app(JournalService::class)->reverse($manual, 'Coba lagi');
            $this->fail('Jurnal bisa dibalik dua kali.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('journal', $exception->errors());
        }

        $this->actingAs($this->manager);
        $ledger = Livewire::test(GeneralLedger::class)->instance();
        foreach (['1-1100', '1-1200', '1-1300', '2-1400', '4-1100', '4-1200', '5-1100', '6-1200'] as $code) {
            $account = $accounts[$code];
            $rows = $ledger->ledgerRows($account);
            $debit = (int) JournalLine::query()->where('account_id', $account->id)->sum('debit_minor');
            $credit = (int) JournalLine::query()->where('account_id', $account->id)->sum('credit_minor');
            $expected = $account->normal_balance === 'DEBIT' ? $debit - $credit : $credit - $debit;
            $this->assertSame($expected, (int) $rows->getCollection()->last()->running_minor, $code);
        }
        foreach (JournalEntry::query()->with('lines')->get() as $entry) {
            $this->assertSame((int) $entry->lines->sum('debit_minor'),
                (int) $entry->lines->sum('credit_minor'), $entry->number);
        }
        $report = app(ManagementReportService::class)->build(today()->startOfMonth(), today()->endOfMonth());
        $trial = $report['sections']['neraca_saldo']['rows'];
        $total = $trial[array_key_last($trial)];
        $this->assertSame($total[2], $total[3]);
        $this->assertSame($report['summary']['net_profit_minor'],
            $report['sections']['laba_rugi']['rows'][array_key_last($report['sections']['laba_rugi']['rows'])][3]);
        $profitRows = Account::query()->whereIn('type', ['REVENUE', 'COGS', 'EXPENSE'])->get()->map(function (Account $account): array {
            $debit = (int) JournalLine::query()->where('account_id', $account->id)->sum('debit_minor');
            $credit = (int) JournalLine::query()->where('account_id', $account->id)->sum('credit_minor');

            return [$account->code, $credit - $debit];
        });
        $profit = $profitRows->sum(fn (array $row): int => $row[1]);
        $this->assertSame($profit, $report['summary']['net_profit_minor']);

        app(AccountingPeriodService::class)->setStatus(2026, 10, 'CLOSED', $this->manager);
        $before = JournalEntry::query()->count();
        try {
            app(JournalService::class)->post(['entry_date' => today()->toDateString(),
                'description' => 'Tidak boleh', 'source_type' => 'manual', 'source_id' => 48049,
                'purpose' => 'MANUAL'], [
                    ['account_id' => $accounts['1-1100']->id, 'debit_minor' => 1, 'credit_minor' => 0],
                    ['account_id' => $accounts['3-1200']->id, 'debit_minor' => 0, 'credit_minor' => 1],
                ]);
            $this->fail('Posting manual di periode tertutup diterima.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entry_date', $exception->errors());
        }
        $next = $purchase->create($supplier->id, $kitchen->id,
            [['raw_material_id' => $material->id, 'qty_ordered' => 1, 'unit_cost_minor' => 1]], $finance);
        $next = $purchase->markOrdered($next, $finance);
        try {
            $purchase->receive($next, [], $finance);
            $this->fail('Penerimaan PO di periode tertutup diterima.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('entry_date', $exception->errors());
        }
        $this->assertSame($before, JournalEntry::query()->count());
    }
}
