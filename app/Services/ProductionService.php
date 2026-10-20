<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CentralKitchen;
use App\Models\JournalEntry;
use App\Models\Production;
use App\Models\ProductionLine;
use App\Models\RawMaterial;
use App\Models\Recipe;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        private readonly JournalService $journals,
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
            $this->ledger->lockProductCostRows(array_keys($rows));
            if ($demand !== []) {
                $locked = $this->ledger->lockAndProjectRawMaterials(
                    StockLedgerService::RAW_MATERIAL_STORE,
                    $kitchen->id,
                    array_keys($demand),
                );

                $this->assertSufficient($demand, $locked);
            }
            if ($this->journals->isAccountingDate(today()->toDateString())) {
                $this->journals->assertOpenDate(today()->toDateString());
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
            $costByProduct = [];
            foreach ($rows as $productId => $qty) {
                if ($recipeByProduct[$productId]?->computed_cost_minor !== null) {
                    $costByProduct[$productId] = (int) $recipeByProduct[$productId]->computed_cost_minor * $qty;
                }
            }
            $this->centralStock->brewIntoShowcase($actor, $kitchen, $rows, $production->id, $costByProduct);

            $consumedCost = 0;
            foreach ($demand as $rawMaterialId => $qty) {
                $movement = $this->ledger->post(
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
                $consumedCost += (int) $movement->cost_minor;
            }

            $cost = (int) StockLedger::query()->where('ref_type', 'production')->where('ref_id', $production->id)
                ->where('movement_type', MovementType::PRODUCTION_IN)->sum('cost_minor');
            if ($cost > 0 && $this->journals->isAccountingDate($production->produced_at->toDateString())) {
                $finished = Account::query()->where('code', '1-1600')->firstOrFail();
                $raw = Account::query()->where('code', '1-1700')->firstOrFail();
                $this->journals->post([
                    'entry_date' => $production->produced_at->toDateString(),
                    'description' => 'Produksi #'.$production->id,
                    'source_type' => 'production', 'source_id' => $production->id,
                    'purpose' => 'TRANSFER', 'posted_by' => $actor->id,
                ], [
                    ['account_id' => $finished->id, 'debit_minor' => $cost, 'credit_minor' => 0],
                    ['account_id' => $raw->id, 'debit_minor' => 0, 'credit_minor' => $cost],
                ]);
            }
            if ($consumedCost !== $cost && $this->journals->isAccountingDate($production->produced_at->toDateString())) {
                $difference = $consumedCost - $cost;
                $raw = Account::query()->where('code', '1-1700')->firstOrFail();
                $variance = Account::query()->where('code', '6-1910')->firstOrFail();
                $this->journals->post([
                    'entry_date' => $production->produced_at->toDateString(),
                    'description' => 'Selisih biaya bahan produksi #'.$production->id,
                    'source_type' => 'production', 'source_id' => $production->id,
                    'purpose' => 'COST_VARIANCE', 'posted_by' => $actor->id,
                ], [
                    ['account_id' => $variance->id, 'debit_minor' => max(0, $difference), 'credit_minor' => max(0, -$difference)],
                    ['account_id' => $raw->id, 'debit_minor' => max(0, -$difference), 'credit_minor' => max(0, $difference)],
                ]);
            }

            return $production->load('lines.product', 'lines.recipe');
        });
    }

    public function void(Production $production, string $reason, User $actor): Production
    {
        Gate::forUser($actor)->authorize('void-production');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => 'Alasan batal produksi wajib diisi, maksimal 255 karakter ya.']);
        }

        return DB::transaction(function () use ($production, $reason, $actor): Production {
            $locked = Production::query()->with('lines')->whereKey($production->id)->lockForUpdate()->firstOrFail();
            if ($locked->voided_at !== null) {
                return $locked;
            }
            $ids = $locked->lines->pluck('product_id')->all();
            $this->ledger->lockAndProject(StockLedgerService::KITCHEN, $locked->kitchen_id, $ids);
            foreach ($locked->lines as $line) {
                if ($this->ledger->remainingProductionBatch($locked->kitchen_id, $line->product_id, $locked->id) < $line->qty_brewed) {
                    throw ValidationException::withMessages(['production' => 'Cups produksi ini sudah keluar dari dapur. Catat koreksi stok terpisah ya.']);
                }
            }
            $voidCost = 0;
            foreach ($locked->lines as $line) {
                $movement = $this->ledger->post(StockLedgerService::KITCHEN, $locked->kitchen_id,
                    $line->product_id, MovementType::ADJUSTMENT, -$line->qty_brewed,
                    $actor->id, $locked->kitchen_id, 'production_void', $locked->id,
                    note: $reason);
                $voidCost += (int) $movement->cost_minor;
            }
            $consumed = StockLedger::query()->where('ref_type', 'production')->where('ref_id', $locked->id)
                ->where('movement_type', MovementType::RECIPE_CONSUME_OUT)->get();
            foreach ($consumed as $row) {
                $this->ledger->post(StockLedgerService::RAW_MATERIAL_STORE, $locked->kitchen_id,
                    null, MovementType::ADJUSTMENT, -(int) $row->qty_delta, $actor->id,
                    $locked->kitchen_id, 'production_void', $locked->id,
                    rawMaterialId: $row->raw_material_id, costMinor: (int) $row->cost_minor, note: $reason);
            }
            $journal = JournalEntry::query()->where('source_type', 'production')
                ->where('source_id', $locked->id)->where('purpose', 'TRANSFER')->first();
            if ($journal) {
                $this->journals->reverse($journal, $reason);
            }
            $variance = JournalEntry::query()->where('source_type', 'production')
                ->where('source_id', $locked->id)->where('purpose', 'COST_VARIANCE')->first();
            if ($variance) {
                $this->journals->reverse($variance, $reason);
            }
            $originalCost = (int) StockLedger::query()->where('ref_type', 'production')->where('ref_id', $locked->id)
                ->where('movement_type', MovementType::PRODUCTION_IN)->sum('cost_minor');
            if ($voidCost !== $originalCost) {
                $difference = $originalCost - $voidCost;
                $finished = Account::query()->where('code', '1-1600')->firstOrFail();
                $expense = Account::query()->where('code', '6-1910')->firstOrFail();
                $this->journals->post([
                    'entry_date' => today()->toDateString(), 'description' => 'Selisih batal produksi #'.$locked->id,
                    'source_type' => 'production_void', 'source_id' => $locked->id,
                    'purpose' => 'COST_VARIANCE', 'posted_by' => $actor->id,
                ], [
                    ['account_id' => $finished->id, 'debit_minor' => max(0, $difference), 'credit_minor' => max(0, -$difference)],
                    ['account_id' => $expense->id, 'debit_minor' => max(0, -$difference), 'credit_minor' => max(0, $difference)],
                ]);
            }
            $locked->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]);
            AuditLog::query()->create(['actor_id' => $actor->id, 'actor_role' => $actor->role->value,
                'action' => 'production.voided', 'subject_type' => Production::class, 'subject_id' => $locked->id,
                'after_json' => ['reason' => $reason], 'ip' => request()->ip()]);

            return $locked;
        }, 3);
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
