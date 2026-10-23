<?php

namespace App\Services\Reporting;

use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\StockLedger;
use App\Services\PendingStockCostService;
use App\Services\RecipeService;
use App\Services\StockLedgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryAccountingReportService
{
    public function __construct(private readonly RecipeService $recipes,
        private readonly StockLedgerService $ledger) {}

    public function costVariance(Carbon $month): array
    {
        $at = $month->copy()->endOfMonth();
        $threshold = (int) config('soul.recipe_cost_variance_threshold_percent', 10);

        // Historical monthly reports retain retired product rows from before the 22 Oct decision.
        return Product::query()->when($at->toDateString() >= '2026-10-22',
            fn ($query) => $query->operational())->orderBy('name')->get()
            ->map(function (Product $product) use ($at, $threshold): array {
                $standard = $product->priceVersionAt($at)?->cost_price_minor;
                $recipe = $this->recipes->currentFor($product->id)?->computed_cost_minor;
                $delta = $standard === null || $recipe === null ? null : (int) $recipe - (int) $standard;
                $percent = $delta === null || $standard === 0 ? null
                    : intdiv(abs($delta) * 100 + intdiv((int) $standard, 2), (int) $standard);

                return [
                    'product' => $product->name, 'standard_minor' => $standard,
                    'recipe_minor' => $recipe, 'difference_minor' => $delta,
                    'difference_percent' => $percent,
                    'flagged' => $delta !== null && $standard > 0 && abs($delta) * 100 > $threshold * $standard,
                ];
            })->all();
    }

    public function pendingCosts(): array
    {
        $cutover = app(PendingStockCostService::class)->cutoverDate();
        $rows = StockLedger::query()->where('cost_pending', true)
            ->whereNotIn('stock_ledger.id', DB::table('stock_cost_resolutions')->select('stock_ledger_id'))
            ->when($cutover, fn ($query) => $query->whereDate('stock_ledger.created_at', '>=', $cutover))
            ->with('product')->orderBy('stock_ledger.id')->get();
        $prices = ProductPriceVersion::query()->whereIn('product_id', $rows->pluck('product_id')->unique())
            ->where('cost_price_minor', '>', 0)->orderBy('created_at')->orderBy('id')->get()->groupBy('product_id');
        $products = [];
        foreach ($rows as $row) {
            if ($row->product === null) {
                continue;
            }
            $id = $row->product_id;
            $products[$id] ??= ['product_id' => $id, 'product' => $row->product->name, 'count' => 0,
                'estimated_minor' => 0, 'stock_impact_minor' => 0, 'unpriced_count' => 0];
            $version = ($prices[$id] ?? collect())->first(
                fn (ProductPriceVersion $price): bool => $price->created_at->gte($row->created_at),
            );
            $products[$id]['count']++;
            if ($version) {
                $products[$id]['estimated_minor'] += (int) $row->cost_pending_qty * (int) $version->cost_price_minor;
                $products[$id]['stock_impact_minor'] += ($row->qty_delta < 0 ? -1 : 1)
                    * (int) $row->cost_pending_qty * (int) $version->cost_price_minor;
            } else {
                $products[$id]['unpriced_count']++;
            }
        }

        return array_values($products);
    }

    public function inventoryGap(Carbon $start): array
    {
        $at = $start->copy()->endOfDay();
        $finished = 0;
        $raw = 0;
        $finishedFallback = 0;
        $rawFallback = 0;
        $stocks = StockLedger::query()->where('created_at', '<=', $at)
            ->select('location_type', 'location_id', 'product_id', 'raw_material_id')
            ->selectRaw('SUM(qty_delta) AS quantity')
            ->groupBy('location_type', 'location_id', 'product_id', 'raw_material_id')->get();
        foreach ($stocks as $stock) {
            [, $value, $fallback] = $this->ledger->valuation($stock->location_type,
                (int) $stock->location_id, $stock->product_id, $stock->raw_material_id, $at);
            if ($stock->product_id !== null) {
                $finished += $value;
                $finishedFallback += $fallback;
            } elseif ($stock->location_type === StockLedgerService::RAW_MATERIAL_STORE) {
                $raw += $value;
                $rawFallback += $fallback;
            }
        }
        $book = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->whereDate('e.entry_date', '<=', $start->toDateString())
            ->whereIn('a.code', ['1-1600', '1-1700'])
            ->groupBy('a.code')->select('a.code')
            ->selectRaw('SUM(l.debit_minor) - SUM(l.credit_minor) AS balance')->pluck('balance', 'code');

        return [
            ['account' => '1-1600', 'physical_minor' => $finished, 'fallback_rows' => $finishedFallback,
                'book_minor' => (int) ($book['1-1600'] ?? 0),
                'gap_minor' => $finished - (int) ($book['1-1600'] ?? 0)],
            ['account' => '1-1700', 'physical_minor' => $raw, 'fallback_rows' => $rawFallback,
                'book_minor' => (int) ($book['1-1700'] ?? 0),
                'gap_minor' => $raw - (int) ($book['1-1700'] ?? 0)],
        ];
    }
}
