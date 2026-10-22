<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\ThrManagement;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\ThrRun;
use App\Models\User;
use App\Services\JournalService;
use App\Services\PayrollRunService;
use App\Services\ThrService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ThrTest extends TestCase
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
        $this->seed(RolePermissionSeeder::class);
        $this->manager = User::factory()->role(Role::MANAGEMENT)->create();
        $this->employee = $this->employee('2026-04-01');
        $this->actingAs($this->manager);
        DB::table('payroll_settings')->insert([
            'effective_from' => '2026-01-01', 'source_note' => 'Synthetic fixture',
            'verified_by' => $this->manager->id, 'verified_at' => now(),
        ]);
        DB::table('ptkp_rates')->insert([
            'status' => 'TK/0', 'ter_category' => 'A', 'annual_amount_minor' => 0,
            'effective_from' => '2026-01-01', 'source_note' => 'Synthetic fixture',
            'verified_by' => $this->manager->id, 'verified_at' => now(),
        ]);
        DB::table('payroll_ter_versions')->insert([
            'category' => 'A', 'effective_from' => '2026-01-01', 'published_at' => now(),
            'source_note' => 'Synthetic fixture', 'verified_by' => $this->manager->id, 'verified_at' => now(),
        ]);
        DB::table('pph21_ter_brackets')->insert([
            'category' => 'A', 'lower_gross_minor' => 0, 'upper_gross_minor' => null,
            'rate_bps' => 500, 'effective_from' => '2026-01-01', 'source_note' => 'Synthetic fixture',
        ]);
    }

    private function employee(string $joined): User
    {
        $user = User::factory()->role(Role::STAFF)->create(['joined_at' => $joined]);
        $user->employeeProfile()->create([
            'base_salary_minor' => 6000000, 'ptkp_status' => 'TK/0',
            'bpjs_tk_enrolled' => false, 'bpjs_kes_enrolled' => false, 'payroll_active' => true,
        ]);

        return $user;
    }

    private function createThrRun(string $holiday, array $ids, string $key): ThrRun
    {
        return app(ThrService::class)->create($this->manager, [
            'holiday_date' => $holiday, 'planned_payment_date' => $holiday,
            'user_ids' => $ids, 'idempotency_key' => $key,
        ]);
    }

    public function test_prorata_zero_minimum_ter_versioned_settings_and_balanced_journals(): void
    {
        $newcomer = $this->employee('2026-09-20');
        $run = $this->createThrRun('2026-10-01', [$this->employee->id, $newcomer->id], 'thr-default-053');
        $this->assertSame(['due_date' => '2026-09-24', 'due' => true], app(ThrService::class)->paymentReminder($run));
        $this->assertSame($run->id, $this->createThrRun('2026-10-01', [$newcomer->id, $this->employee->id], 'thr-default-053')->id);
        $item = $run->items()->where('user_id', $this->employee->id)->firstOrFail();
        $this->assertSame(3000000, $item->snapshot['gross_minor']);
        $this->assertSame(150000, $item->snapshot['pph21_minor']);
        $this->assertSame(0, $run->items()->where('user_id', $newcomer->id)->firstOrFail()->snapshot['gross_minor']);

        app(ThrService::class)->approve($this->manager, $run);
        $run->refresh();
        $this->assertSame('APPROVED', $run->status);
        $this->assertSame(1, JournalEntry::query()->where('purpose', 'THR_ACCRUAL')->count());
        $accrual = JournalEntry::query()->where('purpose', 'THR_ACCRUAL')->firstOrFail();
        $this->assertSame($accrual->lines()->sum('debit_minor'), $accrual->lines()->sum('credit_minor'));
        try {
            app(JournalService::class)->reverse($accrual, 'Lewat jurnal umum');
            $this->fail('Akrual THR tidak boleh dibalik lewat jurnal umum.');
        } catch (ValidationException $exception) {
            $this->assertSame('APPROVED', $run->refresh()->status);
        }
        $this->assertSame(300000, app(PayrollRunService::class)->preview($this->employee, '2026-10')['pph21_minor']);
        $this->assertSame(3000000, app(PayrollRunService::class)->preview($this->employee, '2026-10')['thr_included_minor']);
        $item = $run->items()->where('user_id', $this->employee->id)->firstOrFail();
        Storage::disk('local')->assertExists($item->slip_path);
        $this->get(route('admin.thr-slips.download', $item))->assertOk();

        $cashId = Account::query()->where('code', '1-1300')->value('id');
        app(ThrService::class)->pay($this->manager, $run, '2026-10-02', $cashId);
        app(ThrService::class)->pay($this->manager, $run, '2026-10-02', $cashId);
        $this->assertFalse(app(ThrService::class)->paymentReminder($run->refresh())['due']);
        $this->assertSame(1, JournalEntry::query()->where('purpose', 'THR_PAYMENT')->count());
        $this->assertSame(0, (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', '2-1710')->selectRaw('SUM(CAST(l.credit_minor AS SIGNED) - CAST(l.debit_minor AS SIGNED)) AS balance')->value('balance'));

        $newSettings = [
            'effective_from' => '2026-11-01', 'component_type_ids' => [],
            'minimum_months' => 1, 'full_months' => 6, 'prorata_denominator' => 6,
            'reminder_days' => 3, 'reserve_enabled' => false, 'reason' => 'Aturan THR baru untuk uji',
        ];
        $version = app(ThrService::class)->saveSettings($this->manager, $newSettings);
        $this->assertSame($version->id, app(ThrService::class)->saveSettings($this->manager, $newSettings)->id);
        $next = $this->createThrRun('2026-11-01', [$this->employee->id], 'thr-next-053');
        $this->assertSame(6000000, $next->items()->firstOrFail()->snapshot['gross_minor']);
        $this->assertNotNull($next->setting_version_id);
    }

    public function test_optional_provision_is_used_before_new_thr_expense(): void
    {
        app(ThrService::class)->saveSettings($this->manager, [
            'effective_from' => '2026-09-01', 'component_type_ids' => [],
            'minimum_months' => 1, 'full_months' => 12, 'prorata_denominator' => 12,
            'reminder_days' => 7, 'reserve_enabled' => true, 'reason' => 'Aktifkan cadangan THR untuk uji',
        ]);
        $this->assertSame(500000, app(ThrService::class)->provision($this->manager, '2026-09'));
        $this->assertSame(500000, app(ThrService::class)->provision($this->manager, '2026-09'));
        try {
            app(JournalService::class)->reverse(
                JournalEntry::query()->where('purpose', 'THR_PROVISION')->firstOrFail(), 'Lewat jurnal umum');
            $this->fail('Cadangan THR tidak boleh dibalik lewat jurnal umum.');
        } catch (ValidationException $exception) {
            $this->assertSame(1, JournalEntry::query()->where('purpose', 'THR_PROVISION')->count());
        }
        $run = $this->createThrRun('2026-10-01', [$this->employee->id], 'thr-reserve-053');
        app(ThrService::class)->approve($this->manager, $run);
        $accrual = JournalEntry::query()->where('source_type', 'thr_run')->firstOrFail();
        $debits = $accrual->lines()->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->pluck('journal_lines.debit_minor', 'accounts.code');
        $this->assertSame(500000, (int) $debits['2-1700']);
        $this->assertSame(2500000, (int) $debits['6-2200']);
    }

    public function test_december_thr_uses_annual_pasal_17_increment_instead_of_monthly_ter(): void
    {
        $versionId = DB::table('payroll_annual_tax_versions')->insertGetId([
            'effective_from' => '2026-01-01', 'source_note' => 'Synthetic annual fixture',
            'verified_by' => $this->manager->id, 'verified_at' => now(),
        ]);
        DB::table('payroll_annual_tax_brackets')->insert([
            'version_id' => $versionId, 'lower_taxable_minor' => 0,
            'upper_taxable_minor' => null, 'rate_bps' => 500,
        ]);
        foreach (range(4, 11) as $month) {
            $run = PayrollRun::query()->create([
                'period_start' => sprintf('2026-%02d-01', $month),
                'status' => 'APPROVED', 'created_by' => $this->manager->id,
            ]);
            PayrollItem::query()->create([
                'run_id' => $run->id, 'user_id' => $this->employee->id,
                'snapshot' => [
                    'taxable_gross_minor' => 6000000, 'gross_pay_minor' => 6000000,
                    'pph21_minor' => 300000,
                    'bpjs_employee_minor' => ['jht' => 0, 'jp' => 0],
                ],
            ]);
        }
        $run = $this->createThrRun('2026-12-01', [$this->employee->id], 'thr-december-053');
        $item = $run->items()->firstOrFail();
        $this->assertSame(4000000, $item->snapshot['gross_minor']);
        $this->assertSame(190000, $item->snapshot['pph21_minor']);
    }

    public function test_wrong_role_gets_403_on_thr_page_and_private_slip(): void
    {
        $run = $this->createThrRun('2026-10-01', [$this->employee->id], 'thr-private-053');
        app(ThrService::class)->approve($this->manager, $run);
        $item = $run->items()->firstOrFail();
        $this->actingAs(User::factory()->role(Role::STAFF)->create());
        $this->get(ThrManagement::getUrl())->assertForbidden();
        $this->get(route('admin.thr-slips.download', $item))->assertForbidden();
    }
}
