<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Purchase orders — how raw material stock enters the system traceably, with a real cost.
 * Mirrors StockOpnameService's shape: create a record first, decide on it later, lock the row
 * before any write that touches the ledger.
 *
 * WHO MAY DO THIS
 * ---------------
 * Administrator or Finance only, the same audience every other money-adjacent write in this
 * system already answers to (StockOpnameService, SaleService::assertMayVoid).
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly StockLedgerService $ledger,
        private readonly RecipeService $recipes,
    ) {}

    /**
     * @param  array<int, array{raw_material_id: int, qty_ordered: int, unit_cost_minor: int}>  $lines
     */
    public function create(int $supplierId, int $kitchenId, array $lines, User $actor): PurchaseOrder
    {
        $this->assertMayOperate($actor);

        if ($lines === []) {
            throw new RuntimeException('Purchase order harus punya minimal satu baris bahan baku.');
        }

        $seen = [];
        foreach ($lines as $line) {
            $rawMaterialId = (int) ($line['raw_material_id'] ?? 0);
            $qtyOrdered = (int) ($line['qty_ordered'] ?? 0);
            $unitCostMinor = (int) ($line['unit_cost_minor'] ?? 0);

            if ($rawMaterialId <= 0) {
                throw new RuntimeException('Bahan baku pada purchase order tidak valid.');
            }

            if ($qtyOrdered <= 0) {
                throw new RuntimeException('Jumlah pesan harus lebih dari nol.');
            }

            if ($unitCostMinor <= 0) {
                throw new RuntimeException('Harga satuan harus lebih dari nol.');
            }

            if (isset($seen[$rawMaterialId])) {
                throw new RuntimeException('Bahan baku yang sama tidak boleh muncul dua kali dalam satu purchase order.');
            }

            $seen[$rawMaterialId] = true;
        }

        return DB::transaction(function () use ($supplierId, $kitchenId, $lines, $actor): PurchaseOrder {
            $po = PurchaseOrder::query()->create([
                'uuid' => (string) Str::uuid(),
                'supplier_id' => $supplierId,
                'kitchen_id' => $kitchenId,
                'status' => PurchaseOrderStatus::DRAFT,
                'created_by' => $actor->id,
            ]);

            foreach ($lines as $line) {
                PurchaseOrderLine::query()->create([
                    'purchase_order_id' => $po->id,
                    'raw_material_id' => (int) $line['raw_material_id'],
                    'qty_ordered' => (int) $line['qty_ordered'],
                    'unit_cost_minor' => (int) $line['unit_cost_minor'],
                ]);
            }

            return $po->load('lines');
        });
    }

    public function markOrdered(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        $this->assertMayOperate($actor);

        return DB::transaction(function () use ($po, $actor): PurchaseOrder {
            $locked = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isDraft()) {
                throw new RuntimeException('Hanya draf yang bisa ditandai dipesan.');
            }

            $locked->status = PurchaseOrderStatus::ORDERED;
            $locked->ordered_at = now();
            $locked->save();

            return $locked->fresh(['lines']);
        });
    }

    /**
     * Posts the actual receipt to the stock ledger. Supports receiving less than what was
     * ordered per line; the PO as a whole still becomes RECEIVED exactly once — there is no
     * partial/second receive. A line with no explicit `$receivedQuantities` entry defaults to its
     * full `qty_ordered`.
     *
     * @param  array<int,int>  $receivedQuantities  raw_material_id => qty actually delivered
     */
    public function receive(PurchaseOrder $po, array $receivedQuantities, User $actor): PurchaseOrder
    {
        $this->assertMayOperate($actor);

        $received = DB::transaction(function () use ($po, $receivedQuantities, $actor): PurchaseOrder {
            $locked = PurchaseOrder::query()->with('lines')->whereKey($po->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isReceived()) {
                throw new RuntimeException('Purchase order ini sudah diterima.');
            }

            if (! $locked->status->isOrdered()) {
                throw new RuntimeException('Tandai purchase order ini sebagai dipesan terlebih dahulu.');
            }

            foreach ($locked->lines as $line) {
                $qtyReceived = array_key_exists($line->raw_material_id, $receivedQuantities)
                    ? (int) $receivedQuantities[$line->raw_material_id]
                    : (int) $line->qty_ordered;

                if ($qtyReceived < 0) {
                    throw new RuntimeException('Jumlah diterima tidak boleh negatif.');
                }

                $line->update(['qty_received' => $qtyReceived]);

                if ($qtyReceived > 0) {
                    $this->ledger->post(
                        locationType: StockLedgerService::RAW_MATERIAL_STORE,
                        locationId: $locked->kitchen_id,
                        productId: null,
                        movementType: MovementType::PURCHASE_IN,
                        qty: $qtyReceived,
                        actorId: $actor->id,
                        kitchenId: $locked->kitchen_id,
                        refType: 'purchase_order',
                        refId: $locked->id,
                        rawMaterialId: $line->raw_material_id,
                        costMinor: $qtyReceived * $line->unit_cost_minor,
                    );
                }
            }

            $locked->status = PurchaseOrderStatus::RECEIVED;
            $locked->received_by = $actor->id;
            $locked->received_at = now();
            $locked->save();

            return $locked->fresh(['lines']);
        });

        // Best-effort: the stock movement just committed is the source of truth. A failure here
        // must not roll back a receipt that has already happened in the real kitchen — it would
        // only leave `computed_cost_minor` stale, which the next receive of this raw material
        // corrects anyway.
        try {
            $rawMaterialIds = $received->lines
                ->filter(fn (PurchaseOrderLine $line): bool => (int) $line->qty_received > 0)
                ->pluck('raw_material_id')
                ->all();

            $this->recipes->refreshCostsForRawMaterials($rawMaterialIds, $received->kitchen_id);
        } catch (\Throwable $e) {
            Log::warning('Recipe cost refresh failed after purchase order receipt.', [
                'purchase_order_id' => $received->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return $received;
    }

    /**
     * Refused once RECEIVED — a received PO has already posted to the ledger, and correcting a
     * posted fact is a Stock Opname's job, not a cancel's (the same sale-void-vs-settlement
     * boundary SaleService/StockOpnameService already draw).
     */
    public function cancel(PurchaseOrder $po, User $actor): PurchaseOrder
    {
        $this->assertMayOperate($actor);

        return DB::transaction(function () use ($po, $actor): PurchaseOrder {
            $locked = PurchaseOrder::query()->whereKey($po->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isReceived()) {
                throw new RuntimeException('Purchase order yang sudah diterima tidak bisa dibatalkan — gunakan Stock Opname untuk koreksi.');
            }

            $locked->status = PurchaseOrderStatus::CANCELLED;
            $locked->save();

            return $locked->fresh(['lines']);
        });
    }

    private function assertMayOperate(User $actor): void
    {
        if (! in_array($actor->role, [Role::ADMINISTRATOR, Role::FINANCE], true)) {
            throw new RuntimeException('Hanya Administrator atau Finance yang dapat mengelola purchase order.');
        }
    }
}
