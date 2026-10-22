<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Pages\FinancialReportSettings;
use App\Filament\Pages\ManagementReports;
use App\Models\Account;
use App\Models\Cart;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Models\Product;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\CashTransactionService;
use App\Services\FinancialReportSettingsService;
use App\Services\JournalService;
use App\Services\Reporting\ManagementReportService;
use Database\Seeders\AccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BalanceSheetCashFlowBudgetTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $rider;

    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccountSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $this->manager = User::factory()->role(Role::MANAGEMENT)->create();
        $this->rider = User::factory()->role(Role::STAFF)->create();
        $kitchen = CentralKitchen::query()->create([
            'name' => 'Dapur Uji', 'address' => 'Jalan Uji', 'open_at' => '05:00', 'close_at' => '20:00',
        ]);
        $this->cart = Cart::query()->create(['code' => 'C053', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $this->actingAs($this->manager);
    }

    public function test_balance_sheet_cash_flow_point_profit_budget_and_kpis_reconcile(): void
    {
        $date = '2026-10-20';
        $location = Location::query()->create(['name' => 'Titik Uji', 'lat' => -6.2, 'lng' => 106.8, 'geofence_m' => 100]);
        StaffAssignment::query()->create([
            'user_id' => $this->rider->id, 'cart_id' => $this->cart->id,
            'location_id' => $location->id, 'operating_date' => $date,
            'assigned_by' => $this->manager->id, 'kitchen_id' => $this->cart->kitchen_id,
            'source' => 'hand_to_cart',
        ]);
        $product = Product::query()->create([
            'code' => 'CUP053', 'name' => 'Kopi Uji', 'unit' => 'cup', 'is_sellable' => true, 'sort_order' => 1,
        ]);
        $closingId = DB::table('rider_closings')->insertGetId([
            'operating_date' => $date, 'cart_id' => $this->cart->id, 'rider_id' => $this->rider->id,
            'total_minor' => 20000, 'cogs_minor' => 5000, 'cash_minor' => 20000,
            'qris_minor' => 0, 'transfer_minor' => 0,
            'closed_at' => "$date 17:00:00", 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('rider_closing_lines')->insert([
            'rider_closing_id' => $closingId, 'product_id' => $product->id,
            'qty_in' => 3, 'qty_carry' => 0, 'qty_remaining' => 0, 'qty_wasted' => 1,
            'qty_sold' => 2, 'qty_sale_posted' => 0, 'qty_waste_posted' => 0,
            'unit_price_minor' => 10000, 'unit_cost_minor' => 2500, 'subtotal_minor' => 20000,
        ]);
        DB::table('rider_daily_commission_locks')->insert([
            'rider_id' => $this->rider->id, 'operating_date' => $date, 'cups_sold' => 2,
            'amount_per_cup_minor' => 500, 'commission_minor' => 1000, 'locked_at' => now(),
        ]);
        $allowanceId = DB::table('daily_cart_allowances')->insertGetId([
            'operating_date' => $date, 'cart_id' => $this->cart->id,
            'amount_minor' => 2000, 'is_edited' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('settlements')->insert([
            'operating_date' => $date, 'cart_id' => $this->cart->id, 'staff_id' => $this->rider->id,
            'status' => 'RECONCILED', 'cash_minor' => 19500, 'qris_minor' => 0, 'transfer_minor' => 0,
            'declared_total_minor' => 19500, 'expected_total_minor' => 20000,
            'variance_minor' => -500, 'reconciled_at' => "$date 18:00:00",
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->postJournal('2026-09-30', [['1-1300', 100000, 0], ['3-1100', 0, 100000]], 1);
        $this->postJournal($date, [['1-1200', 20000, 0], ['4-1100', 0, 20000, $this->cart->id]], 2);
        $this->postJournal($date, [['5-1100', 5000, 0, $this->cart->id], ['1-1600', 0, 5000]], 3);
        $this->postJournal($date, [['1-1800', 10000, 0], ['1-1300', 0, 10000]], 4);
        $this->postJournal($date, [['1-1100', 3000, 0], ['1-1300', 0, 3000]], 5);
        $accounts = Account::query()->whereIn('code', ['6-1700', '1-1100'])->pluck('id', 'code');
        app(JournalService::class)->post([
            'entry_date' => $date, 'description' => 'Uang harian uji',
            'source_type' => 'daily_allowance', 'source_id' => $allowanceId,
            'purpose' => 'DAY_20261020_R'.$this->rider->id, 'posted_by' => $this->manager->id,
        ], [
            ['account_id' => $accounts['6-1700'], 'debit_minor' => 2000, 'credit_minor' => 0],
            ['account_id' => $accounts['1-1100'], 'debit_minor' => 0, 'credit_minor' => 2000],
        ]);
        $category = CashCategory::query()->where('name', 'Listrik')->firstOrFail();
        $cash = CashAccount::query()->whereHas('account', fn ($query) => $query->where('code', '1-1300'))->firstOrFail();
        app(CashTransactionService::class)->record($this->manager, [
            'uuid' => (string) Str::uuid(), 'date' => $date,
            'cash_category_id' => $category->id, 'cash_account_id' => $cash->id,
            'amount_minor' => 1000, 'description' => 'Listrik Coffee Bike', 'cart_id' => $this->cart->id,
        ]);

        $settings = app(FinancialReportSettingsService::class);
        $revenueId = Account::query()->where('code', '4-1100')->value('id');
        $settings->saveBudget($this->manager, ['month' => '2026-10', 'account_id' => $revenueId,
            'cart_id' => null, 'amount_minor' => 15000]);
        $settings->saveBudget($this->manager, ['month' => '2026-10', 'account_id' => $revenueId,
            'cart_id' => $this->cart->id, 'amount_minor' => 18000]);
        $settings->saveKpiTarget($this->manager, 'hpp_ratio_bps', 3000);
        $settings->saveKpiTarget($this->manager, 'settlement_variance_minor', 1000);
        $settings->saveKpiTarget($this->manager, 'on_time_deposits_bps', 10000);

        $report = app(ManagementReportService::class)->build(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $balance = $report['sections']['neraca']['rows'];
        $this->assertSame(['CEK', '', 'Selisih neraca', 0], end($balance));
        $cashFlow = $report['sections']['arus_kas_langsung']['rows'];
        $cashRows = collect($cashFlow)->keyBy(fn (array $row): string => $row[0]);
        $this->assertSame(['Kas dan bank awal', '', 0, 0, 100000], $cashFlow[0]);
        $this->assertSame(['Operasi total', '', 20000, 3000, 17000], $cashRows['Operasi total']);
        $this->assertSame(['Investasi total', '', 0, 10000, -10000], $cashRows['Investasi total']);
        $this->assertSame(['Arus bersih', '', 20000, 13000, 7000], $cashRows['Arus bersih']);
        $this->assertSame(['Kas dan bank akhir', '', 0, 0, 107000], $cashRows['Kas dan bank akhir']);
        $point = $report['sections']['laba_titik']['rows'];
        $this->assertSame('Titik Uji', $point[0][0]);
        $this->assertSame(11000, $point[0][8]);
        $this->assertSame(11000, end($point)[8]);
        $budgets = $report['sections']['anggaran']['rows'];
        $this->assertCount(2, $budgets);
        $this->assertSame(20000, $budgets[0][4]);
        $this->assertSame(5000, $budgets[0][5]);
        $this->assertSame(20000, $budgets[1][4]);
        $this->assertSame('Sesuai', $report['sections']['kpi_keuangan']['rows'][0][3]);
        $this->get(ManagementReports::getUrl())->assertOk();
    }

    public function test_cash_mapping_can_change_and_wrong_role_gets_403(): void
    {
        $this->postJournal('2026-10-20', [['1-1800', 10000, 0], ['1-1300', 0, 10000]], 10);
        $settings = app(FinancialReportSettingsService::class);
        $assetId = Account::query()->where('code', '1-1800')->value('id');
        $settings->saveCashFlowMapping($this->manager, $assetId, 'OPERASI');
        $report = app(ManagementReportService::class)->build(Carbon::parse('2026-10-20'), Carbon::parse('2026-10-20'));
        $cashRows = collect($report['sections']['arus_kas_langsung']['rows'])->keyBy(fn (array $row): string => $row[0]);
        $this->assertSame(['Operasi total', '', 0, 10000, -10000], $cashRows['Operasi total']);
        $this->get(FinancialReportSettings::getUrl())->assertOk();
        $this->actingAs(User::factory()->role(Role::STAFF)->create());
        $this->get(FinancialReportSettings::getUrl())->assertForbidden();
        $this->get(ManagementReports::getUrl())->assertForbidden();
    }

    public function test_point_profit_uses_net_ledger_revenue_and_monthly_budget_includes_direct_sale_after_later_void(): void
    {
        $date = '2026-10-20';
        $location = Location::query()->create(['name' => 'Titik Pajak', 'lat' => -6.2, 'lng' => 106.8, 'geofence_m' => 100]);
        StaffAssignment::query()->create([
            'user_id' => $this->rider->id, 'cart_id' => $this->cart->id,
            'location_id' => $location->id, 'operating_date' => $date,
            'assigned_by' => $this->manager->id, 'kitchen_id' => $this->cart->kitchen_id,
            'source' => 'hand_to_cart',
        ]);
        $accounts = Account::query()->whereIn('code', ['1-1200', '1-1100', '4-1100', '4-1200', '2-1500'])
            ->pluck('id', 'code');
        app(JournalService::class)->post([
            'entry_date' => $date, 'description' => 'Jualan dengan PBJT uji',
            'source_type' => 'financial_test', 'source_id' => 51,
            'purpose' => 'RECORD', 'posted_by' => $this->manager->id,
        ], [
            ['account_id' => $accounts['1-1200'], 'debit_minor' => 11000, 'credit_minor' => 0, 'cart_id' => $this->cart->id],
            ['account_id' => $accounts['4-1100'], 'debit_minor' => 0, 'credit_minor' => 10000, 'cart_id' => $this->cart->id],
            ['account_id' => $accounts['2-1500'], 'debit_minor' => 0, 'credit_minor' => 1000, 'cart_id' => $this->cart->id],
        ]);
        $saleId = DB::table('direct_sales')->insertGetId([
            'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id,
            'recorded_by' => $this->manager->id, 'occurred_at' => "$date 12:00:00",
            'total_qty' => 1, 'total_amount_minor' => 10000,
            'payment_method' => 'cash', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $direct = app(JournalService::class)->post([
            'entry_date' => $date, 'description' => 'Penjualan langsung dengan PBJT uji',
            'source_type' => 'direct_sale', 'source_id' => $saleId,
            'purpose' => 'DIRECT_SALE', 'posted_by' => $this->manager->id,
        ], [
            ['account_id' => $accounts['1-1100'], 'debit_minor' => 10000, 'credit_minor' => 0],
            ['account_id' => $accounts['4-1200'], 'debit_minor' => 0, 'credit_minor' => 9000],
            ['account_id' => $accounts['2-1500'], 'debit_minor' => 0, 'credit_minor' => 1000],
        ]);
        DB::table('daily_cart_allowances')->insert([
            'operating_date' => $date, 'cart_id' => $this->cart->id,
            'amount_minor' => 50000, 'is_edited' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(FinancialReportSettingsService::class)->saveBudget($this->manager, [
            'month' => '2026-10', 'account_id' => $accounts['4-1200'],
            'cart_id' => $this->cart->id, 'amount_minor' => 10000,
        ]);
        $this->postJournal('2026-10-25', [['1-1100', 3000, 0], ['4-1200', 0, 3000, $this->cart->id]], 52);

        $report = app(ManagementReportService::class)->build(Carbon::parse($date), Carbon::parse($date));
        $this->assertSame(19000, $report['sections']['laba_titik']['rows'][0][3]);
        $this->assertSame(0, $report['sections']['laba_titik']['rows'][0][6]);
        $this->assertSame(19000, $report['sections']['laba_titik']['rows'][0][8]);
        $this->assertSame(12000, $report['sections']['anggaran']['rows'][0][4]);

        $this->travelTo(Carbon::parse('2026-11-01 12:00:00'));
        app(JournalService::class)->reverse($direct, 'Penjualan langsung dibatalkan');
        DB::table('direct_sales')->where('id', $saleId)->update(['voided_at' => now()]);
        $historic = app(ManagementReportService::class)->build(Carbon::parse($date), Carbon::parse($date));
        $this->assertSame(19000, $historic['sections']['laba_titik']['rows'][0][3]);
    }

    public function test_compound_cash_journal_is_marked_for_review_without_guessing_funding_source(): void
    {
        $this->postJournal('2026-10-20', [
            ['1-1300', 100, 0], ['6-1100', 100, 0],
            ['4-1100', 0, 100], ['3-1100', 0, 100],
        ], 53);
        $report = app(ManagementReportService::class)->build(Carbon::parse('2026-10-20'), Carbon::parse('2026-10-20'));
        $cashRows = collect($report['sections']['arus_kas_langsung']['rows'])->keyBy(fn (array $row): string => $row[0]);
        $this->assertSame(['Perlu dicek total', '', 100, 0, 100], $cashRows['Perlu dicek total']);
        $this->assertSame(['Kas dan bank akhir', '', 0, 0, 100], $cashRows['Kas dan bank akhir']);
    }

    public function test_closing_correction_stays_with_original_rider_after_bike_changes_hands(): void
    {
        $newRider = User::factory()->role(Role::STAFF)->create();
        $oldPoint = Location::query()->create(['name' => 'Titik Lama', 'lat' => -6.2, 'lng' => 106.8, 'geofence_m' => 100]);
        $newPoint = Location::query()->create(['name' => 'Titik Baru', 'lat' => -6.2, 'lng' => 106.8, 'geofence_m' => 100]);
        foreach ([['2026-10-20', $this->rider, $oldPoint], ['2026-10-25', $newRider, $newPoint]] as [$date, $rider, $point]) {
            StaffAssignment::query()->create([
                'user_id' => $rider->id, 'cart_id' => $this->cart->id, 'location_id' => $point->id,
                'operating_date' => $date, 'assigned_by' => $this->manager->id,
                'kitchen_id' => $this->cart->kitchen_id, 'source' => 'hand_to_cart',
            ]);
        }
        $closingId = DB::table('rider_closings')->insertGetId([
            'operating_date' => '2026-10-20', 'cart_id' => $this->cart->id, 'rider_id' => $this->rider->id,
            'total_minor' => 2000, 'cogs_minor' => 0, 'cash_minor' => 2000,
            'qris_minor' => 0, 'transfer_minor' => 0,
            'closed_at' => '2026-10-20 17:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $revisionId = DB::table('rider_closing_revisions')->insertGetId([
            'rider_closing_id' => $closingId, 'actor_id' => $this->manager->id,
            'reason' => 'Koreksi hasil Closing', 'snapshot_json' => '{}', 'created_at' => now(),
        ]);
        $accounts = Account::query()->whereIn('code', ['6-1100', '4-1100'])->pluck('id', 'code');
        app(JournalService::class)->post([
            'entry_date' => '2026-10-25', 'description' => 'Koreksi Closing uji',
            'source_type' => 'rider_closing_revision', 'source_id' => $revisionId,
            'purpose' => 'SETTLEMENT_CORRECTION', 'posted_by' => $this->manager->id,
        ], [
            ['account_id' => $accounts['6-1100'], 'debit_minor' => 1000, 'credit_minor' => 0, 'cart_id' => $this->cart->id],
            ['account_id' => $accounts['4-1100'], 'debit_minor' => 0, 'credit_minor' => 1000, 'cart_id' => $this->cart->id],
        ]);
        $report = app(ManagementReportService::class)->build(Carbon::parse('2026-10-25'), Carbon::parse('2026-10-25'));
        $point = $report['sections']['laba_titik']['rows'][0];
        $this->assertSame('Titik Lama', $point[0]);
        $this->assertSame($this->rider->name, $point[2]);
        $this->assertSame(1000, $point[3]);
    }

    private function postJournal(string $date, array $lines, int $id): void
    {
        $ids = Account::query()->whereIn('code', array_column($lines, 0))->pluck('id', 'code');
        app(JournalService::class)->post([
            'entry_date' => $date, 'description' => 'Jurnal finansial uji',
            'source_type' => 'financial_test', 'source_id' => $id,
            'purpose' => 'RECORD', 'posted_by' => $this->manager->id,
        ], array_map(fn (array $line): array => [
            'account_id' => $ids[$line[0]], 'debit_minor' => $line[1], 'credit_minor' => $line[2],
            'cart_id' => $line[3] ?? null,
        ], $lines));
    }
}
