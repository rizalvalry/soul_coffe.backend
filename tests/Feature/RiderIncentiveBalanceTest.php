<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\RiderIncentiveBalances;
use App\Filament\Pages\TargetCommission;
use App\Models\Account;
use App\Models\Cart;
use App\Models\Product;
use App\Models\RiderClosing;
use App\Models\User;
use App\Services\AccountingPeriodService;
use App\Services\EmployeeAdvanceService;
use App\Services\PayrollRunService;
use App\Services\RiderCommissionService;
use App\Services\RiderIncentiveBalanceService;
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

class RiderIncentiveBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $rider;

    private Cart $cart;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2027-01-01 12:00:00'));
        Storage::fake('local');
        $this->seed(AccountSeeder::class);
        $this->seed(PayrollConfigurationSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->rider = User::factory()->role(Role::STAFF)->create(['joined_at' => '2026-01-01']);
        $this->rider->employeeProfile()->create([
            'base_salary_minor' => 10000000, 'ptkp_status' => 'TK/0',
            'bpjs_tk_enrolled' => false, 'bpjs_kes_enrolled' => false, 'payroll_active' => true,
        ]);
        $this->cart = Cart::query()->create(['code' => 'BALANCE-TEST', 'status' => 'active']);
        $this->product = Product::query()->create([
            'code' => 'BALANCE-CUP', 'name' => 'Cup Uji Saldo', 'unit' => 'cup', 'sort_order' => 1,
        ]);
        $this->actingAs($this->finance);
        DB::table('payroll_settings')->insert([
            'effective_from' => '2026-01-01', 'source_note' => 'Synthetic fixture',
            'verified_by' => $this->finance->id, 'verified_at' => now(),
        ]);
        DB::table('ptkp_rates')->insert([
            'status' => 'TK/0', 'ter_category' => 'A', 'annual_amount_minor' => 0,
            'effective_from' => '2026-01-01', 'source_note' => 'Synthetic fixture',
            'verified_by' => $this->finance->id, 'verified_at' => now(),
        ]);
        DB::table('payroll_ter_versions')->insert([
            'category' => 'A', 'effective_from' => '2026-01-01', 'published_at' => now(),
            'source_note' => 'Synthetic fixture', 'verified_by' => $this->finance->id,
            'verified_at' => now(),
        ]);
        DB::table('pph21_ter_brackets')->insert([
            'category' => 'A', 'lower_gross_minor' => 0, 'upper_gross_minor' => null,
            'rate_bps' => 0, 'effective_from' => '2026-01-01', 'source_note' => 'Synthetic fixture',
        ]);
        app(RiderCommissionService::class)->save([$this->rider->id], '2026-10-01', [
            ['min_cups' => 1, 'amount_per_cup_minor' => 1000],
            ['min_cups' => 101, 'amount_per_cup_minor' => 1500],
        ], $this->finance, (string) Str::uuid());
    }

    private function closing(string $date, int $cups): void
    {
        $closing = RiderClosing::query()->create([
            'rider_id' => $this->rider->id, 'cart_id' => $this->cart->id,
            'operating_date' => $date, 'total_minor' => 0, 'cogs_minor' => 0,
            'qris_minor' => 0, 'transfer_minor' => 0, 'cash_minor' => 0,
            'closed_at' => $date.' 18:00:00',
        ]);
        $closing->lines()->create([
            'product_id' => $this->product->id, 'qty_in' => $cups, 'qty_carry' => 0,
            'qty_remaining' => 0, 'qty_wasted' => 0, 'qty_sold' => $cups,
            'qty_sale_posted' => $cups, 'qty_waste_posted' => 0,
            'unit_price_minor' => 0, 'unit_cost_minor' => 0, 'subtotal_minor' => 0,
        ]);
    }

    private function lockDays(): void
    {
        $commission = app(RiderCommissionService::class);
        foreach (['2026-10-10' => 80, '2026-10-11' => 171] as $date => $cups) {
            $this->closing($date, $cups);
            $commission->lockForSettlement($this->cart->id, $date, $this->finance->id);
        }
    }

    public function test_daily_accrual_withdrawal_payroll_and_reconciliation(): void
    {
        $this->lockDays();
        $service = app(RiderIncentiveBalanceService::class);
        $this->assertSame(336500, $service->balance($this->rider->id));
        $this->assertSame(2, DB::table('journal_entries')->where('purpose', 'RIDER_INCENTIVE_ACCRUAL')->count());
        app(RiderCommissionService::class)->lockForSettlement($this->cart->id, '2026-10-11', $this->finance->id);
        $this->assertSame(336500, $service->balance($this->rider->id));
        $this->assertSame(2, DB::table('journal_entries')->where('purpose', 'RIDER_INCENTIVE_ACCRUAL')->count());

        $input = [
            'request_key' => (string) Str::uuid(), 'rider_id' => $this->rider->id,
            'entry_date' => '2026-10-12', 'amount_minor' => 100000,
            'cash_account_id' => Account::query()->where('code', '1-1100')->value('id'),
            'reason' => null,
        ];
        $movement = $service->withdraw($input, $this->finance);
        $this->assertSame($movement->id, $service->withdraw($input, $this->finance)->id);
        $this->assertSame(236500, $service->balance($this->rider->id));
        $withdrawalJournal = DB::table('journal_entries')->where('id', $movement->journal_entry_id)->first();
        $this->assertSame('WITHDRAWAL', $withdrawalJournal->purpose);
        $this->assertSame(100000, (int) DB::table('journal_lines')->where('entry_id', $withdrawalJournal->id)
            ->where('account_id', Account::query()->where('code', '2-1800')->value('id'))->sum('debit_minor'));
        $this->assertSame(100000, (int) DB::table('journal_lines')->where('entry_id', $withdrawalJournal->id)
            ->where('account_id', $input['cash_account_id'])->sum('credit_minor'));
        try {
            $service->withdraw([...$input, 'request_key' => (string) Str::uuid(), 'amount_minor' => 300000], $this->finance);
            $this->fail('Withdrawal above balance must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_minor', $exception->errors());
            $this->assertSame(422, $exception->status);
        }

        $run = app(PayrollRunService::class)->create('2026-10', $this->finance);
        app(PayrollRunService::class)->approve($run, $this->finance);
        $snapshot = $run->fresh()->items()->firstOrFail()->snapshot;
        $this->assertSame(236500, $snapshot['rider_incentive_minor']);
        $this->assertSame(236500, $snapshot['rider_incentive_balance_minor']);
        $this->assertSame(0, $service->balance($this->rider->id));
        $this->assertSame(336500, (int) DB::table('journal_lines')->where('account_id', Account::query()->where('code', '6-2300')->value('id'))
            ->sum('debit_minor'));
        $this->assertSame(0, $service->reconciliation('2026-10-31')['difference_minor']);
        $readiness = app(AccountingPeriodService::class)->closeReadiness(2026, 10);
        $this->assertSame('ok', collect($readiness)->firstWhere('code', 'rider_incentive_reconciliation')['status']);
        $accrualId = DB::table('journal_entries')->where('purpose', 'PAYROLL_ACCRUAL')->value('id');
        app(PayrollRunService::class)->cancelRun($run, $this->finance, 'Koreksi payroll', $accrualId);
        $this->assertSame(236500, $service->balance($this->rider->id));
        $this->assertSame(0, $service->reconciliation('2027-01-01')['difference_minor']);
        app(PayrollRunService::class)->reopenRun($run, $this->finance, $accrualId);
        app(PayrollRunService::class)->approve($run, $this->finance, $accrualId);
        $this->assertSame(236500, $run->fresh()->items()->firstOrFail()->snapshot['rider_incentive_balance_minor']);
        $this->assertSame(0, $service->balance($this->rider->id));
        $this->assertSame(0, $service->payableBalance($this->rider->id, '2026-10-31'));
        $this->assertSame(0, $service->reconciliation('2027-01-01')['difference_minor']);
    }

    public function test_manual_deduction_requires_reason_and_wrong_role_gets_403(): void
    {
        $this->lockDays();
        $service = app(RiderIncentiveBalanceService::class);
        $input = [
            'request_key' => (string) Str::uuid(), 'rider_id' => $this->rider->id,
            'entry_date' => '2026-10-12', 'amount_minor' => 50000,
            'reason' => '',
        ];
        try {
            $service->deduct($input, $this->finance);
            $this->fail('Manual deduction needs a reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
            $this->assertSame(422, $exception->status);
        }
        $outsider = User::factory()->role(Role::BARISTA)->create();
        $this->actingAs($outsider)->get(RiderIncentiveBalances::getUrl())->assertForbidden();
        Livewire::actingAs($outsider)->test(RiderIncentiveBalances::class)->assertForbidden();
        $this->actingAs($this->finance);
        $input['reason'] = 'Selisih setoran';
        $movement = $service->deduct($input, $this->finance);
        $this->assertSame(286500, $service->balance($this->rider->id));
        $this->assertSame('MANUAL_DEDUCTION', $movement->kind);
        $this->assertSame(50000, (int) DB::table('journal_lines')->where('entry_id', $movement->journal_entry_id)
            ->where('account_id', Account::query()->where('code', '4-1300')->value('id'))->sum('credit_minor'));
    }

    public function test_existing_lock_is_paid_as_legacy_expense_once(): void
    {
        $this->closing('2026-10-10', 80);
        $summary = app(RiderCommissionService::class)->day($this->rider->id, '2026-10-10');
        DB::table('rider_daily_commission_locks')->insert([
            'rider_id' => $this->rider->id, 'operating_date' => '2026-10-10',
            'cups_sold' => 80, 'version_id' => null, 'tier_min_cups' => 1,
            'amount_per_cup_minor' => 1000, 'commission_minor' => $summary['commission_minor'],
            'locked_at' => now(),
        ]);
        $this->closing('2026-10-11', 171);
        app(RiderCommissionService::class)->lockForSettlement($this->cart->id, '2026-10-11', $this->finance->id);
        $run = app(PayrollRunService::class)->create('2026-10', $this->finance);
        app(PayrollRunService::class)->approve($run, $this->finance);
        $snapshot = $run->fresh()->items()->firstOrFail()->snapshot;
        $this->assertSame(336500, $snapshot['rider_incentive_minor']);
        $this->assertSame(80000, $snapshot['rider_incentive_legacy_minor']);
        $this->assertSame(256500, $snapshot['rider_incentive_balance_minor']);
        $this->assertSame(0, app(RiderIncentiveBalanceService::class)->balance($this->rider->id));
        $this->assertSame(1, DB::table('rider_incentive_movements')->where('kind', 'PAYROLL_PAYMENT')->count());
        app(PayrollRunService::class)->approve($run, $this->finance);
        $this->assertSame(1, DB::table('rider_incentive_movements')->where('kind', 'PAYROLL_PAYMENT')->count());
    }

    public function test_kasbon_repayment_updates_both_ledgers_with_one_journal(): void
    {
        $this->lockDays();
        $advance = app(EmployeeAdvanceService::class)->create([
            'request_key' => (string) Str::uuid(), 'user_id' => $this->rider->id,
            'type_id' => DB::table('employee_advance_types')->value('id'),
            'start_date' => '2026-10-01', 'principal_minor' => 100000,
            'reason' => 'Pinjaman uji', 'installment_kind' => 'FREE',
            'installment_minor' => null, 'source_kind' => 'CASH',
            'cash_account_id' => Account::query()->where('code', '1-1100')->value('id'),
        ], $this->finance);
        $movement = app(RiderIncentiveBalanceService::class)->repayAdvance([
            'request_key' => (string) Str::uuid(), 'rider_id' => $this->rider->id,
            'entry_date' => '2026-10-12', 'amount_minor' => 50000,
            'advance_id' => $advance->id, 'reason' => 'Cicilan dari saldo',
        ], $this->finance);
        $this->assertSame(286500, app(RiderIncentiveBalanceService::class)->balance($this->rider->id));
        $this->assertSame(50000, $advance->balanceMinor());
        $this->assertDatabaseHas('employee_advance_movements', [
            'advance_id' => $advance->id, 'kind' => 'INCENTIVE_REPAYMENT',
            'amount_minor' => -50000, 'journal_entry_id' => $movement->journal_entry_id,
        ]);
        $this->assertSame(0, app(EmployeeAdvanceService::class)->reconciliation('2026-10-31')['difference_minor']);
        $this->assertSame(0, app(RiderIncentiveBalanceService::class)->reconciliation('2026-10-31')['difference_minor']);
    }

    public function test_default_tiers_start_on_first_cup_without_changing_saved_versions(): void
    {
        $page = Livewire::actingAs($this->finance)->test(TargetCommission::class);
        $this->assertSame([
            ['min_cups' => '1', 'amount_per_cup_minor' => '1000'],
            ['min_cups' => '101', 'amount_per_cup_minor' => '1500'],
        ], $page->get('tiers'));
        $page->set('selectedRiderId', (string) $this->rider->id)->call('loadRider');
        $this->assertSame(2, count($page->get('tiers')));
        $this->assertSame(2, count(app(RiderCommissionService::class)->tiersFor($this->rider->id, '2026-10-01')));
    }

    public function test_prior_month_balance_is_reserved_until_payroll_approval(): void
    {
        $this->lockDays();
        $this->travelTo(Carbon::parse('2026-11-01 12:00:00'));
        $service = app(RiderIncentiveBalanceService::class);
        $input = [
            'request_key' => (string) Str::uuid(), 'rider_id' => $this->rider->id,
            'entry_date' => '2026-11-01', 'amount_minor' => 100000,
            'cash_account_id' => Account::query()->where('code', '1-1100')->value('id'),
        ];
        try {
            $service->withdraw($input, $this->finance);
            $this->fail('Prior month incentive must remain available for payroll.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertArrayHasKey('amount_minor', $exception->errors());
        }
        $this->assertSame(336500, $service->balance($this->rider->id));
        $this->closing('2026-11-01', 20);
        app(RiderCommissionService::class)->lockForSettlement($this->cart->id, '2026-11-01', $this->finance->id);
        $service->withdraw([...$input, 'amount_minor' => 20000], $this->finance);
        $this->assertSame(336500, $service->balance($this->rider->id));
        app(PayrollRunService::class)->approve(
            app(PayrollRunService::class)->create('2026-10', $this->finance), $this->finance,
        );
        $this->assertSame(0, $service->balance($this->rider->id));
    }

    public function test_later_payroll_cannot_consume_earlier_unapproved_balance(): void
    {
        $this->lockDays();
        $service = app(PayrollRunService::class);
        $november = $service->create('2026-11', $this->finance);
        try {
            $service->approve($november, $this->finance);
            $this->fail('November must not consume October incentive before October payroll.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('run', $exception->errors());
        }
        $this->assertSame(0, DB::table('journal_entries')->where('purpose', 'PAYROLL_ACCRUAL')->count());
        $service->approve($service->create('2026-10', $this->finance), $this->finance);
        $service->approve($november, $this->finance);
        $this->assertSame(0, $november->fresh()->items()->firstOrFail()->snapshot['rider_incentive_minor']);
        $this->assertSame(0, app(RiderIncentiveBalanceService::class)->balance($this->rider->id));
    }

    public function test_earlier_incentive_run_cannot_be_canceled_after_later_approval(): void
    {
        $this->lockDays();
        $service = app(PayrollRunService::class);
        $october = $service->create('2026-10', $this->finance);
        $service->approve($october, $this->finance);
        $november = $service->create('2026-11', $this->finance);
        $service->approve($november, $this->finance);
        $octoberAccrual = DB::table('journal_entries')->where('source_type', 'payroll_run')
            ->where('source_id', $october->id)->where('purpose', 'PAYROLL_ACCRUAL')->value('id');
        try {
            $service->cancelRun($october, $this->finance, 'Koreksi periode', $octoberAccrual);
            $this->fail('Later approved payroll must be undone first.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('run', $exception->errors());
        }
        $this->assertSame('APPROVED', $october->fresh()->status);
        $this->assertSame(0, app(RiderIncentiveBalanceService::class)->balance($this->rider->id));
    }

    public function test_kasbon_incentive_repayment_correction_restores_both_balances_once(): void
    {
        $this->lockDays();
        $advance = app(EmployeeAdvanceService::class)->create([
            'request_key' => (string) Str::uuid(), 'user_id' => $this->rider->id,
            'type_id' => DB::table('employee_advance_types')->value('id'),
            'start_date' => '2026-10-01', 'principal_minor' => 100000,
            'reason' => 'Pinjaman uji', 'installment_kind' => 'FREE',
            'installment_minor' => null, 'source_kind' => 'CASH',
            'cash_account_id' => Account::query()->where('code', '1-1100')->value('id'),
        ], $this->finance);
        $balance = app(RiderIncentiveBalanceService::class);
        $repayment = $balance->repayAdvance([
            'request_key' => (string) Str::uuid(), 'rider_id' => $this->rider->id,
            'entry_date' => '2026-10-12', 'amount_minor' => 50000,
            'advance_id' => $advance->id,
        ], $this->finance);
        $input = [
            'request_key' => (string) Str::uuid(), 'corrects_movement_id' => $repayment->id,
            'entry_date' => '2026-10-13', 'reason' => 'Salah pilih kasbon',
        ];
        $correction = $balance->correct($input, $this->finance);
        $this->assertSame($correction->id, $balance->correct($input, $this->finance)->id);
        $this->assertSame(336500, $balance->balance($this->rider->id));
        $this->assertSame(100000, $advance->balanceMinor());
        $this->assertDatabaseHas('employee_advance_movements', [
            'advance_id' => $advance->id, 'kind' => 'INCENTIVE_REVERSAL',
            'amount_minor' => 50000, 'journal_entry_id' => $correction->journal_entry_id,
        ]);
        $this->assertSame(50000, (int) DB::table('journal_lines')->where('entry_id', $correction->journal_entry_id)
            ->where('account_id', Account::query()->where('code', '1-1500')->value('id'))->sum('debit_minor'));
        $this->assertSame(0, app(EmployeeAdvanceService::class)->reconciliation('2026-10-31')['difference_minor']);
        $this->assertSame(0, $balance->reconciliation('2026-10-31')['difference_minor']);
    }

    public function test_correction_restores_wrong_debit_once_and_inactive_rider_remains_visible(): void
    {
        $this->lockDays();
        $service = app(RiderIncentiveBalanceService::class);
        $movement = $service->withdraw([
            'request_key' => (string) Str::uuid(), 'rider_id' => $this->rider->id,
            'entry_date' => '2026-10-12', 'amount_minor' => 100000,
            'cash_account_id' => Account::query()->where('code', '1-1100')->value('id'),
        ], $this->finance);
        $input = [
            'request_key' => (string) Str::uuid(), 'corrects_movement_id' => $movement->id,
            'entry_date' => '2026-10-13', 'reason' => 'Uang pengambilan dikembalikan',
        ];
        $correction = $service->correct($input, $this->finance);
        $this->assertSame($correction->id, $service->correct($input, $this->finance)->id);
        $this->assertSame(336500, $service->balance($this->rider->id));
        $this->assertSame(0, $service->reconciliation('2026-10-31')['difference_minor']);
        $this->assertSame(100000, (int) DB::table('journal_lines')->where('entry_id', $correction->journal_entry_id)
            ->where('account_id', Account::query()->where('code', '2-1800')->value('id'))->sum('credit_minor'));
        try {
            $service->correct([...$input, 'request_key' => (string) Str::uuid()], $this->finance);
            $this->fail('One original debit can only be corrected once.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('corrects_movement_id', $exception->errors());
        }

        $this->rider->update(['is_active' => false]);
        $page = Livewire::actingAs($this->finance)->test(RiderIncentiveBalances::class);
        $this->assertArrayHasKey($this->rider->id, $page->instance()->riders());
        $this->assertSame(336500, collect($page->instance()->balances())->firstWhere('id', $this->rider->id)['balance_minor']);
        $service->withdraw([
            'request_key' => (string) Str::uuid(), 'rider_id' => $this->rider->id,
            'entry_date' => '2026-10-14', 'amount_minor' => 50000,
            'cash_account_id' => Account::query()->where('code', '1-1100')->value('id'),
        ], $this->finance);
        $this->assertSame(286500, $service->balance($this->rider->id));
    }
}
