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
}
