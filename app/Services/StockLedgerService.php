<?php

namespace App\Services;

use App\Enums\KitchenRejectReason;
use App\Enums\MovementType;
use App\Enums\WasteReason;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\StockLedger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The only writer of stock_ledger.
 *
 * R6: the ledger is append-only and stock is a projection over SUM(qty_delta). Nothing else
 * inserts here, so the sign convention (IN positive, OUT negative) has exactly one enforcement
 * point instead of being re-derived — and possibly inverted — at every call site.
 */
class StockLedgerService
{
    public const KITCHEN = 'kitchen';

    public const CART = 'cart';

    // Phase 2: raw-material stock, same ledger, same numbering space as KITCHEN (location_id is
    // the kitchen_id it belongs to) — see the migration docblock for why this is not a parallel
    // table.
    public const RAW_MATERIAL_STORE = 'raw_material_store';

    /**
     * Movement types that remove stock. Their qty_delta is stored negative.
     */
    private const OUT_MOVEMENTS = [
        MovementType::ALLOCATION_OUT,
        MovementType::REFILL_OUT,
        MovementType::SALE_OUT,
        MovementType::WASTE_OUT,
        MovementType::RETURN_OUT,
        MovementType::RECIPE_CONSUME_OUT,
    ];

    /**
     * Post one movement. `$qty` is always given as a POSITIVE magnitude; the sign is applied
     * here from the movement type, so a caller cannot accidentally add stock with an OUT type.
     *
     * ADJUSTMENT is the one exception: it accepts a signed delta because a correction may go
     * either way, and forcing a direction would make some corrections unrepresentable.
     *
     * Exactly one of `$productId`/`$rawMaterialId` must be given — a row belongs to one catalogue
     * or the other, never both and never neither (mirrored by a DB check constraint where the
     * driver supports one; enforced here unconditionally so every driver is covered).
     * `$costMinor` is the total cost of this line when one is known (a purchase receipt), left
     * null everywhere else.
     */
    public function post(
        string $locationType,
        int $locationId,
        ?int $productId,
        MovementType $movementType,
        int $qty,
        int $actorId,
        int $kitchenId,
        ?string $refType = null,
        ?int $refId = null,
        ?int $rawMaterialId = null,
        ?int $costMinor = null,
        ?string $note = null,
        ?Carbon $occurredAt = null,
        bool $costPending = false,
        int $costPendingQty = 0,
        ?string $transferPairId = null,
    ): StockLedger {
        $this->assertLocationType($locationType);
        $this->assertExactlyOneCatalogue($productId, $rawMaterialId);

        if (in_array($movementType, [MovementType::ADJUSTMENT, MovementType::OPNAME_ADJUSTMENT], true)) {
            if ($qty === 0) {
                throw new RuntimeException('Adjustment tidak boleh nol.');
            }
            $delta = $qty;
        } else {
            if ($qty <= 0) {
                throw new RuntimeException('Jumlah pergerakan stok harus lebih dari nol.');
            }
            $delta = in_array($movementType, self::OUT_MOVEMENTS, true) ? -$qty : $qty;
        }

        return DB::transaction(function () use ($locationType, $locationId, $productId, $movementType, $delta, $costMinor,
            $refType, $refId, $note, $occurredAt, $actorId, $kitchenId, $rawMaterialId, $costPending, $costPendingQty, $transferPairId): StockLedger {
            if ($productId !== null) {
                // Both movement posting and revaluation take this product lock before any
                // valuation/accounting lock, without touching the Product or ledger row locks.
                $this->lockProductCostRows([$productId]);
            }
            $lock = ['location_type' => $locationType, 'location_id' => $locationId,
                'catalogue_type' => $productId === null ? 'raw' : 'product',
                'catalogue_id' => $productId ?? $rawMaterialId];
            DB::table('stock_valuation_locks')->insertOrIgnore($lock);
            $state = DB::table('stock_valuation_locks')->where($lock)->lockForUpdate()->first();
            $at = $occurredAt ?? now();
            if ($state->initialized) {
                $onHand = (int) $state->quantity;
                $value = (int) $state->value_minor;
            } else {
                [$onHand, $value] = $this->valuation($locationType, $locationId, $productId, $rawMaterialId);
            }
            $fallback = false;
            if ($costMinor === null && $movementType === MovementType::SALE_VOID_IN && $refId !== null) {
                $originalType = str_replace('_void', '', (string) $refType);
                $original = StockLedger::query()->where('location_type', $locationType)
                    ->where('location_id', $locationId)->where('product_id', $productId)
                    ->where('movement_type', MovementType::SALE_OUT)->where('ref_type', $originalType)
                    ->where('ref_id', $refId)->first();
                if ($original) {
                    $resolved = DB::table('stock_cost_resolutions')->where('stock_ledger_id', $original->id)
                        ->value('resolved_cost_minor');
                    $costMinor = (int) $original->cost_minor + (int) $resolved;
                    $costPending = $original->cost_pending && $resolved === null;
                    $costPendingQty = $costPending ? (int) $original->cost_pending_qty : 0;
                }
            }
            if ($costMinor === null) {
                $fallbackUnit = $this->fallbackUnitCost($productId, $rawMaterialId, $kitchenId, $at);
                if ($onHand > 0 && $value > 0) {
                    $covered = min(abs($delta), $onHand);
                    $costMinor = $covered === $onHand ? $value
                        : intdiv($value * $covered + intdiv($onHand, 2), $onHand);
                    $costMinor += (abs($delta) - $covered) * $fallbackUnit;
                    if ($productId !== null && $fallbackUnit === 0 && abs($delta) > $covered) {
                        $costPending = true;
                        $costPendingQty = abs($delta) - $covered;
                    }
                } else {
                    $costMinor = abs($delta) * $fallbackUnit;
                    $fallback = true;
                    $costPending = $productId !== null && $fallbackUnit === 0;
                    $costPendingQty = $costPending ? abs($delta) : 0;
                }
            }
            $movement = StockLedger::create([
                'location_type' => $locationType,
                'location_id' => $locationId,
                'product_id' => $productId,
                'raw_material_id' => $rawMaterialId,
                'movement_type' => $movementType,
                'qty_delta' => $delta,
                'cost_minor' => $costMinor,
                'cost_fallback' => $fallback,
                'cost_pending' => $costPending,
                'cost_pending_qty' => $costPendingQty,
                'ref_type' => $refType,
                'ref_id' => $refId,
                'transfer_pair_id' => $transferPairId,
                'note' => $note,
                // A late Finance recognition belongs to the original operating day for settlement.
                // The decision table retains the actual posting time and decision maker.
                'created_at' => $at,
                'actor_id' => $actorId,
                'kitchen_id' => $kitchenId,
            ]);
            if ($movementType === MovementType::WASTE_OUT) {
                $reason = match ($refType) {
                    'rider_closing', 'cart_closeout_reject', 'settlement_reject', 'delivery_incident' => WasteReason::DAMAGED,
                    'refill_expired' => WasteReason::EXPIRED,
                    'promotional_use' => WasteReason::PROMOTION,
                    'kitchen_reject' => WasteReason::fromKitchenReject(
                        KitchenRejectReason::tryFrom((string) DB::table('kitchen_rejects')
                            ->where('id', $refId)->value('reason_code')) ?? KitchenRejectReason::OTHER),
                    default => WasteReason::DISCARDED,
                };
                $movement->wasteClassification()->create(['reason_code' => $reason]);
            }
            DB::table('stock_valuation_locks')->where('id', $state->id)->update([
                'quantity' => $onHand + $delta,
                'value_minor' => $value + ($delta < 0 ? -$costMinor : $costMinor),
                'initialized' => true,
            ]);
            app(InventoryJournalService::class)->postMovement($movement);
            if ($onHand + $delta < 0) {
                // Keep the physical event even when earlier stock was not recorded. Finance
                // can investigate the audit row; the daily reconciliation reports I-3.
                AuditLog::recordAction($movement->actor, 'stock_ledger.negative_balance', $movement,
                    ['balance_qty' => $onHand], [
                        'balance_qty' => $onHand + $delta,
                        'location_type' => $locationType,
                        'location_id' => $locationId,
                        'product_id' => $productId,
                        'raw_material_id' => $rawMaterialId,
                        'movement_type' => $movementType->value,
                        'qty_delta' => $delta,
                        'ref_type' => $refType,
                        'ref_id' => $refId,
                    ]);
            }

            return $movement;
        });
    }

    /** Take cost mutexes in product order before journal-period or valuation locks. */
    public function lockProductCostRows(array $productIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        sort($ids);
        foreach ($ids as $id) {
            DB::table('stock_cost_revaluation_locks')->insertOrIgnore(['product_id' => $id]);
            DB::table('stock_cost_revaluation_locks')->where('product_id', $id)->lockForUpdate()->first();
        }
    }

    /**
     * Move stock between two locations as one atomic pair.
     *
     * Both rows land or neither does. A half-written transfer would make stock appear to vanish,
     * and because the ledger is append-only there would be no way to "fix" it except with a
     * compensating entry nobody knows to write.
     */
    public function transfer(
        string $fromType,
        int $fromId,
        string $toType,
        int $toId,
        int $productId,
        MovementType $outType,
        MovementType $inType,
        int $qty,
        int $actorId,
        int $kitchenId,
        ?string $refType = null,
        ?int $refId = null,
        ?string $note = null,
    ): void {
        // Both legs represent one event, including a transfer posted across midnight.
        $occurredAt = now();
        $transferPairId = (string) Str::uuid();
        DB::transaction(function () use (
            $fromType, $fromId, $toType, $toId, $productId,
            $outType, $inType, $qty, $actorId, $kitchenId, $refType, $refId, $note, $occurredAt, $transferPairId
        ): void {
            $out = $this->post($fromType, $fromId, $productId, $outType, $qty, $actorId, $kitchenId, $refType, $refId,
                note: $note, occurredAt: $occurredAt, transferPairId: $transferPairId);
            $this->post($toType, $toId, $productId, $inType, $qty, $actorId, $kitchenId,
                $refType, $refId, costMinor: $out->cost_minor, costPending: $out->cost_pending,
                costPendingQty: $out->cost_pending_qty, note: $note, occurredAt: $occurredAt,
                transferPairId: $transferPairId);
        });
    }

    /** @return array{int,int,int} Quantity, carrying value in rupiah, fallback row count. */
    public function valuation(string $locationType, int $locationId, ?int $productId,
        ?int $rawMaterialId = null, ?Carbon $at = null): array
    {
        $query = StockLedger::query()->where('stock_ledger.location_type', $locationType)
            ->where('stock_ledger.location_id', $locationId)
            ->where('stock_ledger.product_id', $productId)
            ->where('stock_ledger.raw_material_id', $rawMaterialId)
            ->leftJoin('stock_cost_resolutions as resolution', 'resolution.stock_ledger_id', '=', 'stock_ledger.id');
        if ($at) {
            $query->where('stock_ledger.created_at', '<=', $at);
        }
        $rows = $query->orderBy('stock_ledger.id')->get(['stock_ledger.qty_delta', 'stock_ledger.cost_minor',
            'stock_ledger.cost_fallback', 'stock_ledger.created_at', 'stock_ledger.kitchen_id',
            'resolution.resolved_cost_minor', 'resolution.resolved_at']);
        $qty = 0;
        $value = 0;
        $fallbackRows = 0;
        foreach ($rows as $row) {
            $delta = (int) $row->qty_delta;
            $cost = $row->cost_minor;
            if ($cost === null) {
                $cost = abs($delta) * $this->fallbackUnitCost($productId, $rawMaterialId,
                    (int) $row->kitchen_id, $row->created_at);
                $fallbackRows++;
            } elseif ($row->cost_fallback) {
                $fallbackRows++;
            }
            if ($row->resolved_at !== null && (! $at || Carbon::parse($row->resolved_at)->lte($at))) {
                $cost += (int) $row->resolved_cost_minor;
            }
            $qty += $delta;
            $value += $delta < 0 ? -(int) $cost : (int) $cost;
        }

        return [$qty, $value, $fallbackRows];
    }

    private function fallbackUnitCost(?int $productId, ?int $rawMaterialId, int $kitchenId, Carbon $at): int
    {
        if ($productId !== null) {
            return (int) (Product::query()->find($productId)?->priceVersionAt($at)?->cost_price_minor ?? 0);
        }
        $receipt = StockLedger::query()->where('location_type', self::RAW_MATERIAL_STORE)
            ->where('location_id', $kitchenId)->where('raw_material_id', $rawMaterialId)
            ->where('movement_type', MovementType::PURCHASE_IN)->where('created_at', '<=', $at)
            ->whereNotNull('cost_minor')->orderByDesc('created_at')->orderByDesc('id')->first();

        return $receipt && $receipt->qty_delta > 0 ? intdiv((int) $receipt->cost_minor, (int) $receipt->qty_delta) : 0;
    }

    public function stockFor(string $locationType, int $locationId, int $productId): int
    {
        $this->assertLocationType($locationType);

        return StockLedger::projectedStock($locationType, $locationId, $productId);
    }

    /** FIFO allocation of kitchen removals gives each production a real remaining batch. */
    public function remainingProductionBatch(int $kitchenId, int $productId, int $productionId): int
    {
        $lots = [];
        $inTransit = [];
        $sold = [];
        $rows = StockLedger::query()->where('kitchen_id', $kitchenId)->where('product_id', $productId)
            ->orderBy('id')->get(['location_type', 'location_id', 'qty_delta', 'movement_type', 'ref_type', 'ref_id']);
        foreach ($rows as $row) {
            $delta = (int) $row->qty_delta;
            $location = $row->location_type.':'.$row->location_id;
            $key = $row->ref_type.':'.$row->ref_id;
            if ($delta > 0) {
                if (in_array($row->movement_type, [MovementType::ALLOCATION_IN, MovementType::REFILL_IN,
                    MovementType::RETURN_IN, MovementType::SALE_VOID_IN], true)) {
                    $source = $row->movement_type === MovementType::SALE_VOID_IN ? $sold : $inTransit;
                    $sourceKey = $row->movement_type === MovementType::SALE_VOID_IN
                        ? str_replace('_void', '', (string) $row->ref_type).':'.$row->ref_id : $key;
                    $remaining = $delta;
                    foreach ($source[$sourceKey] ?? [] as $lot) {
                        $taken = min($remaining, $lot['qty']);
                        $lots[$location][] = ['production_id' => $lot['production_id'], 'qty' => $taken];
                        $remaining -= $taken;
                        if ($remaining === 0) {
                            break;
                        }
                    }
                    if ($remaining > 0) {
                        $lots[$location][] = ['production_id' => null, 'qty' => $remaining];
                    }
                } else {
                    $lots[$location][] = ['production_id' => $row->movement_type === MovementType::PRODUCTION_IN
                        && $row->ref_type === 'production' ? (int) $row->ref_id : null, 'qty' => $delta];
                }

                continue;
            }
            $needed = -$delta;
            $lots[$location] ??= [];
            if ($row->ref_type === 'production_void') {
                foreach ($lots[$location] as &$lot) {
                    if ($lot['production_id'] !== (int) $row->ref_id) {
                        continue;
                    }
                    $taken = min($needed, $lot['qty']);
                    $lot['qty'] -= $taken;
                    $needed -= $taken;
                    if ($needed === 0) {
                        break;
                    }
                }
                unset($lot);

                continue;
            }
            $takenLots = [];
            foreach ($lots[$location] as &$lot) {
                $taken = min($needed, $lot['qty']);
                if ($taken > 0) {
                    $takenLots[] = ['production_id' => $lot['production_id'], 'qty' => $taken];
                }
                $lot['qty'] -= $taken;
                $needed -= $taken;
                if ($needed === 0) {
                    break;
                }
            }
            unset($lot);
            if (in_array($row->movement_type, [MovementType::ALLOCATION_OUT, MovementType::REFILL_OUT,
                MovementType::RETURN_OUT], true)) {
                $inTransit[$key] = $takenLots;
            } elseif ($row->movement_type === MovementType::SALE_OUT) {
                $sold[$key] = $takenLots;
            }
        }

        return array_sum(array_map(fn (array $lot): int => $lot['production_id'] === $productionId ? $lot['qty'] : 0,
            $lots[self::KITCHEN.':'.$kitchenId] ?? []));
    }

    /**
     * Projected stock for every product at one location.
     *
     * @return array<int,int> product_id => qty
     */
    public function stockMap(string $locationType, int $locationId): array
    {
        $this->assertLocationType($locationType);

        return StockLedger::query()
            ->where('location_type', $locationType)
            ->where('location_id', $locationId)
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(qty_delta) AS qty')
            ->pluck('qty', 'product_id')
            ->map(fn ($qty): int => (int) $qty)
            ->all();
    }

    /**
     * Row-lock the ledger rows for the given products at a location, in ascending product order.
     *
     * The ordering is what prevents deadlock: two concurrent allocations touching an overlapping
     * product set would otherwise be able to grab the same rows in opposite orders and wedge.
     * Call this inside a transaction before checking sufficiency.
     *
     * @param  array<int,int>  $productIds
     * @return array<int,int> product_id => locked qty
     */
    public function lockAndProject(string $locationType, int $locationId, array $productIds): array
    {
        $this->assertLocationType($locationType);

        $ids = array_values(array_unique(array_map('intval', $productIds)));
        sort($ids);

        if ($ids === []) {
            return [];
        }

        // Stock writers take the product cost mutex before ledger rows; use the same order
        // for sufficiency checks so production and transfers cannot wait on each other.
        $this->lockProductCostRows($ids);
        StockLedger::query()
            ->where('location_type', $locationType)
            ->where('location_id', $locationId)
            ->whereIn('product_id', $ids)
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get(['id']);

        $projected = $this->stockMap($locationType, $locationId);

        return array_combine($ids, array_map(fn (int $id): int => $projected[$id] ?? 0, $ids));
    }

    public function rawMaterialStockFor(string $locationType, int $locationId, int $rawMaterialId): int
    {
        $this->assertLocationType($locationType);

        return (int) StockLedger::query()
            ->where('location_type', $locationType)
            ->where('location_id', $locationId)
            ->where('raw_material_id', $rawMaterialId)
            ->sum('qty_delta');
    }

    /**
     * Projected stock for every raw material at one location — the raw-material twin of
     * stockMap().
     *
     * @return array<int,int> raw_material_id => qty
     */
    public function rawMaterialStockMap(string $locationType, int $locationId): array
    {
        $this->assertLocationType($locationType);

        return StockLedger::query()
            ->where('location_type', $locationType)
            ->where('location_id', $locationId)
            ->whereNotNull('raw_material_id')
            ->groupBy('raw_material_id')
            ->selectRaw('raw_material_id, SUM(qty_delta) AS qty')
            ->pluck('qty', 'raw_material_id')
            ->map(fn ($qty): int => (int) $qty)
            ->all();
    }

    /** @return array{tracked:bool,quantity:int,value_minor:int} Current raw-material valuation under its writer mutex. */
    public function lockRawMaterialState(int $kitchenId, int $rawMaterialId): array
    {
        $lock = ['location_type' => self::RAW_MATERIAL_STORE, 'location_id' => $kitchenId,
            'catalogue_type' => 'raw', 'catalogue_id' => $rawMaterialId];
        DB::table('stock_valuation_locks')->insertOrIgnore($lock);
        $state = DB::table('stock_valuation_locks')->where($lock)->lockForUpdate()->first();
        if ($state->initialized) {
            return ['tracked' => true, 'quantity' => (int) $state->quantity,
                'value_minor' => (int) $state->value_minor];
        }
        [$quantity, $value] = $this->valuation(self::RAW_MATERIAL_STORE, $kitchenId, null, $rawMaterialId);
        $tracked = StockLedger::query()->where('location_type', self::RAW_MATERIAL_STORE)
            ->where('location_id', $kitchenId)->where('raw_material_id', $rawMaterialId)->exists();

        return ['tracked' => $tracked, 'quantity' => $quantity, 'value_minor' => $value];
    }

    /** @return array<int,int> raw_material_id => locked qty */
    public function lockAndProjectRawMaterials(string $locationType, int $locationId, array $rawMaterialIds): array
    {
        $this->assertLocationType($locationType);

        $ids = array_values(array_unique(array_map('intval', $rawMaterialIds)));
        sort($ids);

        if ($ids === []) {
            return [];
        }

        if ($locationType !== self::RAW_MATERIAL_STORE) {
            throw new RuntimeException('Bahan baku hanya tersedia di gudang dapur.');
        }
        $projected = [];
        foreach ($ids as $id) {
            $projected[$id] = $this->lockRawMaterialState($locationId, $id)['quantity'];
        }

        return $projected;
    }

    private function assertLocationType(string $locationType): void
    {
        if (! in_array($locationType, [self::KITCHEN, self::CART, self::RAW_MATERIAL_STORE], true)) {
            throw new RuntimeException("Tipe lokasi stok tidak dikenal: {$locationType}");
        }
    }

    /**
     * A ledger row belongs to exactly one catalogue — a finished product or a raw material —
     * never both and never neither. This is the application-level twin of the DB check
     * constraint added alongside `raw_material_id`, so every driver is covered even one that
     * cannot enforce the constraint itself.
     */
    private function assertExactlyOneCatalogue(?int $productId, ?int $rawMaterialId): void
    {
        if (($productId === null) === ($rawMaterialId === null)) {
            throw new RuntimeException(
                'Setiap baris buku besar stok harus mengacu ke tepat satu: produk atau bahan baku.'
            );
        }
    }
}
