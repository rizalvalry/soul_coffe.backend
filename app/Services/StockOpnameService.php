<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Enums\StockOpnameStatus;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\StockOpname;
use App\Models\StockOpnameLine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stock Opname — the physical count reconciled against the ledger. See the migration for the
 * two-step DRAFT → APPLIED lifecycle and why the posted correction is computed fresh at apply
 * time rather than replayed from the draft.
 *
 * WHO MAY DO THIS
 * ---------------
 * Administrator or Finance, the same audience the money-reconciliation tools (Settlement, the
 * sale void) already answer to — a stock correction is exactly the kind of write that needs to be
 * traceable to a named person with a stated reason.
 */
class StockOpnameService
{
    public function __construct(private readonly StockLedgerService $ledger) {}

    /**
     * Everything an operator needs to fill in the count form for one location: every active
     * product, and what the ledger currently says each one holds.
     *
     * @return array<int, array{product_id: int, product_name: string, unit: string, system_qty: int}>
     */
    public function draftLines(string $locationType, int $locationId): array
    {
        $this->assertLocation($locationType, $locationId);

        $stock = $this->ledger->stockMap($locationType, $locationId);

        return Product::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'unit'])
            ->map(fn (Product $product): array => [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'unit' => $product->unit,
                'system_qty' => $stock[$product->id] ?? 0,
            ])
            ->all();
    }

    /**
     * Records a physical count as a DRAFT. Nothing is posted to the ledger here — see apply().
     *
     * @param  array<int, array{product_id: int, counted_qty: int}>  $counts
     */
    public function create(
        string $locationType,
        int $locationId,
        array $counts,
        string $reason,
        User $actor,
    ): StockOpname {
        $this->assertLocation($locationType, $locationId);
        $this->assertMayOperate($actor);

        $reason = trim($reason);

        if ($reason === '') {
            throw new RuntimeException('Alasan stock opname wajib diisi.');
        }

        $merged = [];

        foreach ($counts as $count) {
            $productId = (int) ($count['product_id'] ?? 0);
            $countedQty = $count['counted_qty'] ?? null;

            if ($productId <= 0 || $countedQty === null) {
                continue;
            }

            if ((int) $countedQty < 0) {
                throw new RuntimeException('Jumlah hasil hitung tidak boleh negatif.');
            }

            // Repeated rows for the same product would make "the count" ambiguous — which one is
            // real? The last one submitted wins, the same forgiving rule SaleService uses for a
            // repeated tile.
            $merged[$productId] = (int) $countedQty;
        }

        if ($merged === []) {
            throw new RuntimeException('Isi dulu hasil hitung untuk setidaknya satu produk.');
        }

        $stock = $this->ledger->stockMap($locationType, $locationId);
        $products = Product::query()->whereIn('id', array_keys($merged))->where('is_active', true)->get()->keyBy('id');

        return DB::transaction(function () use ($locationType, $locationId, $merged, $stock, $products, $reason, $actor): StockOpname {
            $opname = StockOpname::query()->create([
                'uuid' => (string) Str::uuid(),
                'location_type' => $locationType,
                'location_id' => $locationId,
                'counted_date' => Carbon::today()->toDateString(),
                // Server clock (R16), the same discipline every recorded event in this system
                // follows — a count's own time is never taken from the device.
                'counted_at' => now(),
                'status' => StockOpnameStatus::DRAFT,
                'reason' => $reason,
                'created_by' => $actor->id,
            ]);

            foreach ($merged as $productId => $countedQty) {
                if (! ($products[$productId] ?? null)) {
                    throw new RuntimeException('Produk tidak dikenal atau sudah tidak aktif.');
                }

                $systemQty = $stock[$productId] ?? 0;

                StockOpnameLine::query()->create([
                    'stock_opname_id' => $opname->id,
                    'product_id' => $productId,
                    'system_qty_at_count' => $systemQty,
                    'counted_qty' => $countedQty,
                    'variance_qty' => $countedQty - $systemQty,
                ]);
            }

            return $opname->load('lines');
        });
    }

    /**
     * Posts the correction. Every product's delta is computed against LIVE stock under a lock,
     * not the draft's `system_qty_at_count` — see the class docblock for why. A product whose
     * live stock already equals the counted quantity (nothing moved, or it happened to net out)
     * gets no ledger row at all: there is nothing to correct.
     */
    public function apply(StockOpname $opname, User $actor): StockOpname
    {
        $this->assertMayOperate($actor);

        return DB::transaction(function () use ($opname, $actor): StockOpname {
            $locked = StockOpname::query()->with('lines')->whereKey($opname->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isDraft()) {
                throw new RuntimeException('Stock opname ini sudah '.$locked->status->label().'.');
            }

            $productIds = $locked->lines->pluck('product_id')->all();
            $kitchenId = $this->kitchenIdFor($locked->location_type, $locked->location_id);

            $current = $this->ledger->lockAndProject($locked->location_type, $locked->location_id, $productIds);

            foreach ($locked->lines as $line) {
                $systemQtyNow = $current[$line->product_id] ?? 0;
                $delta = $line->counted_qty - $systemQtyNow;

                if ($delta !== 0) {
                    $this->ledger->post(
                        locationType: $locked->location_type,
                        locationId: $locked->location_id,
                        productId: $line->product_id,
                        movementType: MovementType::OPNAME_ADJUSTMENT,
                        qty: $delta,
                        actorId: $actor->id,
                        kitchenId: $kitchenId,
                        refType: 'stock_opname',
                        refId: $locked->id,
                    );
                }

                $line->update([
                    'system_qty_at_apply' => $systemQtyNow,
                    'applied_delta' => $delta,
                ]);
            }

            $locked->status = StockOpnameStatus::APPLIED;
            $locked->applied_by = $actor->id;
            $locked->applied_at = now();
            $locked->save();

            return $locked->fresh(['lines']);
        });
    }

    /**
     * Abandons a draft before it has touched the ledger. Refused once applied — an applied
     * correction is a fact about what was done, and cancelling it would be a second uncontrolled
     * write on top of the first.
     */
    public function cancel(StockOpname $opname, User $actor): StockOpname
    {
        $this->assertMayOperate($actor);

        return DB::transaction(function () use ($opname, $actor): StockOpname {
            $locked = StockOpname::query()->whereKey($opname->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isDraft()) {
                throw new RuntimeException('Hanya draf yang belum diterapkan yang bisa dibatalkan.');
            }

            $locked->status = StockOpnameStatus::CANCELLED;
            $locked->applied_by = $actor->id;
            $locked->applied_at = now();
            $locked->save();

            return $locked->fresh(['lines']);
        });
    }

    private function assertMayOperate(User $actor): void
    {
        if (! in_array($actor->role, [Role::ADMINISTRATOR, Role::FINANCE], true)) {
            throw new RuntimeException('Hanya Administrator atau Finance yang dapat melakukan stock opname.');
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
