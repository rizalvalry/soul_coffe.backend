<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\CentralKitchen;
use App\Models\Production;
use App\Models\ProductionLine;
use App\Models\RawMaterial;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Brewing, Phase 2: the same "cups brewed into the showcase" act CentralStockService::
 * brewIntoShowcase() already performs, now also consuming the raw materials each product's
 * recipe calls for — and refusing the whole brew up front if there isn't enough of any of them.
 *
 * PRODUCTS WITH NO RECIPE YET STILL BREW, RAW-MATERIAL-BLIND
 * -----------------------------------------------------------
 * A product with no active Recipe simply consumes nothing here — the exact behaviour
 * `brewIntoShowcase()` already had before Phase 2 existed. This is a deliberate, conservative
 * choice for a phased BOM rollout: not every sellable product realistically has a recipe entered
 * on day one, and refusing to brew an untracked product outright would break every kitchen that
 * hasn't finished data-entry yet (and every existing test that seeds zero recipes). Once a
 * product's recipe is entered, this method starts enforcing raw-material sufficiency for it
 * automatically — there is no separate switch to flip.
 */
class ProductionService
{
    public function __construct(
        private readonly StockLedgerService $ledger,
        private readonly RecipeService $recipes,
        private readonly CentralStockService $centralStock,
    ) {}

    /**
     * @param  array<int,int>  $qtyByProductId  product_id => cups brewed — the exact shape
     *                                          StoreShowcaseBrewRequest::quantities() already
     *                                          produces, unchanged by Phase 2.
     */
    public function brew(int $kitchenId, array $qtyByProductId, User $actor): Production
    {
        $kitchen = CentralKitchen::query()->findOrFail($kitchenId);
        $rows = $this->normalizeQuantities($qtyByProductId);

        /** @var array<int, ?Recipe> $recipeByProduct */
        $recipeByProduct = [];
        foreach (array_keys($rows) as $productId) {
            $recipeByProduct[$productId] = $this->recipes->currentFor($productId);
        }

        // Aggregate demand across EVERY product in this one brew request before anything is
        // locked. Two products sharing an ingredient (e.g. both a latte and a cappuccino need
        // milk) must have their combined demand checked together: checking product-by-product
        // could pass both individually while their sum exceeds what is actually on the shelf,
        // silently driving the raw-material store negative.
        $demand = [];
        foreach ($rows as $productId => $qty) {
            $recipe = $recipeByProduct[$productId];

            if ($recipe === null) {
                continue;
            }

            foreach ($recipe->recipeLines as $line) {
                $demand[$line->raw_material_id] = ($demand[$line->raw_material_id] ?? 0) + ($line->qty_per_unit * $qty);
            }
        }

        return DB::transaction(function () use ($kitchen, $rows, $recipeByProduct, $demand, $actor): Production {
            if ($demand !== []) {
                $locked = $this->ledger->lockAndProjectRawMaterials(
                    StockLedgerService::RAW_MATERIAL_STORE,
                    $kitchen->id,
                    array_keys($demand),
                );

                $this->assertSufficient($demand, $locked);
            }

            $production = Production::query()->create([
                'uuid' => (string) Str::uuid(),
                'kitchen_id' => $kitchen->id,
                'actor_id' => $actor->id,
                'produced_at' => now(),
            ]);

            foreach ($rows as $productId => $qty) {
                $recipe = $recipeByProduct[$productId];

                ProductionLine::query()->create([
                    'production_id' => $production->id,
                    'product_id' => $productId,
                    'qty_brewed' => $qty,
                    'recipe_id' => $recipe?->id,
                    'recipe_cost_minor' => $recipe?->computed_cost_minor,
                ]);
            }

            // Unchanged: the finished-product side of the brew, exactly as it worked before
            // Phase 2 (PRODUCTION_IN per product, its own audit log entry and notification).
            $this->centralStock->brewIntoShowcase($actor, $kitchen, $rows);

            foreach ($demand as $rawMaterialId => $qty) {
                $this->ledger->post(
                    locationType: StockLedgerService::RAW_MATERIAL_STORE,
                    locationId: $kitchen->id,
                    productId: null,
                    movementType: MovementType::RECIPE_CONSUME_OUT,
                    qty: $qty,
                    actorId: $actor->id,
                    kitchenId: $kitchen->id,
                    refType: 'production',
                    refId: $production->id,
                    rawMaterialId: $rawMaterialId,
                );
            }

            return $production->load('lines.product', 'lines.recipe');
        });
    }

    /**
     * @param  array<int,int>  $demand  raw_material_id => qty needed
     * @param  array<int,int>  $locked  raw_material_id => qty on the shelf, under lock
     */
    private function assertSufficient(array $demand, array $locked): void
    {
        $shortages = [];

        foreach ($demand as $rawMaterialId => $needed) {
            $available = $locked[$rawMaterialId] ?? 0;

            if ($available < $needed) {
                $rawMaterial = RawMaterial::query()->find($rawMaterialId);
                $name = $rawMaterial?->name ?? "#{$rawMaterialId}";
                $unit = $rawMaterial?->unit ?? '';

                $shortages[] = sprintf(
                    'Bahan baku %s kurang: butuh %d %s, stok %d %s.',
                    $name, $needed, $unit, $available, $unit,
                );
            }
        }

        if ($shortages !== []) {
            throw new RuntimeException(implode(' ', $shortages));
        }
    }

    /**
     * @param  array<int|string,int|string>  $qtyByProductId
     * @return array<int,int>
     */
    private function normalizeQuantities(array $qtyByProductId): array
    {
        $rows = [];

        foreach ($qtyByProductId as $productId => $qty) {
            $productId = (int) $productId;
            $qty = (int) $qty;

            if ($qty === 0) {
                continue;
            }

            if ($qty < 0) {
                throw new RuntimeException('Jumlah cups tidak boleh negatif.');
            }

            $rows[$productId] = $qty;
        }

        if ($rows === []) {
            throw new RuntimeException('Tidak ada cups yang diinput.');
        }

        return $rows;
    }
}
