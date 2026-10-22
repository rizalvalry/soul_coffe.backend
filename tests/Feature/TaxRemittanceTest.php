<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Exports\PayrollWithholdingExport;
use App\Filament\Pages\PayrollRemittances;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\JournalService;
use App\Services\PayrollRemittanceService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaxRemittanceTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->manager = User::factory()->role(Role::MANAGEMENT)->create();
        $employee = User::factory()->role(Role::STAFF)->create(['national_id' => '0001234567890123']);
        $employee->employeeProfile()->create([
            'base_salary_minor' => 6000000, 'ptkp_status' => 'TK/0', 'npwp' => '001234567890123',
            'bpjs_tk_enrolled' => true, 'bpjs_kes_enrolled' => true, 'payroll_active' => true,
        ]);
        $run = PayrollRun::query()->create([
            'period_start' => '2026-09-01', 'status' => 'APPROVED', 'created_by' => $this->manager->id,
        ]);
        PayrollItem::query()->create([
            'run_id' => $run->id, 'user_id' => $employee->id,
            'snapshot' => [
                'gross_pay_minor' => 6000000, 'taxable_gross_minor' => 6000000,
                'ptkp_status' => 'TK/0', 'pph21_minor' => 10000,
                'bpjs_employee_minor' => ['jht' => 1000, 'jp' => 0, 'jkk' => 0, 'jkm' => 0, 'health' => 1000],
                'bpjs_employer_minor' => ['jht' => 2000, 'jp' => 0, 'jkk' => 0, 'jkm' => 0, 'health' => 1000],
            ],
        ]);
        $this->actingAs($this->manager);
        $accounts = Account::query()->whereIn('code', ['6-1200', '2-1200', '2-1300', '2-1100'])->pluck('id', 'code');
        app(JournalService::class)->post([
            'entry_date' => '2026-09-30', 'description' => 'Akrual payroll uji',
            'source_type' => 'payroll_run', 'source_id' => $run->id,
            'purpose' => 'PAYROLL_ACCRUAL', 'posted_by' => $this->manager->id,
        ], [
            ['account_id' => $accounts['6-1200'], 'debit_minor' => 6000000, 'credit_minor' => 0],
            ['account_id' => $accounts['2-1200'], 'debit_minor' => 0, 'credit_minor' => 5000],
            ['account_id' => $accounts['2-1300'], 'debit_minor' => 0, 'credit_minor' => 10000],
            ['account_id' => $accounts['2-1100'], 'debit_minor' => 0, 'credit_minor' => 5985000],
        ]);
    }

    public function test_pph21_and_bpjs_per_program_and_combined_close_monthly_liabilities_and_void_reverses(): void
    {
        $service = app(PayrollRemittanceService::class);
        $this->assertSame(10000, $service->status($this->manager, '2026-09', 'PPH21')['difference_minor']);
        $this->assertSame(5000, $service->status($this->manager, '2026-09', 'BPJS')['difference_minor']);
        $cash = Account::query()->where('code', '1-1300')->firstOrFail();
        $input = [
            'month' => '2026-09', 'kind' => 'PPH21', 'program' => null,
            'paid_on' => '2026-10-10', 'amount_minor' => 10000,
            'receipt_number' => 'NTPN-053', 'account_id' => $cash->id,
            'idempotency_key' => 'pph21-053',
        ];
        $pph = $service->remit($this->manager, $input);
        $this->assertSame($pph->id, $service->remit($this->manager, $input)->id);
        $entry = JournalEntry::query()->where('source_type', 'payroll_remittance')->where('source_id', $pph->id)->firstOrFail();
        try {
            app(JournalService::class)->reverse($entry, 'Lewat jurnal umum');
            $this->fail('Setoran tidak boleh dibalik lewat jurnal umum.');
        } catch (ValidationException $exception) {
            $this->assertSame('POSTED', $pph->refresh()->status);
        }
        $this->assertSame('SUDAH_DISETOR', $service->status($this->manager, '2026-09', 'PPH21')['status']);
        $this->assertSame(0, $this->liability('2-1300'));

        $jht = $service->remit($this->manager, [...$input,
            'kind' => 'BPJS', 'program' => 'jht', 'amount_minor' => 3000,
            'receipt_number' => 'BPJS-JHT-053', 'idempotency_key' => 'bpjs-jht-053']);
        $combined = $service->remit($this->manager, [...$input,
            'kind' => 'BPJS', 'program' => null, 'amount_minor' => 2000,
            'receipt_number' => 'BPJS-GAB-053', 'idempotency_key' => 'bpjs-gab-053']);
        $this->assertSame('SUDAH_DISETOR', $service->status($this->manager, '2026-09', 'BPJS')['status']);
        $this->assertSame('SUDAH_DISETOR', $service->status($this->manager, '2026-09', 'BPJS', 'jht')['status']);
        $this->assertSame('SUDAH_DISETOR', $service->status($this->manager, '2026-09', 'BPJS', 'health')['status']);
        $this->assertSame(0, $this->liability('2-1200'));
        $this->assertSame(3, JournalEntry::query()->where('source_type', 'payroll_remittance')->count());
        $service->void($this->manager, $combined, 'Bukti setor salah');
        $service->void($this->manager, $combined, 'Bukti setor salah');
        $this->assertSame(2000, $service->status($this->manager, '2026-09', 'BPJS')['difference_minor']);
        $this->assertSame(2000, $this->liability('2-1200'));
        $service->void($this->manager, $jht, 'Bukti setor salah');
        $service->void($this->manager, $pph, 'Bukti setor salah');
        $this->assertSame(10000, $this->liability('2-1300'));
    }

    public function test_withholding_recap_decrypts_identity_only_for_authorized_user(): void
    {
        $monthly = (new PayrollWithholdingExport('2026-09'))->collection();
        $this->assertCount(1, $monthly);
        $this->assertSame('0001234567890123', $monthly[0][3]);
        $this->assertSame('001234567890123', $monthly[0][4]);
        $this->assertSame(6000000, $monthly[0][6]);
        $this->assertSame(10000, $monthly[0][7]);
        $this->assertCount(2, (new PayrollWithholdingExport('2026', true))->collection());
        $this->get(PayrollRemittances::getUrl())->assertOk();

        $this->actingAs(User::factory()->role(Role::STAFF)->create());
        $this->get(PayrollRemittances::getUrl())->assertForbidden();
    }

    public function test_partial_combined_bpjs_receipt_does_not_claim_an_unsupported_program_allocation(): void
    {
        $service = app(PayrollRemittanceService::class);
        $service->remit($this->manager, [
            'month' => '2026-09', 'kind' => 'BPJS', 'program' => null,
            'paid_on' => '2026-10-10', 'amount_minor' => 1000,
            'receipt_number' => 'BPJS-PARSIAL-053',
            'account_id' => Account::query()->where('code', '1-1300')->value('id'),
            'idempotency_key' => 'bpjs-parsial-053',
        ]);
        $this->assertSame(4000, $service->status($this->manager, '2026-09', 'BPJS')['difference_minor']);
        $program = $service->status($this->manager, '2026-09', 'BPJS', 'health');
        $this->assertSame('GABUNGAN_BELUM_RINCI', $program['status']);
        $this->assertSame(1000, $program['unallocated_minor']);
        $this->assertSame(0, $program['paid_minor']);
    }

    private function liability(string $code): int
    {
        return (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', $code)
            ->selectRaw('SUM(CAST(l.credit_minor AS SIGNED) - CAST(l.debit_minor AS SIGNED)) AS balance')->value('balance');
    }
}
