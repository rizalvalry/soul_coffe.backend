<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\StockLedger;
use Illuminate\Support\Facades\DB;
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

    /**
     * Movement types that remove stock. Their qty_delta is stored negative.
     */
    private const OUT_MOVEMENTS = [
        MovementType::ALLOCATION_OUT,
        MovementType::REFILL_OUT,
        MovementType::SALE_OUT,
        MovementType::WASTE_OUT,
        MovementType::RETURN_OUT,
    ];

    /**
     * Post one movement. `$qty` is always given as a POSITIVE magnitude; the sign is applied
     * here from the movement type, so a caller cannot accidentally add stock with an OUT type.
     *
     * ADJUSTMENT is the one exception: it accepts a signed delta because a correction may go
     * either way, and forcing a direction would make some corrections unrepresentable.
     */
    public function post(
        string $locationType,
        int $locationId,
        int $productId,
        MovementType $movementType,
        int $qty,
        int $actorId,
        int $kitchenId,
        ?string $refType = null,
        ?int $refId = null,
    ): StockLedger {
        $this->assertLocationType($locationType);

        if ($movementType === MovementType::ADJUSTMENT) {
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

        return StockLedger::create([
            'location_type' => $locationType,
            'location_id' => $locationId,
            'product_id' => $productId,
            'movement_type' => $movementType,
            'qty_delta' => $delta,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'actor_id' => $actorId,
            'kitchen_id' => $kitchenId,
        ]);
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
    ): void {
        DB::transaction(function () use (
            $fromType, $fromId, $toType, $toId, $productId,
            $outType, $inType, $qty, $actorId, $kitchenId, $refType, $refId
        ): void {
            $this->post($fromType, $fromId, $productId, $outType, $qty, $actorId, $kitchenId, $refType, $refId);
            $this->post($toType, $toId, $productId, $inType, $qty, $actorId, $kitchenId, $refType, $refId);
        });
    }

    public function stockFor(string $locationType, int $locationId, int $productId): int
    {
        $this->assertLocationType($locationType);

        return StockLedger::projectedStock($locationType, $locationId, $productId);
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

    private function assertLocationType(string $locationType): void
    {
        if (! in_array($locationType, [self::KITCHEN, self::CART], true)) {
            throw new RuntimeException("Tipe lokasi stok tidak dikenal: {$locationType}");
        }
    }
}
