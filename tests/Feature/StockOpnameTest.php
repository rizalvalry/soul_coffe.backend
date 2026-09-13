<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\StockOpnameStatus;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\StockOpname;
use App\Models\User;
use App\Services\StockLedgerService;
use App\Services\StockOpnameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Stock Opname — the correction that never had a way in before.
 *
 * The test worth reading first is `test_applying_measures_against_live_stock_not_the_stale_draft`.
 * That is the entire reason the correction is computed twice (once informationally at the count,
 * once for real at apply) rather than once: a sale between the physical count and the click that
 * applies it must not be silently undone by a stale correction.
 */
class StockOpnameTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Cart $cart;

    private Product $coffee;

    private Product $tea;

    private User $admin;

    private User $finance;

    private User $barista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);

        $this->cart = Cart::create(['code' => '0018', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);

        $this->coffee = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);
        $this->tea = Product::create([
            'code' => 'TEH', 'name' => 'Teh Manis', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 2, 'is_active' => true,
        ]);

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->barista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
    }

    private function service(): StockOpnameService
    {
        return app(StockOpnameService::class);
    }

    private function stockOf(string $type, int $locationId, Product $product): int
    {
        return app(StockLedgerService::class)->stockFor($type, $locationId, $product->id);
    }

    private function seedKitchenStock(int $qty): void
    {
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::KITCHEN,
            locationId: $this->kitchen->id,
            productId: $this->coffee->id,
            movementType: MovementType::PRODUCTION_IN,
            qty: $qty,
            actorId: $this->barista->id,
            kitchenId: $this->kitchen->id,
        );
    }

    // ── Creating a draft ─────────────────────────────────────────────────────

    public function test_draft_lines_show_every_active_product_and_the_current_ledger_figure(): void
    {
        $this->seedKitchenStock(50);

        $lines = $this->service()->draftLines(StockLedgerService::KITCHEN, $this->kitchen->id);

        $coffeeLine = collect($lines)->firstWhere('product_id', $this->coffee->id);
        $teaLine = collect($lines)->firstWhere('product_id', $this->tea->id);

        $this->assertSame(50, $coffeeLine['system_qty']);
        // Never stocked at all, but still listed — a real physical count checks every product,
        // including the ones the ledger thinks are at zero.
        $this->assertSame(0, $teaLine['system_qty']);
    }

    public function test_creating_a_draft_computes_the_variance_but_touches_no_stock(): void
    {
        $this->seedKitchenStock(50);

        $opname = $this->service()->create(
            locationType: StockLedgerService::KITCHEN,
            locationId: $this->kitchen->id,
            counts: [['product_id' => $this->coffee->id, 'counted_qty' => 45]],
            reason: 'Hitungan rutin akhir pekan',
            actor: $this->admin,
        );

        $this->assertSame(StockOpnameStatus::DRAFT, $opname->status);
        $line = $opname->lines->firstWhere('product_id', $this->coffee->id);
        $this->assertSame(50, $line->system_qty_at_count);
        $this->assertSame(45, $line->counted_qty);
        $this->assertSame(-5, $line->variance_qty);
        $this->assertNull($line->applied_delta);

        // Nothing posted yet.
        $this->assertSame(50, $this->stockOf(StockLedgerService::KITCHEN, $this->kitchen->id, $this->coffee));
    }

    public function test_a_reason_is_required(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Alasan stock opname wajib diisi.');

        $this->service()->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 10]],
            '   ',
            $this->admin,
        );
    }

    public function test_a_negative_count_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service()->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => -1]],
            'x',
            $this->admin,
        );
    }

    public function test_only_administrator_or_finance_may_create_one(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service()->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 10]],
            'x',
            $this->barista,
        );
    }

    public function test_finance_may_also_create_one(): void
    {
        $opname = $this->service()->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 10]],
            'x',
            $this->finance,
        );

        $this->assertNotNull($opname->id);
    }

    // ── Applying ─────────────────────────────────────────────────────────────

    public function test_applying_posts_an_opname_adjustment_bringing_stock_to_the_count(): void
    {
        $this->seedKitchenStock(50);

        $opname = $this->service()->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 45]],
            'Kekurangan ditemukan',
            $this->admin,
        );

        $applied = $this->service()->apply($opname, $this->admin);

        $this->assertSame(StockOpnameStatus::APPLIED, $applied->status);
        $this->assertSame($this->admin->id, $applied->applied_by);
        $this->assertNotNull($applied->applied_at);

        $this->assertSame(45, $this->stockOf(StockLedgerService::KITCHEN, $this->kitchen->id, $this->coffee));

        $this->assertTrue(
            \App\Models\StockLedger::query()
                ->where('movement_type', MovementType::OPNAME_ADJUSTMENT)
                ->where('ref_type', 'stock_opname')
                ->where('ref_id', $opname->id)
                ->where('qty_delta', -5)
                ->exists(),
        );
    }

    /**
     * The reason the correction is computed twice. A sale happens between the physical count and
     * the click that applies it — the correction posted must still bring stock to exactly the
     * counted quantity, not blindly replay the stale variance from the moment of counting.
     */
    public function test_applying_measures_against_live_stock_not_the_stale_draft(): void
    {
        $this->seedKitchenStock(50);

        $opname = $this->service()->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 45]],
            'Hitungan sore',
            $this->admin,
        );

        // 10 more cups arrive after the count was taken but before it is applied.
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::KITCHEN,
            locationId: $this->kitchen->id,
            productId: $this->coffee->id,
            movementType: MovementType::PRODUCTION_IN,
            qty: 10,
            actorId: $this->barista->id,
            kitchenId: $this->kitchen->id,
        );

        $this->assertSame(60, $this->stockOf(StockLedgerService::KITCHEN, $this->kitchen->id, $this->coffee));

        $applied = $this->service()->apply($opname, $this->admin);

        // Stock ends at exactly 45 — the counted figure — not 55 (60 - the stale 5 variance).
        $this->assertSame(45, $this->stockOf(StockLedgerService::KITCHEN, $this->kitchen->id, $this->coffee));

        $line = $applied->lines->firstWhere('product_id', $this->coffee->id);
        $this->assertSame(60, $line->system_qty_at_apply);
        $this->assertSame(-15, $line->applied_delta);
        // The draft-time figures are untouched — the audit trail keeps both.
        $this->assertSame(50, $line->system_qty_at_count);
        $this->assertSame(-5, $line->variance_qty);
    }

    public function test_a_line_with_no_real_difference_at_apply_time_posts_nothing(): void
    {
        $this->seedKitchenStock(50);

        $opname = $this->service()->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 50]],
            'Hitungan rutin',
            $this->admin,
        );

        $this->service()->apply($opname, $this->admin);

        $this->assertFalse(
            \App\Models\StockLedger::query()
                ->where('movement_type', MovementType::OPNAME_ADJUSTMENT)
                ->where('ref_id', $opname->id)
                ->exists(),
        );
    }

    public function test_a_positive_variance_adds_stock(): void
    {
        $this->seedKitchenStock(50);

        $opname = $this->service()->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 55]],
            'Ternyata lebih banyak dari catatan',
            $this->admin,
        );

        $this->service()->apply($opname, $this->admin);

        $this->assertSame(55, $this->stockOf(StockLedgerService::KITCHEN, $this->kitchen->id, $this->coffee));
    }

    public function test_an_applied_opname_cannot_be_applied_twice(): void
    {
        $this->seedKitchenStock(50);

        $opname = $this->service()->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 45]],
            'x',
            $this->admin,
        );

        $this->service()->apply($opname, $this->admin);

        $this->expectException(RuntimeException::class);
        $this->service()->apply($opname, $this->admin);
    }

    public function test_a_cart_can_be_opnamed_too(): void
    {
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::CART,
            locationId: $this->cart->id,
            productId: $this->coffee->id,
            movementType: MovementType::ALLOCATION_IN,
            qty: 20,
            actorId: $this->barista->id,
            kitchenId: $this->kitchen->id,
        );

        $opname = $this->service()->create(
            StockLedgerService::CART,
            $this->cart->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 18]],
            'Hitungan gerobak sore',
            $this->admin,
        );

        $this->service()->apply($opname, $this->admin);

        $this->assertSame(18, $this->stockOf(StockLedgerService::CART, $this->cart->id, $this->coffee));
    }

    // ── Cancelling ───────────────────────────────────────────────────────────

    public function test_a_draft_can_be_cancelled_without_touching_stock(): void
    {
        $this->seedKitchenStock(50);

        $opname = $this->service()->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 45]],
            'Salah lokasi',
            $this->admin,
        );

        $cancelled = $this->service()->cancel($opname, $this->admin);

        $this->assertSame(StockOpnameStatus::CANCELLED, $cancelled->status);
        $this->assertSame(50, $this->stockOf(StockLedgerService::KITCHEN, $this->kitchen->id, $this->coffee));
    }

    public function test_an_applied_opname_cannot_be_cancelled(): void
    {
        $this->seedKitchenStock(50);

        $opname = $this->service()->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 45]],
            'x',
            $this->admin,
        );

        $this->service()->apply($opname, $this->admin);

        $this->expectException(RuntimeException::class);
        $this->service()->cancel($opname, $this->admin);
    }

    public function test_an_unknown_location_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service()->create(
            StockLedgerService::KITCHEN,
            999999,
            [['product_id' => $this->coffee->id, 'counted_qty' => 10]],
            'x',
            $this->admin,
        );
    }
}
