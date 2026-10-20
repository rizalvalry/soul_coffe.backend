<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Account;
use App\Models\Product;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Accounting side of physical stock movements; the stock ledger remains append-only. */
class InventoryJournalService
{
    public function __construct(private readonly JournalService $journals) {}

    public function postMovement(StockLedger $movement): void
    {
        $isWaste = $movement->movement_type === MovementType::WASTE_OUT && $movement->ref_type !== 'rider_closing';
        $isAdjustment = $movement->movement_type === MovementType::OPNAME_ADJUSTMENT
            || ($movement->movement_type === MovementType::ADJUSTMENT && $movement->ref_type === 'stock_adjustment');
        if (! $isWaste && ! $isAdjustment) {
            return;
        }
        $this->journals->assertOpenDate($movement->created_at->toDateString());
        $unit = $this->unitCost($movement->product_id, $movement->raw_material_id, $movement->kitchen_id, $movement->created_at);
        if ($unit === null || $unit === 0) {
            return;
        }
        $delta = (int) $movement->qty_delta * $unit;
        $this->postAmount('stock_ledger', $movement->id, 'STOCK_LOSS',
            $movement->created_at->toDateString(), -$delta, $movement->raw_material_id !== null,
            $movement->actor_id, 'Rusak / selisih stok #'.$movement->id);
    }

    public function postClosingRevision(int $revisionId, string $date, int $oldTotal, array $current, int $actorId): void
    {
        $newTotal = 0;
        foreach ($current as $line) {
            $newTotal += (int) $line->qty_wasted * (int) $line->unit_cost_minor;
        }
        $this->postAmount('rider_closing_revision', $revisionId, 'WASTE', $date,
            $newTotal - $oldTotal, false, $actorId, 'Rusak Closing revisi #'.$revisionId);
    }

    public function postAmount(string $sourceType, int $sourceId, string $purpose, string $date,
        int $lossMinor, bool $rawMaterial, ?int $actorId, string $description): void
    {
        if ($lossMinor === 0) {
            return;
        }
        $this->journals->assertOpenDate($date);
        $amount = abs($lossMinor);
        if ($amount > 1000000000000) {
            throw ValidationException::withMessages(['amount' => 'Nilai selisih stok terlalu besar.']);
        }
        $expense = Account::query()->where('code', '6-1900')->firstOrFail();
        $stock = Account::query()->where('code', $rawMaterial ? '1-1700' : '1-1600')->firstOrFail();
        $this->journals->post([
            'entry_date' => $date, 'description' => $description,
            'source_type' => $sourceType, 'source_id' => $sourceId,
            'purpose' => $purpose, 'posted_by' => $actorId,
        ], [
            ['account_id' => $expense->id, 'debit_minor' => max(0, $lossMinor), 'credit_minor' => max(0, -$lossMinor)],
            ['account_id' => $stock->id, 'debit_minor' => max(0, -$lossMinor), 'credit_minor' => max(0, $lossMinor)],
        ]);
    }

    private function unitCost(?int $productId, ?int $rawMaterialId, int $kitchenId, Carbon $at): ?int
    {
        if ($productId !== null) {
            return Product::query()->find($productId)?->priceVersionAt($at)?->cost_price_minor;
        }
        $row = StockLedger::query()->where('raw_material_id', $rawMaterialId)
            ->where('location_type', StockLedgerService::RAW_MATERIAL_STORE)
            ->where('location_id', $kitchenId)->where('movement_type', MovementType::PURCHASE_IN)
            ->where('created_at', '<=', $at)->whereNotNull('cost_minor')
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        if (! $row || $row->qty_delta <= 0) {
            return null;
        }

        return intdiv((int) $row->cost_minor + intdiv((int) $row->qty_delta, 2), (int) $row->qty_delta);
    }
}
