<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exports\PayrollRunExport;
use App\Filament\Pages\PayrollConfiguration;
use App\Filament\Pages\PayrollPriorWithholding;
use App\Filament\Pages\PayrollRuns;
use App\Filament\Resources\Journals\Pages\ListJournals;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\PayrollComponentEntry;
use App\Models\PayrollPriorWithholding as PayrollPriorWithholdingRecord;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\JournalService;
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
