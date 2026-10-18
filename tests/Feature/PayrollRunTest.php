<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exports\PayrollRunExport;
use App\Filament\Pages\PayrollConfiguration;
use App\Filament\Pages\PayrollPriorWithholding;
use App\Filament\Pages\PayrollRuns;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\PayrollComponentEntry;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\PayrollCalculator;
use App\Services\PayrollRunService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\PayrollConfigurationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame(['payroll_detail' => '[redacted]'], $audit->displayChanges('after'));
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

    public function test_excel_name_is_text_and_cms_export_requires_management(): void
    {
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
        $run = app(PayrollRunService::class)->create('2026-12', $this->manager);
        $s = $run->items()->firstOrFail()->snapshot;
        $this->assertSame(3, $s['working_months']);
        $this->assertSame(28500000, $s['annual_taxable_minor']);
        $this->assertSame(1425000, $s['annual_tax_minor']);
        $this->assertSame(425000, $s['pph21_minor']);
        $this->assertFalse($s['december_annual_reconciliation_required']);
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
        $run = app(PayrollRunService::class)->create('2026-10', $this->manager);
        $item = $run->items()->firstOrFail();
        foreach ([Role::FINANCE, Role::ADMINISTRATOR] as $role) {
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
        $run = app(PayrollRunService::class)->create('2026-10', $this->manager);
        $page = Livewire::test(PayrollRuns::class)->set('selectedRunId', $run->id);
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        $page->call('approve')->assertForbidden();
        $this->actingAs($this->manager);
        $page = Livewire::test(PayrollRuns::class)->set('selectedRunId', $run->id);
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        $page->call('markPaid')->assertForbidden();
    }
}
