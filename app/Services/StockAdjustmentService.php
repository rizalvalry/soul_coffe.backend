<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts one manual stock correction from "Stok Terpusat" — the panel's grid could previously only
 * be read, so a figure everyone could see was obviously wrong (a miscount, a cup broken and never
 * logged) had no honest way back into the ledger except an ad-hoc database edit.
 *
 * WHY A COMPENSATING ROW, NEVER AN OVERWRITE
 * --------------------------------------------
 * R6: `stock_ledger` is append-only and stock is `SUM(qty_delta)`, never a stored counter.
 * Changing "what the grid shows" can only ever mean posting a new row through
 * `StockLedgerService::post()` — the ledger's only writer — never touching an existing row or a
 * stock column anywhere. `adjust()` follows the same shape as `StockOpnameService::apply()`: lock
 * the live figure, compute the delta against it, post.
 *
 * HOW THIS DIFFERS FROM STOCK OPNAME
 * -------------------------------------
 * Stock Opname is a full physical count of a whole location, entered as a two-step DRAFT (walk
 * around, write down every product) then APPLY (post every line's correction at once). This
 * service is for the opposite situation: one cell on the grid is obviously wrong right now, and
 * posting the fix should take one submission, not a draft-then-apply detour through a separate
 * screen. There is no draft here — the correction is posted immediately, with the mandatory
 * reason recorded on the ledger row itself rather than on a `stock_opnames` header row.
 */
class StockAdjustmentService
{
    public function __construct(private readonly StockLedgerService $ledger) {}

    public function adjust(
        string $locationType,
        int $locationId,
        int $productId,
        int $countedQty,
        string $reason,
        User $actor,
    ): StockLedger {
        $this->assertMayOperate($actor);
        $this->assertLocation($locationType, $locationId);

        $reason = $this->assertReason($reason);
        $this->assertCountedQty($countedQty);

        $product = Product::query()->where('is_active', true)->find($productId);

        if (! $product) {
            throw new RuntimeException('Produk tidak dikenal atau sudah tidak aktif.');
        }

        $kitchenId = $this->kitchenIdFor($locationType, $locationId);

        return DB::transaction(function () use ($locationType, $locationId, $productId, $countedQty, $reason, $actor, $kitchenId): StockLedger {
            $current = $this->ledger->lockAndProject($locationType, $locationId, [$productId]);
            $systemQtyNow = $current[$productId] ?? 0;
            $delta = $countedQty - $systemQtyNow;

            if ($delta === 0) {
                throw new RuntimeException('Jumlah sebenarnya sudah sama dengan stok sistem, tidak ada yang diposting.');
            }

            return $this->ledger->post(
                locationType: $locationType,
                locationId: $locationId,
                productId: $productId,
                movementType: MovementType::ADJUSTMENT,
                qty: $delta,
                actorId: $actor->id,
                kitchenId: $kitchenId,
                refType: 'stock_adjustment',
                refId: null,
                note: $reason,
            );
        });
    }

    /**
     * The raw-material twin of `adjust()`, mirroring the twin methods StockLedgerService already
     * keeps for the two catalogues (`stockMap`/`rawMaterialStockMap`).
     *
     * The location is always a kitchen's raw-material store: ingredients live at a kitchen, never
     * on a cart. The caller passes the kitchen id and this resolves the store for it.
     */
    public function adjustRawMaterial(
        int $kitchenId,
        int $rawMaterialId,
        int $countedQty,
        string $reason,
        User $actor,
    ): StockLedger {
        $this->assertMayOperate($actor);
        $this->assertLocation(StockLedgerService::KITCHEN, $kitchenId);

        $reason = $this->assertReason($reason);
        $this->assertCountedQty($countedQty);

        $material = RawMaterial::query()->where('is_active', true)->find($rawMaterialId);

        if (! $material) {
            throw new RuntimeException('Bahan baku tidak dikenal atau sudah tidak aktif.');
        }

        return DB::transaction(function () use ($kitchenId, $rawMaterialId, $countedQty, $reason, $actor): StockLedger {
            $current = $this->ledger->lockAndProjectRawMaterials(
                StockLedgerService::RAW_MATERIAL_STORE,
                $kitchenId,
                [$rawMaterialId],
            );

            $delta = $countedQty - ($current[$rawMaterialId] ?? 0);

            if ($delta === 0) {
                throw new RuntimeException('Jumlah sebenarnya sudah sama dengan stok sistem, tidak ada yang diposting.');
            }

            return $this->ledger->post(
                locationType: StockLedgerService::RAW_MATERIAL_STORE,
                locationId: $kitchenId,
                productId: null,
                movementType: MovementType::ADJUSTMENT,
                qty: $delta,
                actorId: $actor->id,
                kitchenId: $kitchenId,
                refType: 'stock_adjustment',
                refId: null,
                rawMaterialId: $rawMaterialId,
                note: $reason,
            );
        });
    }

    private function assertReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Alasan penyesuaian wajib diisi.');
        }

        if (mb_strlen($reason) < 10) {
            throw new RuntimeException('Alasan penyesuaian wajib diisi (min. 10 karakter).');
        }

        return $reason;
    }

    private function assertCountedQty(int $countedQty): void
    {
        if ($countedQty < 0) {
            throw new RuntimeException('Jumlah sebenarnya tidak boleh negatif.');
        }
    }

    private function assertMayOperate(User $actor): void
    {
        if (! in_array($actor->role, [Role::ADMINISTRATOR, Role::FINANCE], true)) {
            throw new RuntimeException('Hanya Administrator atau Finance yang dapat melakukan penyesuaian stok.');
        }
    }

    private function assertLocation(string $locationType, int $locationId): void
    {
        $exists = match ($locationType) {
            StockLedgerService::KITCHEN => CentralKitchen::query()->whereKey($locationId)->exists(),
            StockLedgerService::CART => Cart::query()->whereKey($locationId)->exists(),
            default => throw new RuntimeException("Tipe lokasi stok tidak dikenal: {$locationType}"),
        };

        if (! $exists) {
            throw new RuntimeException('Lokasi tidak ditemukan.');
        }
    }

    private function kitchenIdFor(string $locationType, int $locationId): int
    {
        if ($locationType === StockLedgerService::KITCHEN) {
            return $locationId;
        }

        $kitchenId = Cart::query()->whereKey($locationId)->value('kitchen_id');

        if (! $kitchenId) {
            throw new RuntimeException('Gerobak ini belum terhubung ke dapur pusat.');
        }

        return (int) $kitchenId;
    }
}
