<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Exports\ManagementReportExport;
use App\Filament\Pages\FinanceDailyReport;
use App\Filament\Resources\Carts\CartResource;
use App\Filament\Resources\Carts\Pages\EditCart;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Models\Product;
use App\Models\RiderClosing;
use App\Models\Settlement;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\Reporting\FinanceDailyReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceDailyReportTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Product $coffee;

    private Product $tea;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->kitchen = CentralKitchen::query()->create([
            'name' => 'Dapur A', 'address' => 'Jalan A', 'open_at' => '05:00', 'close_at' => '20:00',
        ]);
        $this->coffee = Product::query()->create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup', 'is_sellable' => true, 'sort_order' => 1,
        ]);
        $this->tea = Product::query()->create([
            'code' => 'TEH', 'name' => 'Teh', 'unit' => 'cup', 'is_sellable' => true, 'sort_order' => 2,
        ]);
        $this->actingAs($this->finance);
    }

    public function test_three_rider_cards_recap_and_excel_use_closing_settlement_and_return_ledger(): void
    {
        $date = '2026-10-14';
        $riders = [
            User::factory()->role(Role::STAFF)->create(['name' => 'Ani']),
            User::factory()->role(Role::STAFF)->create(['name' => 'Beni']),
            User::factory()->role(Role::STAFF)->create(['name' => 'Cici']),
        ];
        $carts = [
            $this->cart('A01', 'Pujasera 1'), $this->cart('A02', 'Both'), $this->cart('A03', 'Pujasera 1'),
        ];
        $location = Location::query()->create(['name' => 'Blok M', 'lat' => -6.24, 'lng' => 106.8]);
        DB::table('staff_assignments')->insert([
            'user_id' => $riders[0]->id, 'cart_id' => $carts[0]->id, 'location_id' => $location->id,
            'operating_date' => $date, 'assigned_by' => $this->finance->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $first = $this->closing($date, $riders[0], $carts[0], $this->coffee, 12, 2, 3, 1, 10, 10000);
        $this->closing($date, $riders[1], $carts[1], $this->coffee, 8, 0, 3, 0, 5, 10000);
        $this->closing($date, $riders[2], $carts[2], $this->tea, 5, 0, 1, 0, 4, 8000);
        $settlement = Settlement::query()->create([
            'operating_date' => $date, 'cart_id' => $carts[0]->id, 'staff_id' => $riders[0]->id,
            'status' => 'RECONCILED', 'reconciled_at' => '2026-10-14 19:00:00',
            'cash_minor' => 60000, 'qris_minor' => 30000, 'transfer_minor' => 9000,
            'declared_total_minor' => 99000, 'expected_total_minor' => 100000,
            'cash_variance_minor' => -1000, 'qris_variance_minor' => 0,
            'transfer_variance_minor' => 0, 'variance_minor' => -1000,
            'variance_reason' => 'Hitung tunai kurang',
        ]);
        $this->returnLedger($carts[0], $this->coffee, 2, 'settlement_return', $settlement->id, '2026-10-15 08:00:00');
        $this->returnLedger($carts[2], $this->tea, 1, 'cart_closeout_return', $carts[2]->id, '2026-10-14 18:00:00');
        $this->returnLedger($carts[0], $this->tea, 1, 'cart_closeout_return', $carts[0]->id, '2026-10-14 18:01:00');
        $extraCart = $this->cart('A04', 'Pujasera 1');
        $this->returnLedger($extraCart, $this->tea, 1, 'cart_closeout_return', $extraCart->id, '2026-10-14 18:02:00');

        $report = app(FinanceDailyReportService::class)->build($date, null, 'weekly');
        $this->assertCount(3, $report['cards']);
        $this->assertSame('Blok M', $report['cards'][0]['location']);
        $this->assertSame($first->cash_minor, $report['cards'][0]['cash_minor']);
        $this->assertSame(-1000, $report['cards'][0]['cash_variance_minor']);
        $this->assertSame(2, $report['cards'][0]['rows'][0][11]);
        $this->assertNull($report['cards'][1]['settlement_cash_minor']);
        $this->assertSame(19, array_sum(array_column($report['sections']['rekap']['rows'], 2)));
        $this->assertSame(5, array_sum(array_column($report['sections']['rekap']['rows'], 5)));
        $this->assertCount(2, $report['cards'][0]['rows']);
        $this->assertSame(100000, $report['cards'][0]['rows'][0][14]);
        $this->assertSame('Sisa dibawa balik', $report['cards'][0]['rows'][0][25]);
        $this->assertSame('Hitung tunai kurang', $report['cards'][0]['rows'][0][26]);
        $this->assertNull($report['cards'][0]['rows'][1][14]);
        $this->assertNull($report['cards'][0]['rows'][1][19]);
        $this->assertCount(count($report['sections']['per_rider']['headings']), $report['cards'][0]['rows'][0]);
        $this->assertSame($report['sections']['per_rider']['rows'][0], $report['cards'][0]['rows'][0]);
        $sheets = (new ManagementReportExport($report['sections']))->sheets();
        $this->assertSame([$report['sections']['per_rider']['headings'], ...$report['sections']['per_rider']['rows']], $sheets[0]->array());
        $this->get(FinanceDailyReport::getUrl())->assertOk()->assertSeeText('Laporan Harian Finance');
        Livewire::test(FinanceDailyReport::class)->set('date', $date)->call('applyFilters')
            ->call('exportExcel', 'per_rider')
            ->assertFileDownloaded("laporan-harian-finance-per_rider-{$date}.xlsx");

        $filtered = app(FinanceDailyReportService::class)->build($date, 'Pujasera 1', 'weekly');
        $this->assertCount(2, $filtered['cards']);
        $this->assertSame(14, array_sum(array_column($filtered['sections']['rekap']['rows'], 2)));
    }

    public function test_company_shortfall_and_per_rider_target_use_daily_closing_cups(): void
    {
        $rider = User::factory()->role(Role::STAFF)->create();
        $cart = $this->cart('B01', 'Both');
        $this->closing('2026-09-01', $rider, $cart, $this->coffee, 1406, 0, 0, 0, 1406, 10000);
        $this->closing('2026-09-02', $rider, $cart, $this->coffee, 1800, 0, 0, 0, 1800, 10000);
        DB::table('attendances')->insert([
            'operating_date' => '2026-09-03', 'user_id' => $rider->id, 'role' => 'STAFF',
            'clocked_in_at' => '2026-09-03 08:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $report = app(FinanceDailyReportService::class)->build('2026-09-02', null, 'monthly');
        $rows = $report['sections']['target_bulanan']['rows'];
        $this->assertSame(['2026-09-01', 1700, 1406, -294, 294], $rows[0]);
        $this->assertSame(['2026-09-02', 1700, 1800, 100, 194], $rows[1]);
        $this->assertSame(2, $report['sections']['target_rider']['rows'][0][2]);
        $this->assertSame(1, $report['sections']['target_rider']['rows'][0][3]);

        Livewire::test(FinanceDailyReport::class)->set('date', '2026-09-02')->call('applyFilters')
            ->set('companyCups', '1900')->set('riderCups', '2000')->call('saveTargets')->assertHasNoErrors();
        $this->assertDatabaseHas('finance_daily_targets', [
            'operating_date' => '2026-09-02', 'company_cups' => 1900, 'rider_cups' => 2000,
        ]);
        $this->assertDatabaseHas('audit_log', ['action' => 'finance_daily_target.saved']);
        $updated = app(FinanceDailyReportService::class)->build('2026-09-02', null, 'monthly');
        $this->assertSame(['2026-09-02', 1900, 1800, -100, 394], $updated['sections']['target_bulanan']['rows'][1]);
        $this->assertSame(2, $updated['sections']['target_rider']['rows'][0][3]);
    }

    public function test_weekly_ranking_breaks_cup_ties_by_rider_name(): void
    {
        $riders = [
            User::factory()->role(Role::STAFF)->create(['name' => 'Zaki']),
            User::factory()->role(Role::STAFF)->create(['name' => 'Ani']),
            User::factory()->role(Role::STAFF)->create(['name' => 'Beni']),
        ];
        foreach ([10, 10, 5] as $index => $cups) {
            $this->closing('2026-10-12', $riders[$index], $this->cart('C0'.$index, 'Legend item'),
                $this->coffee, $cups, 0, 0, 0, $cups, 10000);
        }
        DB::table('attendances')->insert([
            'operating_date' => '2026-10-13', 'user_id' => $riders[1]->id, 'role' => 'STAFF',
            'clocked_in_at' => '2026-10-13 08:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $attendee = User::factory()->role(Role::STAFF)->create(['name' => 'Dora']);
        $attendeeCart = $this->cart('C03', 'Legend item');
        DB::table('staff_assignments')->insert([
            'user_id' => $attendee->id, 'cart_id' => $attendeeCart->id,
            'operating_date' => '2026-10-13', 'assigned_by' => $this->finance->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('attendances')->insert([
            'operating_date' => '2026-10-13', 'user_id' => $attendee->id, 'role' => 'STAFF',
            'clocked_in_at' => '2026-10-13 08:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $report = app(FinanceDailyReportService::class)->build('2026-10-14', null, 'weekly');
        $rows = $report['sections']['peringkat']['rows'];
        $this->assertSame(['Ani', 'Zaki', 'Beni', 'Dora'], array_column($rows, 1));
        $this->assertSame(['2026-10-12', '2026-10-18'], $report['ranking_range']);
        $this->assertSame([10, 10, 5, 0], array_column($rows, 4));
        $this->assertSame(2, $rows[0][3]);
        $this->assertSame('5,0', $rows[0][5]);
        $this->assertSame(1, $rows[3][3]);
        $this->assertSame('C03', $rows[3][2]);
        $this->assertSame([], app(FinanceDailyReportService::class)
            ->build('2026-10-14', 'Both', 'weekly')['sections']['peringkat']['rows']);
    }

    public function test_module_gate_returns_403_and_cart_type_is_editable(): void
    {
        $this->actingAs(User::factory()->role(Role::BARISTA)->create())
            ->get(FinanceDailyReport::getUrl())->assertForbidden();
        $this->actingAs($this->finance);
        PermissionMatrix::set(Role::FINANCE, PanelModule::FINANCE_DAILY_REPORT, []);
        $this->get(FinanceDailyReport::getUrl())->assertForbidden();
        PermissionMatrix::set(Role::FINANCE, PanelModule::FINANCE_DAILY_REPORT, ['view']);
        $this->get(FinanceDailyReport::getUrl())->assertOk();
        Livewire::test(FinanceDailyReport::class)->call('saveTargets')->assertForbidden();
        PermissionMatrix::set(Role::FINANCE, PanelModule::FINANCE_DAILY_REPORT, ['view', 'edit']);
        $cart = $this->cart('D01', null);
        $cart->update(['bike_type' => 'Pujasera LTE']);
        $this->assertSame('Pujasera LTE', $cart->fresh()->bike_type);
        $this->assertSame(7, count(Cart::BIKE_TYPES));
        $this->get(CartResource::getUrl('edit', ['record' => $cart]))->assertOk()->assertSeeText('Jenis Coffee Bike');
        Livewire::test(EditCart::class, ['record' => $cart->getRouteKey()])
            ->fillForm(['code' => $cart->code, 'status' => 'active', 'bike_type' => 'Both'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Both', $cart->fresh()->bike_type);
    }

    public function test_target_edit_conflicts_are_detected_and_retries_do_not_duplicate_audit(): void
    {
        $first = Livewire::test(FinanceDailyReport::class)
            ->set('date', '2026-10-20')->call('applyFilters');
        $second = Livewire::test(FinanceDailyReport::class)
            ->set('date', '2026-10-20')->call('applyFilters');
        $first->set('companyCups', '1900')->call('saveTargets')->assertHasNoErrors();
        $second->set('companyCups', '2000')->call('saveTargets')->assertHasErrors(['companyCups']);
        $this->assertDatabaseHas('finance_daily_targets', [
            'operating_date' => '2026-10-20', 'company_cups' => 1900, 'revision' => 1,
        ]);
        $second->set('companyCups', '1900')->call('saveTargets')->assertHasNoErrors();
        $this->assertSame(1, DB::table('audit_log')->where('action', 'finance_daily_target.saved')->count());
    }

    public function test_rollback_refuses_to_drop_entered_target_or_bike_type(): void
    {
        $cart = $this->cart('E01', 'Pujasera 2');
        $migration = require database_path('migrations/2026_10_24_000082_create_finance_daily_report_settings.php');
        try {
            $migration->down();
            $this->fail('Rollback should preserve classified Coffee Bikes.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Rollback T-082 ditahan', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('carts', 'bike_type'));
        $cart->update(['bike_type' => null]);
        DB::table('finance_daily_targets')->insert([
            'operating_date' => '2026-10-20', 'company_cups' => 1700, 'rider_cups' => 38,
            'revision' => 1, 'updated_by' => $this->finance->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            $migration->down();
            $this->fail('Rollback should preserve entered targets.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Rollback T-082 ditahan', $exception->getMessage());
        }
        $this->assertDatabaseHas('finance_daily_targets', ['operating_date' => '2026-10-20']);
    }

    public function test_tampered_filters_and_revoked_export_are_rejected(): void
    {
        Livewire::test(FinanceDailyReport::class)
            ->set('appliedDate', 'bukan-tanggal')->call('exportExcel', 'all')->assertHasErrors(['date']);
        $page = Livewire::test(FinanceDailyReport::class);
        PermissionMatrix::set(Role::FINANCE, PanelModule::FINANCE_DAILY_REPORT, []);
        $page->call('exportExcel', 'all')->assertForbidden();
    }

    public function test_visible_tab_queries_only_its_report_group(): void
    {
        $service = app(FinanceDailyReportService::class);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $onlyDay = $service->build('2026-10-14', null, 'weekly', 'rekap');
            $dayQueries = count(DB::getQueryLog());
            DB::flushQueryLog();
            $all = $service->build('2026-10-14', null, 'weekly');
            $allQueries = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $this->assertArrayHasKey('rekap', $onlyDay['sections']);
        $this->assertArrayNotHasKey('peringkat', $onlyDay['sections']);
        $this->assertArrayHasKey('peringkat', $all['sections']);
        $this->assertLessThan($allQueries, $dayQueries);
    }

    private function cart(string $code, ?string $type): Cart
    {
        return Cart::query()->create(['code' => $code, 'status' => 'active',
            'kitchen_id' => $this->kitchen->id, 'bike_type' => $type]);
    }

    private function closing(string $date, User $rider, Cart $cart, Product $product,
        int $in, int $carry, int $remaining, int $wasted, int $sold, int $price): RiderClosing
    {
        $total = $sold * $price;
        $qris = min(30000, $total);
        $transfer = min(10000, $total - $qris);
        $closing = RiderClosing::query()->create([
            'operating_date' => $date, 'rider_id' => $rider->id, 'cart_id' => $cart->id,
            'total_minor' => $total, 'cogs_minor' => 0,
            'cash_minor' => $total - $qris - $transfer, 'qris_minor' => $qris, 'transfer_minor' => $transfer,
            'closed_at' => $date.' 17:00:00', 'note' => 'Sisa dibawa balik',
        ]);
        $closing->lines()->create([
            'product_id' => $product->id, 'qty_in' => $in, 'qty_carry' => $carry,
            'qty_remaining' => $remaining, 'qty_wasted' => $wasted, 'qty_sold' => $sold,
            'qty_sale_posted' => $sold, 'qty_waste_posted' => $wasted,
            'unit_price_minor' => $price, 'unit_cost_minor' => 0, 'subtotal_minor' => $total,
        ]);

        return $closing->refresh();
    }

    private function returnLedger(Cart $cart, Product $product, int $qty, string $reference, int $referenceId, string $createdAt): void
    {
        StockLedger::query()->create([
            'location_type' => 'cart', 'location_id' => $cart->id,
            'product_id' => $product->id, 'movement_type' => 'RETURN_OUT',
            'qty_delta' => -$qty, 'ref_type' => $reference, 'ref_id' => $referenceId,
            'actor_id' => $this->finance->id, 'kitchen_id' => $this->kitchen->id,
            'created_at' => $createdAt,
        ]);
    }
}
