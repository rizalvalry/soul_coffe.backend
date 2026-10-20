<?php

namespace Tests\Feature;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\TaxSettings;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CashAccount;
use App\Models\CentralKitchen;
use App\Models\JournalEntry;
use App\Models\RiderClosing;
use App\Models\TaxRemittance;
use App\Models\TaxSettingVersion;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\JournalService;
use App\Services\TaxService;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class TaxTest extends TestCase
{
    use RefreshDatabase;

    private User $management;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountSeeder::class);
        PermissionMatrix::forget();
        PermissionMatrix::set(Role::MANAGEMENT, PanelModule::ATTENDANCE, ['view']);
        $this->management = User::factory()->role(Role::MANAGEMENT)->create();
        $this->actingAs($this->management);
    }

    private function version(string $type, int $rate, string $date, array $overrides = []): TaxSettingVersion
    {
        return app(TaxService::class)->saveSetting($this->management, [
            'tax_type' => $type, 'is_active' => true, 'rate_bps' => $rate,
            'price_mode' => $type === 'PBJT' ? 'INCLUDED' : null,
            'scheme' => $type === 'PPH' ? 'FINAL' : null,
            'effective_from' => $date, 'effective_until' => null,
            'legal_note' => 'Diuji Management', 'monthly_threshold_minor' => null,
            'reason' => 'Tarif uji baru', ...$overrides,
        ]);
    }

    public function test_default_off_rounding_and_immutable_versions_use_operating_date(): void
    {
        $tax = app(TaxService::class);
        $today = today()->toDateString();
        $tomorrow = today()->addDay()->toDateString();
        $this->assertSame(['revenue' => 110000, 'tax' => 0], $tax->splitPbjt(110000, $today));

        $first = $this->version('PBJT', 1000, $today);
        $second = $this->version('PBJT', 1100, $tomorrow);
        $this->assertSame(['revenue' => 100000, 'tax' => 10000], $tax->splitPbjt(110000, $today));
        $this->assertSame(['revenue' => 13636, 'tax' => 1364], $tax->splitPbjt(15000, $today));
        $this->assertSame(1000, $first->fresh()->rate_bps);
        $this->assertSame(1100, $tax->settingAt('PBJT', $tomorrow)->rate_bps);
        $this->assertSame(2, TaxSettingVersion::query()->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'tax.setting_changed')->count());
        $this->assertSame($first->id, $tax->settingAt('PBJT', $today)->id);
        $this->assertSame($second->id, $tax->settingAt('PBJT', $tomorrow)->id);
    }

    public function test_future_schedule_allows_an_earlier_distinct_change_without_rewriting_versions(): void
    {
        $future = today()->addMonth()->toDateString();
        $tomorrow = today()->addDay()->toDateString();
        $scheduled = $this->version('PBJT', 1200, $future);
        $urgent = $this->version('PBJT', 1000, $tomorrow);
        $this->assertSame($urgent->id, app(TaxService::class)->settingAt('PBJT', $tomorrow)->id);
        $this->assertSame($scheduled->id, app(TaxService::class)->settingAt('PBJT', $future)->id);
        $this->assertSame(2, AuditLog::query()->where('action', 'tax.setting_changed')->count());
    }

    public function test_same_day_setting_change_is_rejected_after_a_sale_journal(): void
    {
        $codes = Account::query()->whereIn('code', ['1-1100', '4-1200'])->pluck('id', 'code');
        app(JournalService::class)->post([
            'entry_date' => today()->toDateString(), 'description' => 'Penjualan sebelum aktivasi',
            'source_type' => 'direct_sale', 'source_id' => 991053,
            'purpose' => 'DIRECT_SALE', 'posted_by' => $this->management->id,
        ], [
            ['account_id' => $codes['1-1100'], 'debit_minor' => 110000, 'credit_minor' => 0],
            ['account_id' => $codes['4-1200'], 'debit_minor' => 0, 'credit_minor' => 110000],
        ]);
        foreach (['PBJT', 'PPH'] as $type) {
            try {
                $this->version($type, 1000, today()->toDateString());
                $this->fail('Aktivasi setelah jurnal hari ini diterima.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('effective_from', $exception->errors());
            }
        }
        $this->assertSame(0, TaxSettingVersion::query()->count());
    }

    public function test_monthly_liabilities_final_pph_remittance_retry_and_void_match_journals(): void
    {
        $today = today()->toDateString();
        $month = today()->format('Y-m');
        $tax = app(TaxService::class);
        $this->version('PBJT', 1000, $today, ['monthly_threshold_minor' => 42000000]);
        $this->version('PPH', 500, $today);
        $accounts = Account::query()->whereIn('code', ['1-1100', '4-1100', '2-1500', '2-1600'])->pluck('id', 'code');
        app(JournalService::class)->post([
            'entry_date' => $today, 'description' => 'Penjualan uji',
            'source_type' => 'settlement', 'source_id' => 991051,
            'purpose' => 'SETTLEMENT', 'posted_by' => $this->management->id,
        ], [
            ['account_id' => $accounts['1-1100'], 'debit_minor' => 110000, 'credit_minor' => 0],
            ['account_id' => $accounts['4-1100'], 'debit_minor' => 0, 'credit_minor' => 100000],
            ['account_id' => $accounts['2-1500'], 'debit_minor' => 0, 'credit_minor' => 10000],
        ]);
        $pbjt = $tax->monthlyReport('PBJT', $month);
        $this->assertSame(110000, $pbjt['gross_minor']);
        $this->assertSame(100000, $pbjt['base_minor']);
        $this->assertSame(10000, $pbjt['due_minor']);
        $this->assertSame(42000000, $pbjt['threshold_minor']);

        $this->assertSame(5000, $tax->monthlyReport('PPH', $month)['estimate_minor']);
        $first = $tax->postFinalPph($this->management, $month);
        $this->assertSame($first->id, $tax->postFinalPph($this->management, $month)->id);
        $this->assertSame(1, AuditLog::query()->where('action', 'tax.final_recorded')->count());
        $this->assertSame(5000, $tax->monthlyReport('PPH', $month)['due_minor']);

        $lateSale = app(JournalService::class)->post([
            'entry_date' => $today, 'description' => 'Penjualan terlambat',
            'source_type' => 'settlement', 'source_id' => 991054,
            'purpose' => 'SETTLEMENT', 'posted_by' => $this->management->id,
        ], [
            ['account_id' => $accounts['1-1100'], 'debit_minor' => 100000, 'credit_minor' => 0],
            ['account_id' => $accounts['4-1100'], 'debit_minor' => 0, 'credit_minor' => 100000],
        ]);
        $adjustment = $tax->postFinalPph($this->management, $month);
        $this->assertSame('tax_final_adjustment', $adjustment->source_type);
        $this->assertSame($adjustment->id, $tax->postFinalPph($this->management, $month)->id);
        $this->assertSame(10000, $tax->monthlyReport('PPH', $month)['due_minor']);
        app(JournalService::class)->reverse($lateSale, 'Penjualan dibatalkan');
        $negative = $tax->postFinalPph($this->management, $month);
        $this->assertSame(5000, (int) $negative->lines()->sum('credit_minor'));
        $this->assertSame(5000, $tax->monthlyReport('PPH', $month)['due_minor']);

        $cash = CashAccount::query()->where('account_id', $accounts['1-1100'])->firstOrFail();
        $input = [
            'tax_type' => 'PBJT', 'paid_on' => $today, 'amount_minor' => 10000,
            'receipt_number' => 'BKT-051', 'cash_account_id' => $cash->id,
            'idempotency_key' => 'tax-remittance-051',
        ];
        $remittance = $tax->remit($this->management, $input);
        $this->assertSame($remittance->id, $tax->remit($this->management, $input)->id);
        $this->assertSame(1, TaxRemittance::query()->count());
        $this->assertSame(10000, $tax->monthlyReport('PBJT', $month)['paid_minor']);
        $tax->voidRemittance($this->management, $remittance, 'Bukti setor keliru');
        $this->assertSame(0, $tax->monthlyReport('PBJT', $month)['paid_minor']);
        $this->assertSame(10000, $tax->monthlyReport('PBJT', $month)['due_minor']);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'tax_remittance')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'tax.remittance_voided')->count());
    }

    public function test_partial_month_final_and_general_versions_keep_separate_estimates(): void
    {
        $today = today()->toDateString();
        $tomorrow = today()->addDay()->toDateString();
        $this->version('PPH', 500, $today, ['effective_until' => $today]);
        $this->version('PPH', 2000, $tomorrow, ['scheme' => 'GENERAL']);
        $accounts = Account::query()->whereIn('code', ['1-1100', '4-1100', '5-1100', '1-1600'])->pluck('id', 'code');
        foreach ([$today, $tomorrow] as $index => $date) {
            app(JournalService::class)->post([
                'entry_date' => $date, 'description' => 'Penjualan campuran',
                'source_type' => 'settlement', 'source_id' => 991060 + $index,
                'purpose' => 'SETTLEMENT', 'posted_by' => $this->management->id,
            ], [
                ['account_id' => $accounts['1-1100'], 'debit_minor' => 100000, 'credit_minor' => 0],
                ['account_id' => $accounts['4-1100'], 'debit_minor' => 0, 'credit_minor' => 100000],
            ]);
        }
        app(JournalService::class)->post([
            'entry_date' => $tomorrow, 'description' => 'HPP tarif umum',
            'source_type' => 'settlement', 'source_id' => 991061,
            'purpose' => 'SETTLEMENT_COGS', 'posted_by' => $this->management->id,
        ], [
            ['account_id' => $accounts['5-1100'], 'debit_minor' => 20000, 'credit_minor' => 0],
            ['account_id' => $accounts['1-1600'], 'debit_minor' => 0, 'credit_minor' => 20000],
        ]);
        $month = today()->format('Y-m');
        $report = app(TaxService::class)->monthlyReport('PPH', $month);
        $this->assertSame(5000, $report['final_estimate_minor']);
        $this->assertSame(16000, $report['general_estimate_minor']);
        $this->assertSame(21000, $report['estimate_minor']);
        app(TaxService::class)->postFinalPph($this->management, $month);
        $this->assertSame(5000, app(TaxService::class)->monthlyReport('PPH', $month)['due_minor']);
    }

    public function test_next_month_reversal_and_closing_correction_keep_original_operating_date_rate(): void
    {
        $operatingDate = today()->toDateString();
        $nextMonth = today()->addMonthNoOverflow()->startOfMonth()->toDateString();
        $this->version('PPH', 500, $operatingDate);
        $this->version('PPH', 1000, $nextMonth);
        $accounts = Account::query()->whereIn('code', ['1-1100', '4-1100', '6-1100'])->pluck('id', 'code');
        $original = app(JournalService::class)->post([
            'entry_date' => $operatingDate, 'description' => 'Penjualan sebelum koreksi',
            'source_type' => 'direct_sale', 'source_id' => 991080,
            'purpose' => 'DIRECT_SALE', 'posted_by' => $this->management->id,
        ], [
            ['account_id' => $accounts['1-1100'], 'debit_minor' => 100000, 'credit_minor' => 0],
            ['account_id' => $accounts['4-1100'], 'debit_minor' => 0, 'credit_minor' => 100000],
        ]);
        app(TaxService::class)->postFinalPph($this->management, today()->format('Y-m'));

        $kitchen = CentralKitchen::query()->create([
            'name' => 'Dapur Pajak', 'address' => 'Jalan Uji', 'open_at' => '05:00', 'close_at' => '20:00',
        ]);
        $cart = Cart::query()->create(['code' => 'TAX-051', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $closing = RiderClosing::query()->create([
            'operating_date' => $operatingDate, 'cart_id' => $cart->id, 'rider_id' => $this->management->id,
            'total_minor' => 20000, 'cogs_minor' => 0, 'qris_minor' => 0, 'transfer_minor' => 0,
            'cash_minor' => 20000, 'closed_at' => now(),
        ]);
        $revisionId = DB::table('rider_closing_revisions')->insertGetId([
            'rider_closing_id' => $closing->id, 'actor_id' => $this->management->id,
            'reason' => 'Koreksi tanggal lama', 'snapshot_json' => '{}', 'created_at' => now(),
        ]);
        $this->travelTo(today()->addMonthNoOverflow()->startOfMonth());
        app(JournalService::class)->post([
            'entry_date' => $nextMonth, 'description' => 'Koreksi Closing bulan lalu',
            'source_type' => 'rider_closing_revision', 'source_id' => $revisionId,
            'purpose' => 'SETTLEMENT_CORRECTION', 'posted_by' => $this->management->id,
        ], [
            ['account_id' => $accounts['6-1100'], 'debit_minor' => 20000, 'credit_minor' => 0],
            ['account_id' => $accounts['4-1100'], 'debit_minor' => 0, 'credit_minor' => 20000],
        ]);
        app(JournalService::class)->reverse($original, 'Transaksi dibatalkan bulan berikutnya');

        $month = today()->format('Y-m');
        $report = app(TaxService::class)->monthlyReport('PPH', $month);
        $this->assertSame(-80000, $report['base_minor']);
        $this->assertSame(-4000, $report['final_estimate_minor']);
        app(TaxService::class)->postFinalPph($this->management, $month);
        $this->assertSame(-4000, app(TaxService::class)->monthlyReport('PPH', $month)['due_minor']);
    }

    public function test_final_accrual_can_be_adjusted_down_to_zero_after_reversal(): void
    {
        $today = today()->toDateString();
        $month = today()->format('Y-m');
        $this->version('PPH', 500, $today);
        $accounts = Account::query()->whereIn('code', ['1-1100', '4-1100'])->pluck('id', 'code');
        $sale = app(JournalService::class)->post([
            'entry_date' => $today, 'description' => 'Penjualan uji',
            'source_type' => 'direct_sale', 'source_id' => 991081,
            'purpose' => 'DIRECT_SALE', 'posted_by' => $this->management->id,
        ], [
            ['account_id' => $accounts['1-1100'], 'debit_minor' => 100000, 'credit_minor' => 0],
            ['account_id' => $accounts['4-1100'], 'debit_minor' => 0, 'credit_minor' => 100000],
        ]);
        app(TaxService::class)->postFinalPph($this->management, $month);
        app(JournalService::class)->reverse($sale, 'Penjualan batal');
        $this->get(TaxSettings::getUrl())->assertOk()->assertSee('Sesuaikan utang PPh final bulan ini');
        Livewire::test(TaxSettings::class)->call('recordFinalPph')->assertHasNoErrors();
        $this->assertSame(0, app(TaxService::class)->monthlyReport('PPH', $month)['due_minor']);
    }

    public function test_manual_tax_liability_correction_appears_in_monthly_report(): void
    {
        $accounts = Account::query()->whereIn('code', ['2-1500', '2-1600', '6-1800'])->pluck('id', 'code');
        foreach (['2-1500', '2-1600'] as $index => $liability) {
            app(JournalService::class)->post([
                'entry_date' => today()->toDateString(), 'description' => 'Koreksi pajak manual',
                'source_type' => 'manual', 'source_id' => 991070 + $index,
                'purpose' => 'MANUAL', 'posted_by' => $this->management->id,
            ], [
                ['account_id' => $accounts['6-1800'], 'debit_minor' => 2000, 'credit_minor' => 0],
                ['account_id' => $accounts[$liability], 'debit_minor' => 0, 'credit_minor' => 2000],
            ]);
        }
        $month = today()->format('Y-m');
        $this->assertSame(2000, app(TaxService::class)->monthlyReport('PBJT', $month)['due_minor']);
        $this->assertSame(2000, app(TaxService::class)->monthlyReport('PPH', $month)['due_minor']);
        $this->assertSame(0, app(TaxService::class)->monthlyReport('PBJT', $month)['gross_minor']);
    }

    public function test_pbjt_monthly_threshold_remains_visible_after_version_expires(): void
    {
        $today = today()->toDateString();
        $this->version('PBJT', 1000, $today, [
            'effective_until' => $today, 'monthly_threshold_minor' => 42000000,
        ]);
        $report = app(TaxService::class)->monthlyReport('PBJT', today()->format('Y-m'));
        $this->assertNull($report['setting']);
        $this->assertSame(42000000, $report['threshold_minor']);
        $this->assertSame($today, $report['threshold_version_from']);
    }

    public function test_only_management_can_open_page_and_use_tax_actions(): void
    {
        $this->get(TaxSettings::getUrl())->assertOk()->assertSee('Pajak Restoran (PBJT)');
        Livewire::test(TaxSettings::class)->call('openPbjtForm')->assertSet('showPbjtForm', true);
        foreach ([Role::ADMINISTRATOR, Role::FINANCE, Role::BARISTA, Role::RIDER, Role::STAFF, Role::CONTENT_CREATOR] as $role) {
            $component = Livewire::test(TaxSettings::class);
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->get(TaxSettings::getUrl())->assertForbidden();
            $component->call('savePbjt')->assertForbidden();
            $this->actingAs($this->management);
        }
        $finance = User::factory()->role(Role::FINANCE)->create();
        foreach (['savePph', 'recordFinalPph', 'saveRemittance', 'voidRemittance', 'exportExcel', 'exportPdf'] as $action) {
            $component = Livewire::test(TaxSettings::class);
            $this->actingAs($finance);
            $component->call($action, ...($action === 'exportExcel' || $action === 'exportPdf' ? ['PBJT'] : []))
                ->assertForbidden();
            $this->actingAs($this->management);
        }
        $inactive = User::factory()->role(Role::MANAGEMENT)->create(['is_active' => false]);
        $this->actingAs($inactive)->get(TaxSettings::getUrl())->assertForbidden();
    }

    public function test_management_page_saves_percent_as_basis_points_and_exports_reports(): void
    {
        Livewire::test(TaxSettings::class)
            ->call('openPbjtForm')
            ->set('pbjtInput.is_active', true)
            ->set('pbjtInput.rate_percent', '10.5')
            ->set('pbjtInput.effective_from', today()->toDateString())
            ->set('pbjtInput.reason', 'Tarif Management baru')
            ->call('savePbjt')->assertHasNoErrors();
        $this->assertDatabaseHas('tax_setting_versions', ['tax_type' => 'PBJT', 'rate_bps' => 1050, 'is_active' => 1]);
        $this->assertSame(1, AuditLog::query()->where('action', 'tax.setting_changed')->count());

        Livewire::test(TaxSettings::class)
            ->set('pbjtInput.monthly_threshold_minor', 'bukan angka')
            ->set('pbjtInput.effective_from', today()->addDay()->toDateString())
            ->set('pbjtInput.reason', 'Perubahan ambang uji')
            ->call('savePbjt')->assertHasErrors();
        $this->assertSame(1, TaxSettingVersion::query()->count());

        Livewire::test(TaxSettings::class)->call('exportExcel', 'PBJT')
            ->assertFileDownloaded('laporan-pbjt-'.today()->format('Y-m').'.xlsx');
        Livewire::test(TaxSettings::class)->call('exportPdf', 'PBJT')
            ->assertFileDownloaded('laporan-pbjt-'.today()->format('Y-m').'.pdf');
    }

    public function test_general_pph_is_only_an_estimate_without_automatic_journal(): void
    {
        $today = today()->toDateString();
        $this->version('PPH', 2000, $today, ['scheme' => 'GENERAL']);
        $accounts = Account::query()->whereIn('code', ['1-1100', '4-1100', '5-1100', '1-1600'])->pluck('id', 'code');
        app(JournalService::class)->post([
            'entry_date' => $today, 'description' => 'Penjualan uji',
            'source_type' => 'settlement', 'source_id' => 991052,
            'purpose' => 'SETTLEMENT', 'posted_by' => $this->management->id,
        ], [
            ['account_id' => $accounts['1-1100'], 'debit_minor' => 100000, 'credit_minor' => 0],
            ['account_id' => $accounts['4-1100'], 'debit_minor' => 0, 'credit_minor' => 100000],
        ]);
        app(JournalService::class)->post([
            'entry_date' => $today, 'description' => 'HPP uji',
            'source_type' => 'settlement', 'source_id' => 991052,
            'purpose' => 'SETTLEMENT_COGS', 'posted_by' => $this->management->id,
        ], [
            ['account_id' => $accounts['5-1100'], 'debit_minor' => 20000, 'credit_minor' => 0],
            ['account_id' => $accounts['1-1600'], 'debit_minor' => 0, 'credit_minor' => 20000],
        ]);
        $report = app(TaxService::class)->monthlyReport('PPH', today()->format('Y-m'));
        $this->assertSame(80000, $report['profit_minor']);
        $this->assertSame(16000, $report['estimate_minor']);
        $this->assertSame(0, $report['due_minor']);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'tax_final')->count());
    }
}
