<?php

namespace Tests\Feature\Reporting;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\User;
use App\Services\Reporting\StockOverviewService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "How many cups exist right now, before they are divided up" — the number the panel could not
 * answer before this page existed.
 *
 * Everything is asserted against real ledger movements rather than a counter, because that is
 * how the figure is produced (R6: stock is SUM(qty_delta), never a stored total).
 */
class StockOverviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockOverviewService $service;
    private StockLedgerService $ledger;
    private CentralKitchen $kitchen;
    private Product $coffee;
    private Product $tea;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(StockOverviewService::class);
        $this->ledger = app(StockLedgerService::class);

        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur A', 'address' => 'Jl. Uji 1', 'open_at' => '05:00', 'close_at' => '20:00',
        ]);

        $this->coffee = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);
        $this->tea = Product::create([
            'code' => 'TEH', 'name' => 'Teh Manis', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 2, 'is_active' => true,
        ]);

        $this->actor = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
    }

    private function cart(string $code, string $status = 'active'): Cart
    {
        return Cart::create(['code' => $code, 'status' => $status, 'kitchen_id' => $this->kitchen->id]);
    }

    private function brew(int $productId, int $qty): void
    {
        $this->ledger->post(
            locationType: StockLedgerService::KITCHEN,
            locationId: $this->kitchen->id,
            productId: $productId,
            movementType: MovementType::PRODUCTION_IN,
            qty: $qty,
            actorId: $this->actor->id,
            kitchenId: $this->kitchen->id,
        );
    }

    private function toCart(Cart $cart, int $productId, int $qty): void
    {
        $this->ledger->transfer(
            fromType: StockLedgerService::KITCHEN,
            fromId: $this->kitchen->id,
            toType: StockLedgerService::CART,
            toId: $cart->id,
            productId: $productId,
            outType: MovementType::ALLOCATION_OUT,
            inType: MovementType::ALLOCATION_IN,
            qty: $qty,
            actorId: $this->actor->id,
            kitchenId: $this->kitchen->id,
        );
    }

    public function test_it_reports_nothing_but_zeroes_before_anything_is_brewed(): void
    {
        $this->cart('0001');

        $snap = $this->service->snapshot();

        $this->assertSame(0, $snap['grand']);
        $this->assertSame(0, $snap['kitchen_grand']);
        $this->assertSame(0, $snap['cart_grand']);
        // A column still exists for every product, so the grid keeps its shape.
        $this->assertSame(0, $snap['grand_totals'][$this->coffee->id]);
        $this->assertSame(0, $snap['grand_totals'][$this->tea->id]);
    }

    public function test_brewed_cups_show_at_the_kitchen_and_in_the_grand_total(): void
    {
        $this->brew($this->coffee->id, 40);
        $this->brew($this->tea->id, 10);

        $snap = $this->service->snapshot();

        $this->assertSame(50, $snap['kitchen_grand']);
        $this->assertSame(0, $snap['cart_grand']);
        $this->assertSame(50, $snap['grand']);
        $this->assertSame(40, $snap['kitchens']->first()['qty'][$this->coffee->id]);
        $this->assertSame(50, $snap['kitchens']->first()['total']);
    }

    /** The whole point of the page: the split between "not yet handed out" and "already out". */
    public function test_handing_cups_to_a_cart_moves_them_between_the_two_subtotals(): void
    {
        $cart = $this->cart('0018');

        $this->brew($this->coffee->id, 40);
        $this->toCart($cart, $this->coffee->id, 15);

        $snap = $this->service->snapshot();

        $this->assertSame(25, $snap['kitchen_grand']);
        $this->assertSame(15, $snap['cart_grand']);
        // The company total is unchanged by a transfer — cups moved, none appeared or vanished.
        $this->assertSame(40, $snap['grand']);

        $row = $snap['carts']->firstWhere(fn (array $r): bool => $r['cart']->code === '0018');
        $this->assertSame(15, $row['qty'][$this->coffee->id]);
    }

    public function test_totals_add_up_across_several_carts_and_products(): void
    {
        $a = $this->cart('0001');
        $b = $this->cart('0002');

        $this->brew($this->coffee->id, 100);
        $this->brew($this->tea->id, 50);
        $this->toCart($a, $this->coffee->id, 20);
        $this->toCart($b, $this->coffee->id, 30);
        $this->toCart($b, $this->tea->id, 5);

        $snap = $this->service->snapshot();

        $this->assertSame(50, $snap['cart_totals'][$this->coffee->id]);
        $this->assertSame(5, $snap['cart_totals'][$this->tea->id]);
        $this->assertSame(50, $snap['kitchen_totals'][$this->coffee->id]);
        $this->assertSame(45, $snap['kitchen_totals'][$this->tea->id]);
        $this->assertSame(150, $snap['grand']);
    }

    /** Cups can sit on a cart that is out of service; a total that dropped them would be wrong. */
    public function test_a_cart_under_maintenance_is_still_counted(): void
    {
        $broken = $this->cart('0009', 'maintenance');

        $this->brew($this->coffee->id, 20);
        $this->toCart($broken, $this->coffee->id, 8);

        $snap = $this->service->snapshot();

        $this->assertSame(8, $snap['cart_grand']);
        $this->assertNotNull($snap['carts']->firstWhere(fn (array $r): bool => $r['cart']->code === '0009'));
    }

    public function test_a_retired_cart_is_left_out(): void
    {
        $this->cart('0001');
        $this->cart('0099', 'retired');

        $codes = $this->service->snapshot()['carts']
            ->map(fn (array $r): string => $r['cart']->code)
            ->all();

        $this->assertSame(['0001'], $codes);
    }

    public function test_inactive_products_are_not_columns(): void
    {
        Product::create([
            'code' => 'LAMA', 'name' => 'Produk Lama', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 9, 'is_active' => false,
        ]);

        $names = $this->service->snapshot()['products']->pluck('name')->all();

        $this->assertSame(['Kopi Susu', 'Teh Manis'], $names);
    }

    /** Products keep the paper form's order — the same order the barista's Add Stock form uses. */
    public function test_products_follow_sort_order(): void
    {
        Product::create([
            'code' => 'AWAL', 'name' => 'Produk Pertama', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 0, 'is_active' => true,
        ]);

        $names = $this->service->snapshot()['products']->pluck('name')->all();

        $this->assertSame(['Produk Pertama', 'Kopi Susu', 'Teh Manis'], $names);
    }

    // ── Bahan baku ───────────────────────────────────────────────────────

    private function rawMaterial(string $code, string $name, string $unit = 'g', ?int $reorderPoint = null): \App\Models\RawMaterial
    {
        return \App\Models\RawMaterial::create([
            'code' => $code, 'name' => $name, 'unit' => $unit,
            'reorder_point' => $reorderPoint, 'is_active' => true, 'sort_order' => 1,
        ]);
    }

    private function receiveRawMaterial(int $rawMaterialId, int $qty): void
    {
        $this->ledger->post(
            locationType: StockLedgerService::RAW_MATERIAL_STORE,
            locationId: $this->kitchen->id,
            productId: null,
            movementType: MovementType::PURCHASE_IN,
            qty: $qty,
            actorId: $this->actor->id,
            kitchenId: $this->kitchen->id,
            rawMaterialId: $rawMaterialId,
        );
    }

    public function test_raw_material_stock_appears_per_kitchen(): void
    {
        $milk = $this->rawMaterial('SUSU', 'Susu', 'ml');
        $this->receiveRawMaterial($milk->id, 5000);

        $snap = $this->service->snapshot();

        $this->assertSame(['Susu'], $snap['raw_materials']->pluck('name')->all());
        $this->assertSame(5000, $snap['raw_material_rows']->first()['qty'][$milk->id]);
        $this->assertSame(5000, $snap['raw_material_totals'][$milk->id]);
    }

    public function test_raw_material_stock_is_summed_across_kitchens(): void
    {
        $second = CentralKitchen::create([
            'name' => 'Dapur B', 'address' => 'Jl. Uji 2', 'open_at' => '05:00', 'close_at' => '20:00',
        ]);

        $milk = $this->rawMaterial('SUSU', 'Susu', 'ml');
        $this->receiveRawMaterial($milk->id, 5000);

        $this->ledger->post(
            locationType: StockLedgerService::RAW_MATERIAL_STORE,
            locationId: $second->id,
            productId: null,
            movementType: MovementType::PURCHASE_IN,
            qty: 2000,
            actorId: $this->actor->id,
            kitchenId: $second->id,
            rawMaterialId: $milk->id,
        );

        $this->assertSame(7000, $this->service->snapshot()['raw_material_totals'][$milk->id]);
    }

    public function test_a_material_at_or_below_its_reorder_point_is_flagged(): void
    {
        $beans = $this->rawMaterial('KOPI', 'Biji Kopi', 'g', 1000);
        $this->receiveRawMaterial($beans->id, 900);

        $snap = $this->service->snapshot();

        $this->assertTrue($snap['raw_material_below'][$beans->id]);
        $this->assertTrue($snap['raw_material_rows']->first()['below'][$beans->id]);
    }

    public function test_a_material_above_its_reorder_point_is_not_flagged(): void
    {
        $beans = $this->rawMaterial('KOPI', 'Biji Kopi', 'g', 1000);
        $this->receiveRawMaterial($beans->id, 1500);

        $this->assertFalse($this->service->snapshot()['raw_material_below'][$beans->id]);
    }

    /** Ambang yang belum disetel bukan ambang nol: bahan tanpa reorder point tidak pernah ditandai. */
    public function test_a_material_without_a_reorder_point_is_never_flagged(): void
    {
        $sugar = $this->rawMaterial('GULA', 'Gula', 'g');

        $this->assertFalse($this->service->snapshot()['raw_material_below'][$sugar->id]);
    }

    public function test_inactive_raw_materials_are_not_columns(): void
    {
        $this->rawMaterial('SUSU', 'Susu', 'ml');
        \App\Models\RawMaterial::create([
            'code' => 'LAMA', 'name' => 'Bahan Lama', 'unit' => 'g',
            'is_active' => false, 'sort_order' => 9,
        ]);

        $this->assertSame(['Susu'], $this->service->snapshot()['raw_materials']->pluck('name')->all());
    }

    public function test_finished_goods_figures_are_untouched_by_raw_materials(): void
    {
        $milk = $this->rawMaterial('SUSU', 'Susu', 'ml');
        $this->receiveRawMaterial($milk->id, 5000);
        $this->brew($this->coffee->id, 40);

        $snap = $this->service->snapshot();

        // Bahan baku dihitung dalam ml, cups dalam cup: totalnya tidak boleh tercampur.
        $this->assertSame(40, $snap['grand']);
        $this->assertSame(40, $snap['kitchen_grand']);
    }
}
