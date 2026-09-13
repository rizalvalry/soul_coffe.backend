<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Recipe;
use App\Models\RecipeLine;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The bill of materials behind a product: what raw materials one finished unit consumes, and
 * what that currently costs.
 *
 * VERSIONED, NEVER EDITED IN PLACE
 * --------------------------------
 * The same reasoning as ProductPriceVersion (R10): a recipe correction is a new row, never an
 * edit of an old one, because a past production line may already point at that old version as
 * the recipe it actually used (production_lines.recipe_id). Editing history out from under a
 * production record would make that record's own cost snapshot a lie.
 */
class RecipeService
{
    public function __construct(private readonly StockLedgerService $ledger) {}

    /**
     * The active recipe for a product right now: highest effective_from <= now, is_active=true,
     * tied-broken by id desc — the exact rule Product::currentPriceVersion() uses for "the
     * current price version", applied here to "the current recipe".
     */
    public function currentFor(int $productId): ?Recipe
    {
        return Recipe::query()
            ->where('product_id', $productId)
            ->where('is_active', true)
            ->where('effective_from', '<=', now())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->with('recipeLines.rawMaterial')
            ->first();
    }

    /**
     * Creates a NEW version of a product's recipe. Never touches an existing recipe row.
     *
     * @param  array<int, array{raw_material_id: int, qty_per_unit: int}>  $lines
     */
    public function create(int $productId, array $lines, User $actor): Recipe
    {
        if ($lines === []) {
            throw new RuntimeException('Resep harus punya minimal satu bahan baku.');
        }

        $seen = [];
        foreach ($lines as $line) {
            $rawMaterialId = (int) ($line['raw_material_id'] ?? 0);
            $qtyPerUnit = (int) ($line['qty_per_unit'] ?? 0);

            if ($rawMaterialId <= 0) {
                throw new RuntimeException('Bahan baku pada resep tidak valid.');
            }

            if ($qtyPerUnit <= 0) {
                throw new RuntimeException('Jumlah per unit harus lebih dari nol.');
            }

            if (isset($seen[$rawMaterialId])) {
                throw new RuntimeException('Bahan baku yang sama tidak boleh muncul dua kali dalam satu resep.');
            }

            $seen[$rawMaterialId] = true;
        }

        return DB::transaction(function () use ($productId, $lines, $actor): Recipe {
            $previousVersion = (int) Recipe::query()
                ->where('product_id', $productId)
                ->max('version');

            $recipe = Recipe::query()->create([
                'product_id' => $productId,
                'version' => $previousVersion + 1,
                'is_active' => true,
                'effective_from' => now(),
                'created_by' => $actor->id,
            ]);

            foreach ($lines as $line) {
                RecipeLine::query()->create([
                    'recipe_id' => $recipe->id,
                    'raw_material_id' => (int) $line['raw_material_id'],
                    'qty_per_unit' => (int) $line['qty_per_unit'],
                ]);
            }

            return $recipe->load('recipeLines.rawMaterial');
        });
    }

    /**
     * Refreshes `computed_cost_minor` on every active recipe that uses any of the given raw
     * materials, from the latest known PURCHASE_IN unit cost per raw material at that kitchen.
     * Best-effort by design (see PurchaseOrderService::receive()): a recipe with no PURCHASE_IN
     * yet for one of its ingredients is left null rather than guessed at.
     *
     * @param  array<int,int>  $rawMaterialIds
     */
    public function refreshCostsForRawMaterials(array $rawMaterialIds, int $kitchenId): void
    {
        if ($rawMaterialIds === []) {
            return;
        }

        $recipeIds = RecipeLine::query()
            ->whereIn('raw_material_id', $rawMaterialIds)
            ->whereHas('recipe', fn ($q) => $q->where('is_active', true))
            ->pluck('recipe_id')
            ->unique();

        foreach ($recipeIds as $recipeId) {
            $recipe = Recipe::query()->with('recipeLines')->find($recipeId);

            if ($recipe) {
                $this->refreshCost($recipe, $kitchenId);
            }
        }
    }

    private function refreshCost(Recipe $recipe, int $kitchenId): void
    {
        $total = 0;

        foreach ($recipe->recipeLines as $line) {
            $unitCost = $this->latestUnitCostMinor((int) $line->raw_material_id, $kitchenId);

            if ($unitCost === null) {
                // At least one ingredient has no known purchase cost yet — don't guess at the
                // total, leave it null until every ingredient has a real cost behind it.
                $recipe->update(['computed_cost_minor' => null]);

                return;
            }

            $total += $line->qty_per_unit * $unitCost;
        }

        $recipe->update(['computed_cost_minor' => $total]);
    }

    /**
     * The most recent PURCHASE_IN's (cost_minor / qty_delta) for this raw material at this
     * kitchen's raw-material store — the "latest known unit cost" the brief calls for. Null when
     * nothing has ever been received.
     */
    private function latestUnitCostMinor(int $rawMaterialId, int $kitchenId): ?int
    {
        $row = StockLedger::query()
            ->where('location_type', StockLedgerService::RAW_MATERIAL_STORE)
            ->where('location_id', $kitchenId)
            ->where('raw_material_id', $rawMaterialId)
            ->where('movement_type', MovementType::PURCHASE_IN)
            ->whereNotNull('cost_minor')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (! $row || (int) $row->qty_delta <= 0) {
            return null;
        }

        return (int) round($row->cost_minor / $row->qty_delta);
    }
}
