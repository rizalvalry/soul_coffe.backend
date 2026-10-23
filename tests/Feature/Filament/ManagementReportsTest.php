<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\RefillStatus;
use App\Enums\Role;
use App\Filament\Pages\ManagementReports;
use App\Models\Account;
use App\Models\Cart;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashTransaction;
use App\Models\CentralKitchen;
use App\Models\JournalEntry;
use App\Models\Media;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\RefillRequest;
use App\Models\Settlement;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\JournalService;
use App\Services\Reporting\ManagementReportService;
use Database\Seeders\AccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class ManagementReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $management;

    private User $rider;

    private Cart $cart;

    private Cart $secondCart;

    private Product $coffee;

    private Product $tea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountSeeder::class);
        PermissionMatrix::forget();
        PermissionMatrix::set(Role::MANAGEMENT, PanelModule::ATTENDANCE, ['view']);
        $this->management = User::factory()->role(Role::MANAGEMENT)->create();
        $this->rider = User::factory()->role(Role::STAFF)->create();
        $kitchen = CentralKitchen::query()->create([
            'name' => 'Dapur Uji', 'address' => 'Jalan Uji', 'open_at' => '05:00', 'close_at' => '20:00',
        ]);
        $this->cart = Cart::query()->create(['code' => 'C01', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $this->secondCart = Cart::query()->create(['code' => 'C02', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $this->coffee = Product::query()->create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup', 'is_sellable' => true, 'sort_order' => 1,
        ]);
        $this->tea = Product::query()->create([
            'code' => 'TEH', 'name' => 'Teh', 'unit' => 'cup', 'is_sellable' => true, 'sort_order' => 2,
        ]);
        $this->actingAs($this->management);
    }

    public function test_only_management_can_open_page_and_download_reports(): void
    {
        $this->get(ManagementReports::getUrl())->assertOk()->assertSee('Laporan Management');
        Livewire::test(ManagementReports::class)->call('exportExcel', 'ringkasan')
            ->assertFileDownloaded('laporan-management-ringkasan-'.today()->toDateString().'-'.today()->toDateString().'.xlsx');
        Livewire::test(ManagementReports::class)->call('exportPdf', 'ringkasan')
            ->assertFileDownloaded('laporan-management-ringkasan-'.today()->toDateString().'-'.today()->toDateString().'.pdf');

        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create())
            ->get(ManagementReports::getUrl())->assertOk();
        $this->actingAs(User::factory()->role(Role::FINANCE)->create())
            ->get(ManagementReports::getUrl())->assertOk();
        foreach ([Role::BARISTA, Role::RIDER, Role::STAFF, Role::CONTENT_CREATOR] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get(ManagementReports::getUrl())->assertForbidden();
            $this->assertFalse(ManagementReports::canAccess());
        }
        $inactive = User::factory()->role(Role::MANAGEMENT)->create(['is_active' => false]);
        $this->actingAs($inactive)->get(ManagementReports::getUrl())->assertForbidden();
        $this->assertFalse(ManagementReports::canAccess());
    }

    public function test_journal_totals_snapshot_margins_voids_and_operational_breakdown(): void
    {
        $this->postJournal('2026-10-12', '1-1200', '4-1100', 13000, 1);
        $this->postJournal('2026-10-12', '5-1100', '1-1600', 5000, 2);
        $this->postJournal('2026-10-13', '1-1100', '4-1200', 5000, 3);
        $this->postJournal('2026-10-13', '5-1100', '1-1600', 2000, 4);
        $this->postJournal('2026-10-13', '6-1400', '1-1100', 1000, 5);
        $this->postJournal('2026-10-13', '6-1200', '2-1100', 3000, 6);

        $this->sale($this->cart, $this->coffee, '2026-10-12', 2, 10000, 2000);
        $this->sale($this->secondCart, $this->tea, '2026-10-12', 1, 3000, 1000);
        $this->sale($this->cart, $this->coffee, '2026-10-12', 1, 99000, 100, true);
        $this->directSale($this->coffee, '2026-10-13', 5000, 2000);
        $this->directSale($this->tea, '2026-10-13', 99999, 100, true);

        Settlement::query()->create([
            'operating_date' => '2026-10-12', 'cart_id' => $this->cart->id, 'staff_id' => $this->rider->id,
            'status' => 'RECONCILED', 'reconciled_at' => '2026-10-12 18:00:00', 'expected_cash_minor' => 10000,
            'expected_qris_minor' => 0, 'expected_transfer_minor' => 0,
            'expected_total_minor' => 10000, 'cash_minor' => 9500, 'qris_minor' => 0,
            'transfer_minor' => 0, 'declared_total_minor' => 9500, 'variance_minor' => -500,
        ]);
        Settlement::query()->create([
            'operating_date' => '2026-10-12', 'cart_id' => $this->secondCart->id, 'staff_id' => $this->rider->id,
            'status' => 'RECONCILED', 'reconciled_at' => '2026-10-12 18:00:00',
            'expected_cash_minor' => 0, 'expected_qris_minor' => 2000, 'expected_transfer_minor' => 1000,
            'expected_total_minor' => 3000, 'cash_minor' => 0, 'qris_minor' => 2000,
            'transfer_minor' => 1000, 'declared_total_minor' => 3000, 'variance_minor' => 0,
        ]);
        $category = CashCategory::query()->where('name', 'Listrik')->firstOrFail();
        $cashAccount = CashAccount::query()->where('name', 'Kas Kecil Kantor')->firstOrFail();
        CashTransaction::query()->create([
            'uuid' => (string) Str::uuid(), 'date' => '2026-10-13', 'direction' => 'OUT',
            'cash_category_id' => $category->id, 'cash_account_id' => $cashAccount->id,
            'amount_minor' => 1000, 'description' => 'Listrik', 'recorded_by' => $this->management->id,
            'status' => 'POSTED',
        ]);
        CashTransaction::query()->create([
            'uuid' => (string) Str::uuid(), 'date' => '2026-10-13', 'direction' => 'OUT',
            'cash_category_id' => $category->id, 'cash_account_id' => $cashAccount->id,
            'amount_minor' => 99000, 'description' => 'Batal', 'recorded_by' => $this->management->id,
            'status' => 'VOID',
        ]);
        $run = PayrollRun::query()->create([
            'period_start' => '2026-10-01', 'status' => 'APPROVED',
            'created_by' => $this->management->id, 'approved_by' => $this->management->id,
        ]);
        PayrollItem::query()->create([
            'run_id' => $run->id, 'user_id' => $this->rider->id,
            'snapshot' => ['gross_pay_minor' => 3000, 'take_home_minor' => 2500],
        ]);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $report = app(ManagementReportService::class)->build(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(40, $queries);
        $this->assertSame(13000, $report['summary']['cart_revenue_minor']);
        $this->assertSame(5000, $report['summary']['direct_revenue_minor']);
        $this->assertSame(7000, $report['summary']['cogs_minor']);
        $this->assertSame(7000, $report['summary']['net_profit_minor']);
        $this->assertSame('61,11%', $report['summary']['gross_margin']);
        $this->assertSame(7000, $report['sections']['laba_rugi']['rows'][array_key_last($report['sections']['laba_rugi']['rows'])][3]);
        $this->assertSame(['TOTAL', '', 28000, 28000], $report['sections']['neraca_saldo']['rows'][array_key_last($report['sections']['neraca_saldo']['rows'])]);
        $this->assertSame(['C01', $this->rider->name, 10000, 0, 9500, 0, -500, 1], $report['sections']['setoran']['rows'][0]);
        $this->assertSame(['C02', $this->rider->name, 0, 3000, 0, 3000, 0, 0], $report['sections']['setoran']['rows'][1]);
        $this->assertSame(['C01', 15000, 6000, 9000, '60,00%'], $report['sections']['margin_gerobak']['rows'][0]);
        $this->assertSame(['C02', 3000, 1000, 2000, '66,66%'], $report['sections']['margin_gerobak']['rows'][1]);
        $this->assertSame(['Kopi Susu', 15000, 6000, 9000, '60,00%'], $report['sections']['margin_produk']['rows'][0]);
        $this->assertSame(['Listrik', 1000], $report['sections']['biaya']['rows'][0]);
        $this->assertSame(['1-1100', 'Kas Kecil Kantor', 0, 5000, 1000, 4000], $report['sections']['arus_kas']['rows'][0]);
        $this->assertSame(['1-1200', 'Kas Setoran', 0, 13000, 0, 13000], $report['sections']['arus_kas']['rows'][1]);
        $this->assertSame(['2026-10', 'APPROVED', 1, 3000, 2500], $report['sections']['payroll']['rows'][0]);
    }

    public function test_week_is_monday_through_sunday_and_custom_range_is_capped(): void
    {
        foreach (['2026-10-11', '2026-10-12', '2026-10-18', '2026-10-19'] as $index => $date) {
            $this->postJournal($date, '1-1200', '4-1100', 1000, $index + 10);
        }

        Livewire::test(ManagementReports::class)
            ->set('period', 'weekly')->set('anchorDate', '2026-10-14')
            ->call('applyRange')->assertHasNoErrors()
            ->assertSet('reportFrom', '2026-10-12')->assertSet('reportTo', '2026-10-18');
        Livewire::test(ManagementReports::class)
            ->set('period', 'monthly')->set('anchorDate', '2026-02-15')
            ->call('applyRange')->assertHasNoErrors()
            ->assertSet('reportFrom', '2026-02-01')->assertSet('reportTo', '2026-02-28');
        Livewire::test(ManagementReports::class)
            ->set('period', 'custom')->set('fromDate', '2026-10-12')->set('toDate', '2026-10-18')
            ->call('applyRange')->assertHasNoErrors()
            ->assertSet('reportFrom', '2026-10-12')->assertSet('reportTo', '2026-10-18');
        $report = app(ManagementReportService::class)->build(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'));
        $this->assertSame(2000, $report['summary']['cart_revenue_minor']);
        $this->assertIsArray(app(ManagementReportService::class)->build(Carbon::parse('2026-01-01'), Carbon::parse('2027-01-01')));
        $this->expectException(ValidationException::class);
        app(ManagementReportService::class)->build(Carbon::parse('2026-01-01'), Carbon::parse('2027-01-02'));
    }

    public function test_refill_expiry_and_waste_are_grouped_by_cart(): void
    {
        $media = Media::query()->create([
            'kind' => 'evidence', 'path' => 'synthetic.jpg', 'mime' => 'image/jpeg',
            'bytes' => 10, 'sha256' => hash('sha256', 'synthetic'), 'uploaded_by' => $this->rider->id,
        ]);
        RefillRequest::query()->create([
            'uuid' => (string) Str::uuid(), 'code' => 'REF-T014-1',
            'operating_date' => '2026-10-12', 'cart_id' => $this->cart->id,
            'staff_id' => $this->rider->id, 'kitchen_id' => $this->cart->kitchen_id,
            'status' => RefillStatus::EXPIRED, 'evidence_photo_id' => $media->id,
        ]);
        $settlement = Settlement::query()->create([
            'operating_date' => '2026-10-12', 'cart_id' => $this->cart->id,
            'staff_id' => $this->rider->id,
        ]);
        DB::table('settlement_lines')->insert([
            'settlement_id' => $settlement->id, 'product_id' => $this->coffee->id,
            'qty_issued' => 5, 'qty_sold' => 2, 'qty_remaining' => 1, 'qty_wasted' => 2,
            'variance_qty' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $report = app(ManagementReportService::class)->build(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-12'));
        $this->assertSame([['C01', 1, 2]], $report['sections']['refill_waste']['rows']);
    }

    public function test_every_section_can_be_exported_as_excel_and_pdf(): void
    {
        $keys = array_keys(app(ManagementReportService::class)->build(today(), today())['sections']);
        foreach ($keys as $key) {
            Livewire::test(ManagementReports::class)->call('exportExcel', $key)
                ->assertFileDownloaded("laporan-management-{$key}-".today()->toDateString().'-'.today()->toDateString().'.xlsx');
            Livewire::test(ManagementReports::class)->call('exportPdf', $key)
                ->assertFileDownloaded("laporan-management-{$key}-".today()->toDateString().'-'.today()->toDateString().'.pdf');
        }
    }

    public function test_later_reversals_are_explicitly_reconciled_in_both_report_periods(): void
    {
        $this->directSale($this->coffee, '2026-10-12', 5000, 2000);
        $revenue = $this->postJournal('2026-10-12', '1-1100', '4-1200', 5000, 30);
        $cost = $this->postJournal('2026-10-12', '5-1100', '1-1600', 2000, 31);
        $expense = $this->postJournal('2026-10-12', '6-1400', '1-1100', 1000, 32);
        $category = CashCategory::query()->where('name', 'Listrik')->firstOrFail();
        $cashAccount = CashAccount::query()->where('name', 'Kas Kecil Kantor')->firstOrFail();
        CashTransaction::query()->create([
            'uuid' => (string) Str::uuid(), 'date' => '2026-10-12', 'direction' => 'OUT',
            'cash_category_id' => $category->id, 'cash_account_id' => $cashAccount->id,
            'amount_minor' => 1000, 'description' => 'Listrik', 'recorded_by' => $this->management->id,
            'status' => 'VOID',
        ]);
        DB::table('direct_sales')->update(['voided_at' => '2026-10-19 12:00:00']);

        $this->travelTo(Carbon::parse('2026-10-19 12:00:00'));
        foreach ([$revenue, $cost, $expense] as $entry) {
            app(JournalService::class)->reverse($entry, 'Batal');
        }

        $before = app(ManagementReportService::class)->build(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-12'));
        $this->assertSame(2000, $before['summary']['net_profit_minor']);
        $this->assertSame(['Penyesuaian jurnal / penjualan belum disetor', 5000, 2000, 3000, '60,00%'],
            $before['sections']['margin_produk']['rows'][0]);
        $this->assertSame(['Penyesuaian jurnal / biaya di luar kas keluar', 1000], $before['sections']['biaya']['rows'][0]);

        $after = app(ManagementReportService::class)->build(Carbon::parse('2026-10-19'), Carbon::parse('2026-10-19'));
        $this->assertSame(-2000, $after['summary']['net_profit_minor']);
        $this->assertSame('60,00%', $after['summary']['gross_margin']);
        $this->assertSame(['Penyesuaian jurnal / penjualan belum disetor', -5000, -2000, -3000, '60,00%'],
            $after['sections']['margin_produk']['rows'][0]);
        $this->assertSame(['Penyesuaian jurnal / biaya di luar kas keluar', -1000], $after['sections']['biaya']['rows'][0]);
    }

    public function test_pending_sales_and_asset_transfers_do_not_become_journal_profit_or_expense(): void
    {
        $this->sale($this->cart, $this->coffee, '2026-10-12', 1, 5000, 2000);
        $category = CashCategory::query()->where('name', 'Petty Cash (isi ulang)')->firstOrFail();
        $cashAccount = CashAccount::query()->where('name', 'Kas Kecil Kantor')->firstOrFail();
        CashTransaction::query()->create([
            'uuid' => (string) Str::uuid(), 'date' => '2026-10-12', 'direction' => 'OUT',
            'cash_category_id' => $category->id, 'cash_account_id' => $cashAccount->id,
            'amount_minor' => 1000, 'description' => 'Isi ulang kas', 'recorded_by' => $this->management->id,
            'status' => 'POSTED',
        ]);

        $report = app(ManagementReportService::class)->build(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-12'));
        $this->assertSame(0, $report['summary']['net_profit_minor']);
        $this->assertSame(['C01', 5000, 2000, 3000, '60,00%'], $report['sections']['margin_gerobak']['rows'][0]);
        $this->assertSame(['Penyesuaian jurnal / penjualan belum disetor', -5000, -2000, -3000, '60,00%'],
            $report['sections']['margin_gerobak']['rows'][1]);
        $this->assertSame([], $report['sections']['biaya']['rows']);
    }

    public function test_payroll_recap_uses_the_month_end_accrual_date(): void
    {
        $run = PayrollRun::query()->create([
            'period_start' => '2026-10-01', 'status' => 'APPROVED',
            'created_by' => $this->management->id, 'approved_by' => $this->management->id,
        ]);
        PayrollItem::query()->create([
            'run_id' => $run->id, 'user_id' => $this->rider->id,
            'snapshot' => ['gross_pay_minor' => 3000, 'take_home_minor' => 2500],
        ]);
        $this->postJournal('2026-10-31', '6-1200', '2-1100', 3000, 40);

        $before = app(ManagementReportService::class)->build(Carbon::parse('2026-10-30'), Carbon::parse('2026-10-30'));
        $this->assertSame([], $before['sections']['payroll']['rows']);
        $onAccrual = app(ManagementReportService::class)->build(Carbon::parse('2026-10-31'), Carbon::parse('2026-10-31'));
        $this->assertSame(['2026-10', 'APPROVED', 1, 3000, 2500], $onAccrual['sections']['payroll']['rows'][0]);
        $this->assertSame(3000, $onAccrual['summary']['salary_expense_minor']);
    }

    public function test_aggregate_reads_share_the_report_transaction(): void
    {
        $levels = [];
        DB::listen(function ($query) use (&$levels): void {
            if (str_contains($query->sql, 'journal_lines') && str_contains($query->sql, 'journal_entries')) {
                $levels[] = DB::transactionLevel();
            }
        });
        app(ManagementReportService::class)->build(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-12'));
        $this->assertGreaterThanOrEqual(2, count($levels));
        $this->assertGreaterThanOrEqual(2, min($levels));
    }

    public function test_mixed_legacy_and_closing_period_reconciles_product_and_bike_to_journals(): void
    {
        config(['soul.closing_started_on' => '2026-10-18']);
        $this->sale($this->cart, $this->coffee, '2026-10-17', 1, 10000, 4000);
        // A legacy APK sale already included in Closing must never be counted twice.
        $this->sale($this->cart, $this->coffee, '2026-10-18', 1, 10000, 4000);
        $closingId = DB::table('rider_closings')->insertGetId([
            'operating_date' => '2026-10-18', 'cart_id' => $this->cart->id, 'rider_id' => $this->rider->id,
            'total_minor' => 20000, 'cogs_minor' => 8000,
            'cash_minor' => 20000, 'qris_minor' => 0, 'transfer_minor' => 0,
            'closed_at' => '2026-10-18 18:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rider_closing_lines')->insert([
            'rider_closing_id' => $closingId, 'product_id' => $this->coffee->id,
            'qty_in' => 2, 'qty_carry' => 0, 'qty_remaining' => 0, 'qty_wasted' => 0,
            'qty_sold' => 2, 'qty_sale_posted' => 1, 'qty_waste_posted' => 0,
            'unit_price_minor' => 10000, 'unit_cost_minor' => 4000, 'subtotal_minor' => 20000,
        ]);
        $this->directSale($this->coffee, '2026-10-18', 5000, 2000);

        $this->postJournal('2026-10-17', '1-1200', '4-1100', 10000, 601);
        $this->postJournal('2026-10-17', '5-1100', '1-1600', 4000, 602);
        $this->postJournal('2026-10-18', '1-1200', '4-1100', 20000, 603);
        $this->postJournal('2026-10-18', '5-1100', '1-1600', 8000, 604);
        $this->postJournal('2026-10-18', '1-1100', '4-1200', 5000, 605);
        $this->postJournal('2026-10-18', '5-1100', '1-1600', 2000, 606);

        $report = app(ManagementReportService::class)->build(Carbon::parse('2026-10-17'), Carbon::parse('2026-10-18'));
        $this->assertSame(35000, $report['summary']['revenue_minor']);
        $this->assertSame(14000, $report['summary']['cogs_minor']);
        foreach (['margin_gerobak', 'margin_produk'] as $section) {
            $rows = $report['sections'][$section]['rows'];
            $this->assertCount(1, $rows);
            $this->assertSame(35000, array_sum(array_column($rows, 1)));
            $this->assertSame(14000, array_sum(array_column($rows, 2)));
        }
    }

    public function test_method_only_variance_is_counted_in_settlement_report(): void
    {
        Settlement::query()->create([
            'operating_date' => '2026-10-20', 'cart_id' => $this->cart->id, 'staff_id' => $this->rider->id,
            'status' => 'SUBMITTED', 'expected_cash_minor' => 10000,
            'expected_qris_minor' => 5000, 'expected_transfer_minor' => 0,
            'expected_total_minor' => 15000, 'cash_minor' => 15000, 'qris_minor' => 0,
            'transfer_minor' => 0, 'declared_total_minor' => 15000, 'variance_minor' => 0,
            'method_variance_flagged' => true,
        ]);

        $report = app(ManagementReportService::class)->build(Carbon::parse('2026-10-20'), Carbon::parse('2026-10-20'));
        $this->assertSame(0, $report['sections']['setoran']['rows'][0][6]);
        $this->assertSame(1, $report['sections']['setoran']['rows'][0][7]);
    }

    private function postJournal(string $date, string $debitCode, string $creditCode, int $amount, int $id): JournalEntry
    {
        return app(JournalService::class)->post([
            'entry_date' => $date, 'description' => 'Jurnal sintetis',
            'source_type' => 'report_test', 'source_id' => $id, 'purpose' => 'RECORD',
        ], [
            ['account_id' => Account::query()->where('code', $debitCode)->value('id'), 'debit_minor' => $amount, 'credit_minor' => 0],
            ['account_id' => Account::query()->where('code', $creditCode)->value('id'), 'debit_minor' => 0, 'credit_minor' => $amount],
        ]);
    }

    public function test_current_product_margin_hides_retired_ice_but_old_period_keeps_history(): void
    {
        $ice = Product::query()->create(['code' => 'OLD-ICE', 'name' => 'Es Batu', 'unit' => 'pack',
            'is_sellable' => false, 'is_active' => false, 'sort_order' => 90]);
        $this->directSale($ice, '2026-10-20', 4000, 2000);

        $old = app(ManagementReportService::class)->build(Carbon::parse('2026-10-20'), Carbon::parse('2026-10-20'));
        $this->assertContains('Es Batu', array_column($old['sections']['margin_produk']['rows'], 0));
        $current = app(ManagementReportService::class)->build(Carbon::parse('2026-10-20'), Carbon::parse('2026-10-23'));
        $this->assertNotContains('Es Batu', array_column($current['sections']['margin_produk']['rows'], 0));
    }

    private function sale(Cart $cart, Product $product, string $date, int $qty, int $subtotal, int $unitCost, bool $void = false): void
    {
        $id = DB::table('sales')->insertGetId([
            'uuid' => (string) Str::uuid(), 'operating_date' => $date,
            'cart_id' => $cart->id, 'staff_id' => $this->rider->id,
            'occurred_at' => "$date 12:00:00", 'total_qty' => $qty,
            'total_amount_minor' => $subtotal, 'payment_method' => 'cash',
            'voided_at' => $void ? "$date 13:00:00" : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sale_lines')->insert([
            'sale_id' => $id, 'product_id' => $product->id, 'qty' => $qty,
            'unit_price_minor' => intdiv($subtotal, $qty), 'subtotal_minor' => $subtotal,
            'unit_cost_minor' => $unitCost, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function directSale(Product $product, string $date, int $subtotal, int $unitCost, bool $void = false): void
    {
        $id = DB::table('direct_sales')->insertGetId([
            'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id,
            'recorded_by' => $this->management->id, 'occurred_at' => "$date 12:00:00",
            'total_qty' => 1, 'total_amount_minor' => $subtotal,
            'voided_at' => $void ? "$date 13:00:00" : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('direct_sale_lines')->insert([
            'direct_sale_id' => $id, 'product_id' => $product->id, 'qty' => 1,
            'unit_price_minor' => $subtotal, 'subtotal_minor' => $subtotal,
            'unit_cost_minor' => $unitCost, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
