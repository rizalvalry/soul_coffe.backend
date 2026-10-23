<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\StockLedger;
use Illuminate\Validation\ValidationException;

/** Accounting side of physical stock movements; the stock ledger remains append-only. */
class InventoryJournalService
{
    public function __construct(private readonly JournalService $journals) {}

    public function postMovement(StockLedger $movement): void
    {
        if (! $this->journals->isAccountingDate($movement->created_at->toDateString())) {
            return;
        }
        if ($movement->movement_type === MovementType::SALE_OUT
            || ($movement->movement_type === MovementType::ADJUSTMENT
                && $movement->ref_type === 'rider_closing_sale_compensation')) {
            $this->postSaleCost($movement);

            return;
        }
        if ($movement->movement_type === MovementType::SALE_VOID_IN) {
            $originalType = str_replace('_void', '', (string) $movement->ref_type);
            $out = StockLedger::query()->where('location_type', $movement->location_type)
                ->where('location_id', $movement->location_id)->where('product_id', $movement->product_id)
                ->where('movement_type', MovementType::SALE_OUT)->where('ref_type', $originalType)
                ->where('ref_id', $movement->ref_id)->first();
            $costEntry = $out ? JournalEntry::query()->where('source_type', 'stock_ledger')
                ->where('source_id', $out->id)->where('purpose', 'SALE_COGS')->first() : null;
            if ($costEntry && ! $costEntry->reversal) {
                $this->journals->reverse($costEntry, 'Penjualan dibatalkan');
                $revalued = (int) $movement->cost_minor - (int) $out->cost_minor;
                if ($revalued !== 0) {
                    $this->postSaleCost($movement, $revalued, 'SALE_COGS_REVALUE_VOID');
                }
            } elseif (! $costEntry) {
                $this->postSaleCost($movement);
            }

            return;
        }
        $isWaste = $movement->movement_type === MovementType::WASTE_OUT
            || ($movement->movement_type === MovementType::ADJUSTMENT
                && $movement->ref_type === 'rider_closing_waste_compensation');
        $isAdjustment = $movement->movement_type === MovementType::OPNAME_ADJUSTMENT
            || ($movement->movement_type === MovementType::ADJUSTMENT
                && in_array($movement->ref_type, ['stock_adjustment', 'cart_closeout_count_adjustment', 'rider_closing_after_disposition', 'kitchen_reject_correction'], true));
        if (! $isWaste && ! $isAdjustment) {
            return;
        }
        $this->journals->assertOpenDate($movement->created_at->toDateString());
        $amount = (int) $movement->cost_minor;
        if ($amount === 0) {
            return;
        }
        $this->postAmount('stock_ledger', $movement->id, 'STOCK_LOSS',
            $movement->created_at->toDateString(), $movement->qty_delta < 0 ? $amount : -$amount,
            $movement->raw_material_id !== null,
            $movement->actor_id, 'Rusak / selisih stok #'.$movement->id);
    }

    private function postSaleCost(StockLedger $movement, ?int $overrideAmount = null,
        string $purpose = 'SALE_COGS'): void
    {
        $this->journals->assertOpenDate($movement->created_at->toDateString());
        $signedAmount = $overrideAmount ?? (int) $movement->cost_minor;
        if ($signedAmount === 0) {
            return;
        }
        $amount = abs($signedAmount);
        $stock = Account::query()->where('code', '1-1600')->firstOrFail();
        $expense = Account::query()->where('code', '5-1100')->firstOrFail();
        $out = ($movement->qty_delta < 0) !== ($signedAmount < 0);
        $this->journals->post([
            'entry_date' => $movement->created_at->toDateString(),
            'description' => 'HPP stok terjual #'.$movement->id,
            'source_type' => 'stock_ledger', 'source_id' => $movement->id,
            'purpose' => $purpose,
            'posted_by' => $movement->actor_id,
        ], [
            ['account_id' => $expense->id, 'debit_minor' => $out ? $amount : 0, 'credit_minor' => $out ? 0 : $amount],
            ['account_id' => $stock->id, 'debit_minor' => $out ? 0 : $amount, 'credit_minor' => $out ? $amount : 0],
        ]);
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
        $expense = Account::query()->where('code', '6-1910')->firstOrFail();
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
}
