<?php

namespace App\Services\Reporting;

use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Services\StockLedgerService;
use Illuminate\Support\Collection;

/**
 * Where every cup currently is: still in a kitchen showcase, or already out on a cart.
 *
 * The panel had no answer to "how many cups exist right now" — the barista could see their own
 * kitchen from the phone and each staff member their own cart, but nobody could see the total
 * before it was divided up. That is the number a production team plans against.
 *
 * Computed from `stock_ledger` (R6: append-only, stock is SUM(qty_delta), never a counter), so
 * these figures cannot drift from the movements that produced them — there is nothing else to
 * keep in sync.
 */
class StockOverviewService
{
    public function __construct(private readonly StockLedgerService $ledger) {}

    /**
     * @return array{
     *     products: Collection<int, Product>,
     *     kitchens: Collection<int, array{kitchen: CentralKitchen, qty: array<int,int>, total: int}>,
     *     carts: Collection<int, array{cart: Cart, qty: array<int,int>, total: int}>,
     *     kitchen_totals: array<int,int>,
     *     cart_totals: array<int,int>,
     *     grand_totals: array<int,int>,
     *     kitchen_grand: int,
     *     cart_grand: int,
     *     grand: int,
     * }
     */
    public function snapshot(): array
    {
        // Sellable products only, in the paper form's order — the same list the barista's Add
        // Stock form shows, so the two screens can be read side by side.
        $products = Product::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name', 'unit']);

        $productIds = $products->pluck('id')->all();

        $kitchens = CentralKitchen::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (CentralKitchen $kitchen): array => $this->row(
                'kitchen',
                $kitchen,
                $this->ledger->stockMap(StockLedgerService::KITCHEN, $kitchen->id),
                $productIds,
            ));

        // Retired carts are excluded but carts under maintenance are NOT: cups can be sitting on
        // a cart that is out of service, and a total that quietly omitted them would be wrong.
        $carts = Cart::query()
            ->where('status', '!=', 'retired')
            ->orderBy('code')
            ->get()
            ->map(fn (Cart $cart): array => $this->row(
                'cart',
                $cart,
                $this->ledger->stockMap(StockLedgerService::CART, $cart->id),
                $productIds,
            ));

        $kitchenTotals = $this->sumColumns($kitchens, $productIds);
        $cartTotals = $this->sumColumns($carts, $productIds);

        $grandTotals = [];
        foreach ($productIds as $id) {
            $grandTotals[$id] = ($kitchenTotals[$id] ?? 0) + ($cartTotals[$id] ?? 0);
        }

        return [
            'products' => $products,
            'kitchens' => $kitchens,
            'carts' => $carts,
            'kitchen_totals' => $kitchenTotals,
            'cart_totals' => $cartTotals,
            'grand_totals' => $grandTotals,
            'kitchen_grand' => array_sum($kitchenTotals),
            'cart_grand' => array_sum($cartTotals),
            'grand' => array_sum($grandTotals),
        ];
    }

    /**
     * @param  array<int,int>  $stockMap
     * @param  array<int,int>  $productIds
     * @return array{kitchen?: CentralKitchen, cart?: Cart, qty: array<int,int>, total: int}
     */
    private function row(string $key, CentralKitchen|Cart $location, array $stockMap, array $productIds): array
    {
        $qty = [];
        foreach ($productIds as $id) {
            // A product with no ledger row at this location is zero, not absent: the grid needs
            // a cell for every column or the columns stop lining up.
            $qty[$id] = (int) ($stockMap[$id] ?? 0);
        }

        return [$key => $location, 'qty' => $qty, 'total' => array_sum($qty)];
    }

    /**
     * @param  Collection<int, array{qty: array<int,int>}>  $rows
     * @param  array<int,int>  $productIds
     * @return array<int,int>
     */
    private function sumColumns(Collection $rows, array $productIds): array
    {
        $totals = [];
        foreach ($productIds as $id) {
            $totals[$id] = (int) $rows->sum(fn (array $row): int => $row['qty'][$id] ?? 0);
        }

        return $totals;
    }
}
