<?php

namespace App\Services\Reporting;

use App\Enums\MovementType;
use App\Models\Product;
use App\Models\StockLedger;
use App\Services\RecipeService;
use App\Services\StockLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryAccountingReportService
{
    public function __construct(private readonly RecipeService $recipes) {}

    public function costVariance(Carbon $month): array
    {
        $at = $month->copy()->endOfMonth();
        $threshold = (int) config('soul.recipe_cost_variance_threshold_percent', 10);

        return Product::query()->where('is_active', true)->orderBy('name')->get()
            ->map(function (Product $product) use ($at, $threshold): array {
                $standard = $product->priceVersionAt($at)?->cost_price_minor;
                $recipe = $this->recipes->currentFor($product->id)?->computed_cost_minor;
                $delta = $standard === null || $recipe === null ? null : (int) $recipe - (int) $standard;
                $percent = $delta === null || $standard === 0 ? null
                    : intdiv(abs($delta) * 100 + intdiv((int) $standard, 2), (int) $standard);

                return [
                    'product' => $product->name, 'standard_minor' => $standard,
                    'recipe_minor' => $recipe, 'difference_minor' => $delta,
                    'difference_percent' => $percent, 'flagged' => $percent !== null && $percent > $threshold,
                ];
            })->all();
    }

    public function inventoryGap(Carbon $start): array
    {
        $at = $start->copy()->endOfDay();
        $finished = 0;
        $raw = 0;
        $stocks = StockLedger::query()->where('created_at', '<=', $at)
            ->select('location_type', 'location_id', 'product_id', 'raw_material_id')
            ->selectRaw('SUM(qty_delta) AS quantity')
            ->groupBy('location_type', 'location_id', 'product_id', 'raw_material_id')->get();
        foreach ($stocks as $stock) {
            $quantity = (int) $stock->quantity;
            if ($quantity === 0) {
                continue;
            }
            if ($stock->product_id !== null) {
                $unit = Product::query()->find($stock->product_id)?->priceVersionAt($at)?->cost_price_minor;
                $finished += $quantity * (int) ($unit ?? 0);
            } elseif ($stock->location_type === StockLedgerService::RAW_MATERIAL_STORE) {
                $receipt = StockLedger::query()->where('location_type', StockLedgerService::RAW_MATERIAL_STORE)
                    ->where('location_id', $stock->location_id)->where('raw_material_id', $stock->raw_material_id)
                    ->where('movement_type', MovementType::PURCHASE_IN)->where('created_at', '<=', $at)
                    ->whereNotNull('cost_minor')->orderByDesc('created_at')->orderByDesc('id')->first();
                if ($receipt && $receipt->qty_delta > 0) {
                    $unit = intdiv((int) $receipt->cost_minor + intdiv((int) $receipt->qty_delta, 2), (int) $receipt->qty_delta);
                    $raw += $quantity * $unit;
                }
            }
        }
        $book = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->whereDate('e.entry_date', '<=', $start->toDateString())
            ->whereIn('a.code', ['1-1600', '1-1700'])
            ->groupBy('a.code')->select('a.code')
            ->selectRaw('SUM(l.debit_minor) - SUM(l.credit_minor) AS balance')->pluck('balance', 'code');

        return [
            ['account' => '1-1600', 'physical_minor' => $finished, 'book_minor' => (int) ($book['1-1600'] ?? 0),
                'gap_minor' => $finished - (int) ($book['1-1600'] ?? 0)],
            ['account' => '1-1700', 'physical_minor' => $raw, 'book_minor' => (int) ($book['1-1700'] ?? 0),
                'gap_minor' => $raw - (int) ($book['1-1700'] ?? 0)],
        ];
    }
}
