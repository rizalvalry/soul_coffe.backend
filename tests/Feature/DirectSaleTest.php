<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\DirectSale;
use App\Models\JournalEntry;
use App\Models\Location;
use App\Models\LocationPriceVersion;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\Sale;
use App\Models\Settlement;
use App\Models\StaffAssignment;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\DirectSaleService;
use App\Services\Reporting\DashboardMetricsService;
use App\Services\Reporting\ManagementReportService;
use App\Services\Reporting\SalesActivityService;
use App\Services\SettlementService;
use App\Services\StockLedgerService;
use App\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Penjualan Langsung Kantor — a walk-in buyer at the central kitchen/office, entered by Finance
 * or Administrator directly into a cart's stock. Deliberately NOT a Sale: see the migration and
 * DirectSaleService docblocks for why this is a separate table and a separate service, and the
 * isolation test at the bottom for the guarantee that matters most — this feature must never
 * touch the existing gerobak-sale reporting path.
 */
class DirectSaleTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Cart $assignedCart;

    private Cart $idleCart;

    private Product $coffee;

    private User $finance;

    private User $admin;

    private User $staff;

    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();
        config(['soul.closing_started_on' => today()->addDay()->toDateString()]);

        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);

        $this->assignedCart = Cart::create(['code' => '0018', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);
        $this->idleCart = Cart::create(['code' => '0032', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);

        $this->coffee = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);

        ProductPriceVersion::create([
            'product_id' => $this->coffee->id,
            'cost_price_minor' => 10000,
            'sell_price_minor' => 20000,
            'effective_from' => now()->subDay(),
        ]);

        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->staff = User::factory()->role(Role::STAFF)->create();
        $this->rider = User::factory()->role(Role::RIDER)->create(['name' => 'Runner Uji Satu']);

        $location = Location::create(['name' => 'Pulomas', 'lat' => -6.18, 'lng' => 106.88]);

        StaffAssignment::create([
            'source' => 'hand_to_cart',
            'user_id' => $this->rider->id,
            'cart_id' => $this->assignedCart->id,
            'location_id' => $location->id,
            'operating_date' => Carbon::today()->toDateString(),
            'assigned_by' => $this->admin->id,
            'kitchen_id' => $this->kitchen->id,
        ]);
    }

    private function stockCart(Cart $cart, int $qty): void
    {
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::CART,
            locationId: $cart->id,
            productId: $this->coffee->id,
            movementType: MovementType::ALLOCATION_IN,
            qty: $qty,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
        );
    }

    private function cartStock(Cart $cart): int
    {
        return app(StockLedgerService::class)
            ->stockFor(StockLedgerService::CART, $cart->id, $this->coffee->id);
    }

    private function service(): DirectSaleService
    {
        return app(DirectSaleService::class);
    }

    public function test_direct_sale_posts_revenue_and_moving_cost_then_reverses_both_on_void(): void
    {
        $this->stockCart($this->assignedCart, 10);
        $sale = $this->service()->record($this->finance, $this->assignedCart->id,
            [['product_id' => $this->coffee->id, 'qty' => 2]], 'transfer');
        $this->assertSame(1, AuditLog::query()->where('action', 'direct_sale.created')
            ->where('subject_id', $sale->id)->count());

        $revenue = JournalEntry::query()->where('source_type', 'direct_sale')
            ->where('source_id', $sale->id)->where('purpose', 'DIRECT_SALE')->firstOrFail();
        $movementId = StockLedger::query()->where('ref_type', 'direct_sale')
            ->where('ref_id', $sale->id)->where('movement_type', MovementType::SALE_OUT)->firstOrFail()->id;
        $cogs = JournalEntry::query()->where('source_type', 'stock_ledger')
            ->where('source_id', $movementId)->where('purpose', 'SALE_COGS')->firstOrFail();
        $this->assertSame(['1-1400' => [40000, 0], '4-1200' => [0, 40000]],
            $revenue->lines()->with('account')->get()->mapWithKeys(fn ($line): array => [$line->account->code => [$line->debit_minor, $line->credit_minor]])->all());
        $this->assertSame(['5-1100' => [20000, 0], '1-1600' => [0, 20000]],
            $cogs->lines()->with('account')->get()->mapWithKeys(fn ($line): array => [$line->account->code => [$line->debit_minor, $line->credit_minor]])->all());

        $this->service()->void($sale, $this->finance, 'Salah input');
        $this->assertSame(1, AuditLog::query()->where('action', 'direct_sale.voided')
            ->where('subject_id', $sale->id)->count());
        $this->assertNotNull($revenue->fresh()->reversal);
        $this->assertNotNull($cogs->fresh()->reversal);
        $this->assertSame(2, JournalEntry::query()->where('purpose', 'REVERSAL')->count());
    }

    public function test_active_included_pbjt_splits_direct_sale_and_reversal_balances(): void
    {
        $management = User::factory()->role(Role::MANAGEMENT)->create();
        app(TaxService::class)->saveSetting($management, [
            'tax_type' => 'PBJT', 'is_active' => true, 'rate_bps' => 1000,
            'price_mode' => 'INCLUDED', 'scheme' => null,
            'effective_from' => today()->toDateString(), 'effective_until' => null,
            'legal_note' => null, 'monthly_threshold_minor' => null, 'reason' => 'Tarif uji baru',
        ]);
        ProductPriceVersion::query()->where('product_id', $this->coffee->id)->update(['sell_price_minor' => 110000]);
        $this->stockCart($this->assignedCart, 1);
        $sale = $this->service()->record($this->finance, $this->assignedCart->id,
            [['product_id' => $this->coffee->id, 'qty' => 1]]);
        $journal = JournalEntry::query()->where('source_type', 'direct_sale')->where('source_id', $sale->id)
            ->where('purpose', 'DIRECT_SALE')->firstOrFail();
        $this->assertSame(['1-1100' => [110000, 0], '4-1200' => [0, 100000], '2-1500' => [0, 10000]],
            $journal->lines()->with('account')->get()->mapWithKeys(fn ($line): array => [$line->account->code => [$line->debit_minor, $line->credit_minor]])->all());
        $this->service()->void($sale, $this->finance, 'Salah catat penjualan');
        $this->assertNotNull($journal->fresh()->reversal);
        $this->assertSame(110000, (int) $journal->reversal->lines()->sum('debit_minor'));
        $this->assertSame(110000, (int) $journal->reversal->lines()->sum('credit_minor'));
    }

    public function test_included_pbjt_rounding_can_post_zero_revenue_without_empty_line(): void
    {
        $management = User::factory()->role(Role::MANAGEMENT)->create();
        app(TaxService::class)->saveSetting($management, [
            'tax_type' => 'PBJT', 'is_active' => true, 'rate_bps' => 10000,
            'price_mode' => 'INCLUDED', 'scheme' => null,
            'effective_from' => today()->toDateString(), 'effective_until' => null,
            'legal_note' => null, 'monthly_threshold_minor' => null, 'reason' => 'Tarif uji batas',
        ]);
        ProductPriceVersion::query()->where('product_id', $this->coffee->id)->update(['sell_price_minor' => 1]);
        $this->stockCart($this->assignedCart, 1);

        $sale = $this->service()->record($this->finance, $this->assignedCart->id,
            [['product_id' => $this->coffee->id, 'qty' => 1]]);
        $journal = JournalEntry::query()->where('source_type', 'direct_sale')->where('source_id', $sale->id)
            ->where('purpose', 'DIRECT_SALE')->firstOrFail();
        $this->assertSame(['1-1100' => [1, 0], '2-1500' => [0, 1]],
            $journal->lines()->with('account')->get()->mapWithKeys(fn ($line): array => [$line->account->code => [$line->debit_minor, $line->credit_minor]])->all());
        $this->assertSame(0, $this->cartStock($this->assignedCart));
    }

    public function test_unpriced_direct_sale_does_not_move_stock_or_create_journals(): void
    {
        $this->stockCart($this->assignedCart, 3);
        ProductPriceVersion::query()->where('product_id', $this->coffee->id)->delete();

        try {
            $this->service()->record($this->finance, $this->assignedCart->id,
                [['product_id' => $this->coffee->id, 'qty' => 1]]);
            $this->fail('Unpriced direct sale was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Harga jual', $exception->getMessage());
        }

        $this->assertSame(3, $this->cartStock($this->assignedCart));
        $this->assertSame(0, DirectSale::query()->count());
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_zero_price_direct_sale_does_not_omit_cogs(): void
    {
        $this->stockCart($this->assignedCart, 3);
        ProductPriceVersion::query()->where('product_id', $this->coffee->id)->update(['sell_price_minor' => 0]);

        $this->expectException(RuntimeException::class);
        try {
            $this->service()->record($this->finance, $this->assignedCart->id,
                [['product_id' => $this->coffee->id, 'qty' => 1]]);
        } finally {
            $this->assertSame(3, $this->cartStock($this->assignedCart));
            $this->assertSame(0, DirectSale::query()->count());
            $this->assertSame(0, JournalEntry::query()->count());
        }
    }

    // ── Who may record ───────────────────────────────────────────────────────

    public function test_finance_can_record_a_direct_sale(): void
    {
        $this->stockCart($this->assignedCart, 10);

        $sale = $this->service()->record(
            $this->finance,
            $this->assignedCart->id,
            [['product_id' => $this->coffee->id, 'qty' => 2]],
        );

        $this->assertSame(2, $sale->total_qty);
        $this->assertSame(8, $this->cartStock($this->assignedCart));
    }

    public function test_administrator_can_record_a_direct_sale(): void
    {
        $this->stockCart($this->assignedCart, 10);

        $sale = $this->service()->record(
            $this->admin,
            $this->assignedCart->id,
            [['product_id' => $this->coffee->id, 'qty' => 2]],
        );

        $this->assertSame(2, $sale->total_qty);
    }

    public function test_staff_cannot_record_a_direct_sale(): void
    {
        $this->stockCart($this->assignedCart, 10);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Hanya Finance atau Administrator');

        $this->service()->record(
            $this->staff,
            $this->assignedCart->id,
            [['product_id' => $this->coffee->id, 'qty' => 2]],
        );
    }

    public function test_rider_cannot_record_a_direct_sale(): void
    {
        $this->stockCart($this->assignedCart, 10);

        $this->expectException(RuntimeException::class);

        $this->service()->record(
            $this->rider,
            $this->assignedCart->id,
            [['product_id' => $this->coffee->id, 'qty' => 2]],
        );
    }

    // ── Stock ─────────────────────────────────────────────────────────────────

    public function test_recording_decrements_the_chosen_carts_stock_via_a_sale_out_ledger_row(): void
    {
        $this->stockCart($this->assignedCart, 10);

        $sale = $this->service()->record(
            $this->finance,
            $this->assignedCart->id,
            [['product_id' => $this->coffee->id, 'qty' => 3]],
        );

        $this->assertSame(7, $this->cartStock($this->assignedCart));

        $this->assertTrue(
            StockLedger::query()
                ->where('movement_type', MovementType::SALE_OUT)
                ->where('ref_type', 'direct_sale')
                ->where('ref_id', $sale->id)
                ->where('location_type', StockLedgerService::CART)
                ->where('location_id', $this->assignedCart->id)
                ->where('qty_delta', -3)
                ->exists(),
        );
    }

    public function test_insufficient_stock_is_refused_with_the_product_name_and_available_qty(): void
    {
        $this->stockCart($this->assignedCart, 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stok Coffee Bike untuk Kopi Susu hanya 3 cup.');

        $this->service()->record(
            $this->finance,
            $this->assignedCart->id,
            [['product_id' => $this->coffee->id, 'qty' => 5]],
        );
    }

    // ── The cart dropdown's two valid paths ──────────────────────────────────

    public function test_picking_a_cart_with_no_assignment_today_works(): void
    {
        $this->stockCart($this->idleCart, 10);

        $this->assertFalse(
            StaffAssignment::query()
                ->where('cart_id', $this->idleCart->id)
                ->whereDate('operating_date', Carbon::today()->toDateString())
                ->exists(),
        );

        $sale = $this->service()->record(
            $this->finance,
            $this->idleCart->id,
            [['product_id' => $this->coffee->id, 'qty' => 2]],
        );

        $this->assertSame($this->idleCart->id, $sale->cart_id);
        $this->assertSame(8, $this->cartStock($this->idleCart));
    }

    public function test_picking_a_cart_with_an_assignment_today_also_works(): void
    {
        $this->stockCart($this->assignedCart, 10);

        $sale = $this->service()->record(
            $this->finance,
            $this->assignedCart->id,
            [['product_id' => $this->coffee->id, 'qty' => 2]],
        );

        $this->assertSame($this->assignedCart->id, $sale->cart_id);
    }

    // ── Price pinning ────────────────────────────────────────────────────────

    public function test_buyer_custom_price_and_reason_are_pinned_and_used_by_margin_report(): void
    {
        $this->stockCart($this->assignedCart, 2);
        $sale = $this->service()->record($this->finance, $this->assignedCart->id, [[
            'product_id' => $this->coffee->id, 'qty' => 2,
            'unit_price_minor' => 9000, 'price_reason' => 'Pesanan Polri',
        ]], buyerName: 'Polri');
        $this->assertSame('Polri', $sale->buyer_name);
        $this->assertSame(18000, $sale->total_amount_minor);
        $this->assertSame(9000, $sale->lines->first()->unit_price_minor);
        $this->assertSame(10000, $sale->lines->first()->unit_cost_minor);
        $this->assertSame('Pesanan Polri', $sale->lines->first()->price_reason);
        $report = app(ManagementReportService::class)->build(today(), today());
        $this->assertSame(18000, $report['sections']['margin_produk']['rows'][0][1]);
        $this->assertSame(20000, $report['sections']['margin_produk']['rows'][0][2]);
        $this->assertSame(-2000, $report['sections']['margin_produk']['rows'][0][3]);
    }

    public function test_different_custom_price_requires_reason_and_rejects_decimals(): void
    {
        $this->stockCart($this->idleCart, 2);
        try {
            $this->service()->record($this->finance, $this->idleCart->id, [[
                'product_id' => $this->coffee->id, 'qty' => 1, 'unit_price_minor' => 9000,
            ]]);
            $this->fail('Missing reason should be rejected.');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
        }
        $this->assertDatabaseCount('direct_sales', 0);
        $this->expectException(ValidationException::class);
        $this->service()->record($this->finance, $this->idleCart->id, [[
            'product_id' => $this->coffee->id, 'qty' => 1,
            'unit_price_minor' => '9000.5', 'price_reason' => 'Pesanan',
        ]]);
    }

    public function test_zero_custom_price_is_valid_with_a_reason(): void
    {
        $this->stockCart($this->idleCart, 1);
        $sale = $this->service()->record($this->finance, $this->idleCart->id, [[
            'product_id' => $this->coffee->id, 'qty' => 1,
            'unit_price_minor' => 0, 'price_reason' => 'Sampel pesanan',
        ]]);
        $this->assertSame(0, $sale->total_amount_minor);
        $this->assertSame(0, $sale->lines->first()->unit_price_minor);
    }

    public function test_direct_sale_uses_product_price_regardless_of_cart_location(): void
    {
        $this->stockCart($this->assignedCart, 1);
        $this->stockCart($this->idleCart, 1);
        $locationId = StaffAssignment::query()->where('cart_id', $this->assignedCart->id)->value('location_id');
        LocationPriceVersion::create([
            'product_id' => $this->coffee->id, 'location_id' => $locationId,
            'sell_price_minor' => 15000, 'effective_from' => now()->subMinute(),
        ]);
        $assigned = $this->service()->record($this->finance, $this->assignedCart->id,
            [['product_id' => $this->coffee->id, 'qty' => 1]]);
        $idle = $this->service()->record($this->finance, $this->idleCart->id,
            [['product_id' => $this->coffee->id, 'qty' => 1]]);
        $this->assertSame(20000, $assigned->total_amount_minor);
        $this->assertSame(20000, $idle->total_amount_minor);
    }

    public function test_price_is_pinned_from_the_current_price_version_at_record_time(): void
    {
        $this->stockCart($this->assignedCart, 10);

        $sale = $this->service()->record(
            $this->finance,
            $this->assignedCart->id,
            [['product_id' => $this->coffee->id, 'qty' => 2]],
        );

        $this->assertSame(40000, $sale->total_amount_minor);
        $this->assertSame(20000, $sale->lines->first()->unit_price_minor);
        $this->assertSame(10000, $sale->lines->first()->unit_cost_minor);

        // A later price change must not rewrite what was already recorded.
        ProductPriceVersion::create([
            'product_id' => $this->coffee->id,
            'cost_price_minor' => 15000,
            'sell_price_minor' => 30000,
            'effective_from' => now(),
        ]);

        $this->assertSame(20000, $sale->fresh(['lines'])->lines->first()->unit_price_minor);
        $this->assertSame(10000, $sale->fresh(['lines'])->lines->first()->unit_cost_minor);
    }

    // ── Void ─────────────────────────────────────────────────────────────────

    public function test_only_administrator_and_finance_may_void(): void
    {
        $this->stockCart($this->assignedCart, 10);
        $sale = $this->service()->record($this->finance, $this->assignedCart->id, [
            ['product_id' => $this->coffee->id, 'qty' => 3],
        ]);

        $this->expectException(RuntimeException::class);

        $this->service()->void($sale, $this->staff, 'Salah input');
    }

    public function test_void_has_no_time_window_an_old_direct_sale_can_still_be_voided(): void
    {
        $this->stockCart($this->assignedCart, 10);
        $sale = $this->service()->record($this->finance, $this->assignedCart->id, [
            ['product_id' => $this->coffee->id, 'qty' => 3],
        ]);

        $sale->forceFill(['occurred_at' => now()->subDays(3)])->save();

        $voided = $this->service()->void($sale, $this->admin, 'Koreksi setelah beberapa hari');

        $this->assertNotNull($voided->voided_at);
        $this->assertSame(10, $this->cartStock($this->assignedCart));
    }

    public function test_void_posts_a_sale_void_in_row_and_does_not_touch_the_original_sale_out_rows(): void
    {
        $this->stockCart($this->assignedCart, 10);
        $sale = $this->service()->record($this->finance, $this->assignedCart->id, [
            ['product_id' => $this->coffee->id, 'qty' => 3],
        ]);

        $this->service()->void($sale, $this->finance, 'Salah input');

        $this->assertTrue(
            StockLedger::query()
                ->where('movement_type', MovementType::SALE_VOID_IN)
                ->where('ref_type', 'direct_sale_void')
                ->where('ref_id', $sale->id)
                ->where('qty_delta', 3)
                ->exists(),
        );

        $this->assertTrue(
            StockLedger::query()
                ->where('movement_type', MovementType::SALE_OUT)
                ->where('ref_type', 'direct_sale')
                ->where('ref_id', $sale->id)
                ->where('qty_delta', -3)
                ->exists(),
        );
    }

    public function test_void_is_refused_once_reconciled(): void
    {
        $this->stockCart($this->assignedCart, 10);
        $sale = $this->service()->record($this->finance, $this->assignedCart->id, [
            ['product_id' => $this->coffee->id, 'qty' => 3],
        ]);

        Settlement::query()->create([
            'operating_date' => Carbon::today()->toDateString(),
            'cart_id' => $this->assignedCart->id,
            'staff_id' => $this->rider->id,
            'status' => 'RECONCILED',
            'cash_minor' => 60000,
            'declared_total_minor' => 60000,
            'expected_total_minor' => 60000,
            'variance_minor' => 0,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stock Opname');

        $this->service()->void($sale, $this->admin, 'Koreksi');
    }

    public function test_a_reason_is_required_to_void(): void
    {
        $this->stockCart($this->assignedCart, 10);
        $sale = $this->service()->record($this->finance, $this->assignedCart->id, [
            ['product_id' => $this->coffee->id, 'qty' => 3],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('wajib diisi');

        $this->service()->void($sale, $this->finance, '   ');
    }

    public function test_a_direct_sale_cannot_be_voided_twice(): void
    {
        $this->stockCart($this->assignedCart, 10);
        $sale = $this->service()->record($this->finance, $this->assignedCart->id, [
            ['product_id' => $this->coffee->id, 'qty' => 3],
        ]);

        $this->service()->void($sale, $this->finance, 'Pertama');

        $this->expectException(RuntimeException::class);

        $this->service()->void($sale->fresh(), $this->finance, 'Kedua');
    }

    // ── Dashboard exclusion ──────────────────────────────────────────────────

    public function test_a_voided_direct_sale_does_not_appear_in_the_dashboard_direct_sales_figure(): void
    {
        $this->stockCart($this->assignedCart, 10);

        $kept = $this->service()->record($this->finance, $this->assignedCart->id, [
            ['product_id' => $this->coffee->id, 'qty' => 2],
        ]);
        $voided = $this->service()->record($this->finance, $this->assignedCart->id, [
            ['product_id' => $this->coffee->id, 'qty' => 3],
        ]);

        $this->service()->void($voided, $this->finance, 'Salah input');

        $metrics = app(DashboardMetricsService::class)->directSalesToday();

        $this->assertSame(2, $metrics['total_qty']);
        $this->assertSame(40000, $metrics['total_amount_minor']);
        $this->assertSame(1, $metrics['transaction_count']);
    }

    // ── Total isolation from the existing gerobak-sale reporting path ────────

    public function test_direct_sales_never_touch_the_existing_sales_table_or_its_reporting(): void
    {
        $this->stockCart($this->assignedCart, 20);
        $this->stockCart($this->idleCart, 20);

        $one = $this->service()->record($this->finance, $this->assignedCart->id, [
            ['product_id' => $this->coffee->id, 'qty' => 2],
        ]);
        $two = $this->service()->record($this->admin, $this->idleCart->id, [
            ['product_id' => $this->coffee->id, 'qty' => 3],
        ]);
        $this->service()->void($two, $this->finance, 'Salah input');

        $this->assertSame(0, Sale::query()->count());

        $activity = app(SalesActivityService::class)->dayTotals();
        $this->assertSame(0, $activity['transactions']);
        $this->assertSame(0, $activity['cups']);
        $this->assertSame(0, $activity['revenue']);

        $draft = app(SettlementService::class)->draft($this->assignedCart);
        $line = collect($draft['lines'])->firstWhere('product_id', $this->coffee->id);
        // Only ALLOCATION_IN counted as issued — the direct sale's SALE_OUT is real stock
        // movement, but qty_sold in the gerobak settlement path comes from `sales`, which this
        // feature never writes to.
        $this->assertSame(0, $line['qty_sold']);

        $this->assertSame(1, DirectSale::query()->whereNull('voided_at')->count());
        $this->assertSame(2, DirectSale::query()->count());
    }
}
