<?php

namespace Tests\Feature;

use App\Enums\AttendanceCode;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\EmployeeAdvances;
use App\Filament\Pages\PayrollRuns;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\EmployeeAdvance;
use App\Models\PayrollComponentEntry;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Access\PermissionMatrix;
use App\Services\AccountingPeriodService;
use App\Services\EmployeeAdvanceService;
use App\Services\JournalService;
use App\Services\PayrollRunService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\PayrollConfigurationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeAdvanceTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $employee;

    private EmployeeAdvanceService $advances;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-01-01 12:00:00'));
        Storage::fake('local');
        $this->seed(AccountSeeder::class);
        $this->seed(PayrollConfigurationSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->employee = User::factory()->role(Role::STAFF)->create(['joined_at' => '2026-01-01']);
        $this->employee->employeeProfile()->create([
            'base_salary_minor' => 1000000, 'ptkp_status' => 'TK/0',
            'bpjs_tk_enrolled' => false, 'bpjs_kes_enrolled' => false, 'payroll_active' => true,
        ]);
        $this->actingAs($this->finance);
        $this->advances = app(EmployeeAdvanceService::class);
        $this->rates();
    }

    private function rates(): void
    {
        DB::table('payroll_settings')->insert([
            'effective_from' => '2026-01-01', 'source_note' => 'Fixture',
            'verified_by' => $this->finance->id, 'verified_at' => now(),
        ]);
        DB::table('ptkp_rates')->insert([
            'status' => 'TK/0', 'ter_category' => 'A', 'annual_amount_minor' => 0,
            'effective_from' => '2026-01-01', 'source_note' => 'Fixture',
            'verified_by' => $this->finance->id, 'verified_at' => now(),
        ]);
        DB::table('payroll_ter_versions')->insert([
            'category' => 'A', 'effective_from' => '2026-01-01', 'published_at' => now(),
            'source_note' => 'Fixture', 'verified_by' => $this->finance->id, 'verified_at' => now(),
        ]);
        DB::table('pph21_ter_brackets')->insert([
            'category' => 'A', 'lower_gross_minor' => 0, 'upper_gross_minor' => null,
            'rate_bps' => 0, 'effective_from' => '2026-01-01', 'source_note' => 'Fixture',
        ]);
    }

    private function createAdvance(int $principal = 1000000, string $kind = 'PAYROLL', int $installment = 200000,
        string $source = 'CASH', ?string $date = null, ?string $requestKey = null): EmployeeAdvance
    {
        return $this->advances->create([
            'request_key' => $requestKey ?? (string) Str::uuid(), 'user_id' => $this->employee->id,
            'type_id' => DB::table('employee_advance_types')->where('name', 'Kasbon motor')->value('id'),
            'start_date' => $date ?? '2026-10-01', 'principal_minor' => $principal,
            'reason' => 'Bantuan operasional', 'installment_kind' => $kind,
            'installment_minor' => $kind === 'FREE' ? null : $installment,
            'source_kind' => $source,
            'cash_account_id' => $source === 'CASH' ? Account::query()->where('code', '1-1100')->value('id') : null,
        ], $this->finance);
    }

    public function test_disbursement_and_cash_repayment_balance_and_journal(): void
    {
        $loanKey = (string) Str::uuid();
        $advance = $this->createAdvance(requestKey: $loanKey);
        $this->assertSame($advance->id, $this->createAdvance(requestKey: $loanKey)->id);
        $this->assertSame(1000000, $advance->balanceMinor());
        $this->assertDatabaseHas('journal_lines', [
            'entry_id' => $advance->movements()->firstOrFail()->journal_entry_id,
            'account_id' => Account::query()->where('code', '1-1500')->value('id'),
            'debit_minor' => 1000000, 'credit_minor' => 0,
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'entry_id' => $advance->movements()->firstOrFail()->journal_entry_id,
            'account_id' => Account::query()->where('code', '1-1100')->value('id'),
            'debit_minor' => 0, 'credit_minor' => 1000000,
        ]);
        $key = (string) Str::uuid();
        $payment = [
            'request_key' => $key, 'entry_date' => '2026-10-10', 'amount_minor' => 200000,
            'cash_account_id' => Account::query()->where('code', '1-1100')->value('id'),
        ];
        $this->advances->repay($advance, $payment, $this->finance);
        $this->advances->repay($advance, $payment, $this->finance);
        $operation = DB::table('employee_advance_operations')->where('request_key', $key)->first();
        $this->assertSame($operation->id, DB::table('journal_entries')
            ->where('source_type', 'employee_advance_repayment')->value('source_id'));
        try {
            $this->advances->repay($advance, [...$payment, 'amount_minor' => 100000], $this->finance);
            $this->fail('Changed idempotency replay succeeded.');
        } catch (ValidationException) {
            $this->assertSame(2, $advance->movements()->count());
        }
        $this->assertSame(800000, $advance->balanceMinor());
        $this->assertSame(2, $advance->movements()->count());
        $this->assertSame(0, $this->advances->reconciliation('2026-10-31')['difference_minor']);
    }

    public function test_motor_installments_are_capped_by_balance_and_mark_paid(): void
    {
        $advance = $this->createAdvance(300000);
        $service = app(PayrollRunService::class);
        $october = $service->create('2026-10', $this->finance);
        $this->assertSame(200000, $october->items()->firstOrFail()->snapshot['advance_deductions'][0]['amount_minor']);
        $service->approve($october, $this->finance);
        $this->assertSame(100000, $advance->balanceMinor());
        $november = $service->create('2026-11', $this->finance);
        $this->assertSame(100000, $november->items()->firstOrFail()->snapshot['advance_deductions'][0]['amount_minor']);
        $service->approve($november, $this->finance);
        $this->assertSame(0, $advance->balanceMinor());
        $this->assertSame('PAID', $advance->fresh()->status);
        $this->assertSame(0, $this->advances->reconciliation('2026-11-30')['difference_minor']);
        $this->assertSame(2, $advance->movements()->where('kind', 'PAYROLL_DEDUCTION')->count());
    }

    public function test_installment_never_makes_take_home_negative_and_remainder_carries(): void
    {
        $this->employee->employeeProfile->setSalaryEffectiveFrom('2026-10-01');
        $this->employee->employeeProfile->update(['base_salary_minor' => 100000]);
        $advance = $this->createAdvance(300000);
        $service = app(PayrollRunService::class);
        $october = $service->create('2026-10', $this->finance);
        $snapshot = $october->items()->firstOrFail()->snapshot;
        $this->assertSame(100000, $snapshot['advance_deductions'][0]['amount_minor']);
        $this->assertSame(0, $snapshot['take_home_minor']);
        $service->approve($october, $this->finance);
        $this->assertSame(200000, $advance->balanceMinor());
        $november = $service->create('2026-11', $this->finance);
        $this->assertSame(100000, $november->items()->firstOrFail()->snapshot['advance_deductions'][0]['amount_minor']);
    }

    public function test_opening_balance_and_adjustment_use_equity_without_cash(): void
    {
        $advance = $this->createAdvance(500000, 'FREE', 0, 'OPENING');
        $journal = $advance->movements()->firstOrFail()->journal_entry_id;
        $this->assertDatabaseHas('journal_lines', [
            'entry_id' => $journal, 'account_id' => Account::query()->where('code', '3-1200')->value('id'),
            'debit_minor' => 0, 'credit_minor' => 500000,
        ]);
        $this->assertSame(0, DB::table('journal_lines')->where('entry_id', $journal)
            ->whereIn('account_id', Account::query()->whereIn('code', ['1-1100', '1-1200', '1-1300'])->pluck('id'))->count());
        $this->advances->adjust($advance, [
            'request_key' => (string) Str::uuid(), 'entry_date' => '2026-10-15',
            'amount_minor' => -100000, 'reason' => 'Koreksi saldo lama',
        ], $this->finance);
        $this->assertSame(400000, $advance->balanceMinor());
        $this->assertSame(0, $this->advances->reconciliation('2026-10-31')['difference_minor']);
        $readiness = app(AccountingPeriodService::class)->closeReadiness(2026, 10);
        $this->assertSame('ok', collect($readiness)->firstWhere('code', 'employee_advances_reconciliation')['status']);
    }

    public function test_override_is_audited_and_payroll_cancellation_restores_balance(): void
    {
        $advance = $this->createAdvance(300000);
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->finance);
        $this->advances->setPayrollOverride($run, $advance, 50000, $this->finance);
        $service->recalculate($run, $this->finance);
        $this->assertSame(50000, $run->items()->firstOrFail()->snapshot['advance_deductions'][0]['amount_minor']);
        $this->assertDatabaseHas('audit_log', ['action' => 'employee_advance.payroll_override', 'subject_id' => $advance->id]);
        $service->approve($run, $this->finance);
        $this->assertSame(250000, $advance->balanceMinor());
        $accrualId = app(JournalService::class)->latestPayrollAccrual($run)->id;
        $service->cancelRun($run, $this->finance, 'Koreksi payroll', $accrualId);
        $this->assertSame(300000, $advance->balanceMinor());
        $this->assertSame(0, $this->advances->reconciliation('2027-01-01')['difference_minor']);
        $service->reopenRun($run, $this->finance, $accrualId);
        $service->approve($run, $this->finance, $accrualId);
        $this->assertSame(250000, $advance->balanceMinor());
        $this->assertSame(0, $this->advances->reconciliation('2027-01-01')['difference_minor']);
    }

    public function test_daily_installment_only_uses_attendance_from_loan_start(): void
    {
        foreach (['2026-10-10', '2026-10-20', '2026-10-21'] as $day) {
            DB::table('attendance_marks')->insert([
                'user_id' => $this->employee->id, 'entry_date' => $day,
                'code' => AttendanceCode::PRESENT->value, 'recorded_by' => $this->finance->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $advance = $this->createAdvance(200000, 'DAY', 50000, 'CASH', '2026-10-20');
        $snapshot = app(PayrollRunService::class)->create('2026-10', $this->finance)->items()->firstOrFail()->snapshot;
        $this->assertSame(100000, $snapshot['advance_deductions'][0]['amount_minor']);
        $this->assertSame($advance->id, $snapshot['advance_deductions'][0]['advance_id']);
    }

    public function test_repayment_reversal_restores_cash_and_receivable_with_new_movement(): void
    {
        $advance = $this->createAdvance(300000);
        $repayment = $this->advances->repay($advance, [
            'request_key' => (string) Str::uuid(), 'entry_date' => '2026-10-15',
            'amount_minor' => 200000,
            'cash_account_id' => Account::query()->where('code', '1-1100')->value('id'),
        ], $this->finance);
        $key = (string) Str::uuid();
        $reversal = $this->advances->reverseRepayment($repayment, $key, 'Salah akun kas', $this->finance);
        $this->assertSame($reversal->id, $this->advances->reverseRepayment($repayment, $key, 'Salah akun kas', $this->finance)->id);
        $this->assertSame(300000, $advance->balanceMinor());
        $this->assertSame(0, $this->advances->reconciliation('2027-01-01')['difference_minor']);
        $this->assertDatabaseHas('journal_lines', [
            'entry_id' => $reversal->journal_entry_id,
            'account_id' => Account::query()->where('code', '1-1100')->value('id'),
            'debit_minor' => 0, 'credit_minor' => 200000,
        ]);
    }

    public function test_backdated_repayment_cannot_make_historical_receivable_negative(): void
    {
        $advance = $this->createAdvance(100000);
        $this->advances->adjust($advance, [
            'request_key' => (string) Str::uuid(), 'entry_date' => '2026-12-01',
            'amount_minor' => 900000, 'reason' => 'Koreksi saldo',
        ], $this->finance);
        try {
            $this->advances->repay($advance, [
                'request_key' => (string) Str::uuid(), 'entry_date' => '2026-11-01',
                'amount_minor' => 500000,
                'cash_account_id' => Account::query()->where('code', '1-1100')->value('id'),
            ], $this->finance);
            $this->fail('Backdated repayment succeeded.');
        } catch (ValidationException) {
            $this->assertSame(2, $advance->movements()->count());
            $this->assertSame(0, $this->advances->reconciliation('2026-11-30')['difference_minor']);
        }
    }

    public function test_later_cash_repayment_does_not_block_safe_prior_month_payroll(): void
    {
        $advance = $this->createAdvance(1000000);
        $this->advances->repay($advance, [
            'request_key' => (string) Str::uuid(), 'entry_date' => '2026-11-02',
            'amount_minor' => 100000,
            'cash_account_id' => Account::query()->where('code', '1-1100')->value('id'),
        ], $this->finance);
        $service = app(PayrollRunService::class);
        $run = $service->create('2026-10', $this->finance);
        $this->assertSame(200000, $run->items()->firstOrFail()->snapshot['advance_deductions'][0]['amount_minor']);
        $service->approve($run, $this->finance);
        $this->assertSame(700000, $advance->balanceMinor());
        $this->assertSame(0, $this->advances->reconciliation('2026-10-31')['difference_minor']);
        $this->assertSame(0, $this->advances->reconciliation('2026-11-30')['difference_minor']);
    }

    public function test_unlinked_legacy_advance_deduction_is_reported_as_reconciliation_warning(): void
    {
        PayrollComponentEntry::query()->create([
            'user_id' => $this->employee->id,
            'component_type_id' => DB::table('payroll_component_types')->where('code', 'ADVANCE')->value('id'),
            'period_start' => '2026-10-01', 'request_key' => (string) Str::uuid(),
            'amount_minor' => 50000, 'note' => 'Potongan lama', 'created_by' => $this->finance->id,
        ]);
        $run = app(PayrollRunService::class)->create('2026-10', $this->finance);
        app(PayrollRunService::class)->approve($run, $this->finance);
        $this->assertSame(50000, $this->advances->reconciliation('2026-10-31')['difference_minor']);
        $readiness = app(AccountingPeriodService::class)->closeReadiness(2026, 10);
        $this->assertSame('warning', collect($readiness)->firstWhere('code', 'employee_advances_reconciliation')['status']);
    }

    public function test_audit_override_amount_requires_advance_view_permission(): void
    {
        $advance = $this->createAdvance(300000);
        $run = app(PayrollRunService::class)->create('2026-10', $this->finance);
        $this->advances->setPayrollOverride($run, $advance, 50000, $this->finance);
        $audit = AuditLog::query()->where('action', 'employee_advance.payroll_override')->firstOrFail();
        $this->assertSame(50000, $audit->displayChanges('after')['amount_minor']);
        UserPermission::query()->create([
            'user_id' => $this->finance->id, 'module' => PanelModule::EMPLOYEE_ADVANCES->value,
            'abilities' => [], 'reason' => 'Uji pembatasan', 'updated_by' => $this->finance->id,
        ]);
        PermissionMatrix::forget();
        $this->assertArrayNotHasKey('amount_minor', $audit->displayChanges('after'));
    }

    public function test_override_and_snapshot_roll_back_together_on_failed_recalculation(): void
    {
        $advance = $this->createAdvance(300000);
        $run = app(PayrollRunService::class)->create('2026-10', $this->finance);
        DB::table('payroll_settings')->where('effective_from', '2026-01-01')->update(['verified_at' => null]);
        Livewire::test(PayrollRuns::class)->set('selectedRunId', $run->id)
            ->set('advanceOverride.advance_id', $advance->id)
            ->set('advanceOverride.amount_minor', 50000)
            ->call('saveAdvanceOverride')->assertHasErrors();
        $this->assertDatabaseMissing('employee_advance_payroll_overrides', [
            'payroll_run_id' => $run->id, 'advance_id' => $advance->id,
        ]);
    }

    public function test_non_employee_cannot_receive_loan_and_administrator_cannot_write(): void
    {
        $nonEmployee = User::factory()->role(Role::STAFF)->create();
        try {
            $this->advances->create([
                'request_key' => (string) Str::uuid(), 'user_id' => $nonEmployee->id,
                'type_id' => DB::table('employee_advance_types')->value('id'),
                'start_date' => '2026-10-01', 'principal_minor' => 100000,
                'reason' => 'Invalid', 'installment_kind' => 'FREE', 'source_kind' => 'OPENING',
            ], $this->finance);
            $this->fail('Nonemployee loan succeeded.');
        } catch (ValidationException) {
            $this->assertSame(0, EmployeeAdvance::query()->count());
        }
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->actingAs($admin)->get(EmployeeAdvances::getUrl())->assertOk();
        Livewire::test(EmployeeAdvances::class)->call('createLoan')->assertForbidden();
    }

    public function test_mutations_are_immutable_and_role_without_module_gets_403(): void
    {
        $advance = $this->createAdvance();
        $movement = $advance->movements()->firstOrFail();
        try {
            $movement->update(['amount_minor' => 1]);
            $this->fail('Movement update succeeded.');
        } catch (ValidationException) {
            $this->assertSame(1000000, $movement->fresh()->amount_minor);
        }
        $this->expectException(ValidationException::class);
        $movement->delete();
    }

    public function test_cms_denies_role_without_module_and_no_api_mutation_route_exists(): void
    {
        $advance = $this->createAdvance();
        $this->get(EmployeeAdvances::getUrl())->assertOk()->assertSee('Kartu mutasi');
        $staff = User::factory()->role(Role::STAFF)->create();
        PermissionMatrix::forget();
        $this->actingAs($staff)->get(EmployeeAdvances::getUrl())->assertForbidden();
        $movementId = $advance->movements()->firstOrFail()->id;
        $this->patchJson('/api/v1/employee-advances/'.$advance->id.'/movements/'.$movementId, ['amount_minor' => 1])->assertNotFound();
        $this->deleteJson('/api/v1/employee-advances/'.$advance->id.'/movements/'.$movementId)->assertNotFound();
    }
}
