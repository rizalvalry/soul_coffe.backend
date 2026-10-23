<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Services\Reporting\InventoryAccountingReportService;
use App\Services\Reporting\StockOverviewService;
use Illuminate\Support\Facades\DB;

/** Checks independent ledger, screen, and accounting projections without changing stock. */
class StockReconciliationService
{
    public function __construct(
        private readonly StockOverviewService $overview,
        private readonly InventoryAccountingReportService $accounting,
    ) {}

    /** @return array<string, mixed>|null */
    public function latestResult(): ?array
    {
        $row = DB::table('stock_reconciliation_results')->orderByDesc('checked_at')->first();
        if ($row === null) {
            return null;
        }

        $history = $this->savedFindings();
        $findings = json_decode($row->findings, true, 512, JSON_THROW_ON_ERROR);
        foreach ($findings as $finding) {
            unset($history[$finding['key'] ?? $this->legacyFindingKey($finding)]);
            if (($finding['key'] ?? null) === ($finding['baseKey'] ?? $finding['key'] ?? null)) {
                $legacyKey = $this->legacyFindingKey($finding);
                if ($finding['code'] !== 'UNPAIRED_TRANSFER'
                    || (isset($history[$legacyKey]['legacyObservedAt'])
                        && ($finding['eventAt'] ?? '') <= $history[$legacyKey]['legacyObservedAt'])) {
                    unset($history[$legacyKey]);
                }
            }
        }

        $history = array_map(function (array $finding): array {
            $finding['isHistory'] = true;

            return $finding;
        }, array_values($history));

        return ['checked_on' => $row->checked_on, 'checked_at' => $row->checked_at,
            'status' => $row->status, 'findings' => $findings, 'history' => $history,
            'pending_cost_rows' => (int) $row->pending_cost_rows,
            'pending_cost_estimated_minor' => (int) $row->pending_cost_estimated_minor];
    }

    /** @return array<string, array<string, mixed>> Latest saved copy of each finding, with its first report date. */
    public function savedFindings(): array
    {
        $saved = [];
        foreach (DB::table('stock_reconciliation_results')->orderBy('checked_at')->orderBy('id')
            ->get(['findings', 'history', 'checked_at', 'checked_on']) as $result) {
            $entries = array_merge(
                json_decode($result->history ?? '[]', true, 512, JSON_THROW_ON_ERROR),
                json_decode($result->findings, true, 512, JSON_THROW_ON_ERROR),
            );
            foreach ($entries as $finding) {
                $wasLegacy = ! array_key_exists('key', $finding);
                $key = $finding['key'] ?? $this->legacyFindingKey($finding);
                // Rows written before this feature sent alerts but had no per-finding timestamp.
                $reportedAt = array_key_exists('reportedAt', $finding)
                    ? $finding['reportedAt'] : $result->checked_at;
                $finding['key'] = $key;
                $finding['reportedAt'] = $saved[$key]['reportedAt'] ?? $reportedAt;
                if ($wasLegacy) {
                    $finding['legacyObservedAt'] = max(
                        $saved[$key]['legacyObservedAt'] ?? $result->checked_at,
                        $result->checked_at,
                    );
                }
                $saved[$key] = $finding;
            }
        }

        return $saved;
    }

    public function legacyFindingKey(array $finding): string
    {
        $eventKey = $finding['code'] === 'NEGATIVE_HISTORY'
            ? 'ledger:'.str_replace('ledger #', '', (string) $finding['reference'])
            : ($finding['reference'] ?? null);

        return hash('sha256', json_encode([$finding['code'], $finding['locationType'],
            $finding['locationId'], $finding['itemType'], $finding['itemId'],
            $eventKey], JSON_THROW_ON_ERROR));
    }

    /** @return array{rows: int, estimated_minor: int} */
    public function pendingCostSummary(): array
    {
        $rows = $this->accounting->pendingCosts();

        return ['rows' => array_sum(array_column($rows, 'count')),
            'estimated_minor' => array_sum(array_column($rows, 'estimated_minor'))];
    }

    /** @return array<int, array<string, int|string|null>> */
    public function findings(): array
    {
        // MySQL's REPEATABLE READ gives every projection the same committed ledger/journal view.
        return DB::transaction(fn (): array => $this->collectFindings());
    }

    /** @return array<int, array<string, int|string|null>> */
    private function collectFindings(): array
    {
        $findings = [];
        $snapshot = $this->overview->snapshot();
        $visibleProductIds = array_fill_keys($snapshot['products']->pluck('id')->all(), true);
        $display = [];
        foreach (['kitchens' => StockLedgerService::KITCHEN, 'carts' => StockLedgerService::CART] as $key => $type) {
            foreach ($snapshot[$key] as $row) {
                $id = (int) $row[$type]->id;
                foreach ($row['qty'] as $productId => $qty) {
                    $display[$this->key($type, $id, 'product', (int) $productId)] = (int) $qty;
                }
            }
        }
        foreach ($snapshot['raw_material_rows'] as $row) {
            foreach ($row['qty'] as $materialId => $qty) {
                $display[$this->key(StockLedgerService::RAW_MATERIAL_STORE,
                    (int) $row['kitchen']->id, 'raw', (int) $materialId)] = (int) $qty;
            }
        }

        $balances = DB::table('stock_ledger')->select('location_type', 'location_id', 'product_id',
            'raw_material_id')->selectRaw('SUM(qty_delta) AS qty')
            ->groupBy('location_type', 'location_id', 'product_id', 'raw_material_id')->get();
        $ledgerKeys = [];
        foreach ($balances as $balance) {
            $kind = $balance->product_id === null ? 'raw' : 'product';
            $itemId = (int) ($balance->product_id ?? $balance->raw_material_id);
            $key = $this->key($balance->location_type, (int) $balance->location_id, $kind, $itemId);
            $ledgerKeys[$key] = true;
            $qty = (int) $balance->qty;
            $shown = $display[$key] ?? null;
            // Retired products remain in the ledger and book value, but no longer have
            // operational stock columns to compare against.
            if (($kind !== 'product' || isset($visibleProductIds[$itemId])) && $shown !== $qty) {
                $findings[] = $this->finding('DISPLAY_MISMATCH', $balance->location_type,
                    (int) $balance->location_id, $kind, $itemId, $qty, $shown);
            }
            if ($qty < 0) {
                $findings[] = $this->finding('NEGATIVE_BALANCE', $balance->location_type,
                    (int) $balance->location_id, $kind, $itemId, $qty, 0);
            }
        }
        foreach ($display as $key => $shown) {
            if (! isset($ledgerKeys[$key]) && $shown !== 0) {
                [$type, $id, $kind, $itemId] = explode(':', $key);
                $findings[] = $this->finding('DISPLAY_MISMATCH', $type,
                    (int) $id, $kind, (int) $itemId, 0, $shown);
            }
        }

        // A later receipt must not erase evidence that an earlier accepted posting went below zero.
        $running = [];
        $negativeHistory = [];
        DB::table('stock_ledger')->select('id', 'location_type', 'location_id', 'product_id', 'raw_material_id', 'qty_delta')
            ->orderBy('id')->chunkById(500, function ($rows) use (&$running, &$negativeHistory): void {
                foreach ($rows as $row) {
                    $kind = $row->product_id === null ? 'raw' : 'product';
                    $itemId = (int) ($row->product_id ?? $row->raw_material_id);
                    $key = $this->key($row->location_type, (int) $row->location_id, $kind, $itemId);
                    $before = $running[$key] ?? 0;
                    $running[$key] = $before + (int) $row->qty_delta;
                    if ($before >= 0 && $running[$key] < 0) {
                        $negativeHistory[] = $this->finding('NEGATIVE_HISTORY', $row->location_type,
                            (int) $row->location_id, $kind, $itemId, $running[$key], 0,
                            'ledger #'.$row->id, 'ledger:'.$row->id);
                    }
                }
            }, 'id');
        array_push($findings, ...$negativeHistory);

        // A transfer has one source and one destination per reference/product, not just net zero.
        foreach ([
            [MovementType::ALLOCATION_OUT, MovementType::ALLOCATION_IN],
            [MovementType::REFILL_OUT, MovementType::REFILL_IN],
            [MovementType::RETURN_OUT, MovementType::RETURN_IN],
        ] as [$out, $in]) {
            $legs = DB::table('stock_ledger')->whereIn('movement_type', [$out->value, $in->value])
                ->select('ref_type', 'ref_id', 'product_id', 'movement_type', 'location_type', 'location_id', 'kitchen_id', 'transfer_pair_id')
                ->selectRaw('DATE(created_at) AS movement_date, MIN(created_at) AS movement_at, SUM(qty_delta) AS qty')
                ->groupBy('ref_type', 'ref_id', 'product_id', 'movement_type', 'location_type', 'location_id', 'kitchen_id', 'transfer_pair_id')
                // Legacy carts use their cart ID as the close-out reference across days.
                // Split batches by day and selected kitchen recorded on both transfer legs.
                ->groupByRaw('DATE(created_at)')
                ->get()->groupBy(fn ($leg): string => $leg->transfer_pair_id !== null
                    ? 'pair:'.$leg->transfer_pair_id.':'.($leg->ref_type ?? '').':'.($leg->ref_id ?? 0)
                        .':'.$leg->product_id.':'.($leg->kitchen_id ?? 0)
                    : 'legacy:'.($leg->ref_type ?? '').':'.($leg->ref_id ?? 0)
                        .':'.$leg->product_id.':'.($leg->kitchen_id ?? 0).':'.$leg->movement_date);
            foreach ($legs as $groupKey => $group) {
                $source = $group->where('movement_type', $out->value)->values();
                $destination = $group->where('movement_type', $in->value)->values();
                $first = $group->first();
                $net = (int) $group->sum('qty');
                $outType = $out === MovementType::RETURN_OUT ? StockLedgerService::CART : StockLedgerService::KITCHEN;
                $inType = $out === MovementType::RETURN_OUT ? StockLedgerService::KITCHEN : StockLedgerService::CART;
                $expected = $this->expectedTransfer($first->ref_type, $first->ref_id, $first->kitchen_id);
                $valid = $first->ref_type !== null && $first->ref_id !== null
                    && $source->count() === 1 && $destination->count() === 1 && $net === 0
                    && $source[0]->location_type === $outType && $destination[0]->location_type === $inType
                    && (int) $source[0]->qty < 0 && (int) $destination[0]->qty > 0
                    && ($expected === null || ($expected[$outType] === (int) $source[0]->location_id
                        && $expected[$inType] === (int) $destination[0]->location_id));
                if (! $valid) {
                    $actual = $group->map(fn ($leg): string => $leg->movement_type.'@'.$leg->location_type
                        .'#'.$leg->location_id.'='.$leg->qty)->implode(', ');
                    $finding = $this->finding('UNPAIRED_TRANSFER', 'transfer',
                        (int) ($first->ref_id ?? 0), 'product', (int) $first->product_id,
                        $net, 0, ($first->ref_type ?? 'tanpa referensi').' '
                            .($first->transfer_pair_id === null ? '' : 'pasangan '.$first->transfer_pair_id.' ')
                            .$actual, $out->value.':'.$groupKey);
                    $finding['eventDate'] = $first->movement_date;
                    $finding['eventAt'] = $first->movement_at;
                    $findings[] = $finding;
                }
            }
        }

        // The all-location total must equal permanent inflows less permanent outflows.
        $totals = DB::table('stock_ledger')->whereNotNull('product_id')->select('product_id')
            ->selectRaw('SUM(qty_delta) AS total')
            ->selectRaw("SUM(CASE WHEN movement_type IN ('ALLOCATION_OUT','ALLOCATION_IN','REFILL_OUT','REFILL_IN','RETURN_OUT','RETURN_IN') THEN 0 ELSE qty_delta END) AS permanent_total")
            ->groupBy('product_id')->get();
        foreach ($totals as $total) {
            if ((int) $total->total !== (int) $total->permanent_total) {
                $findings[] = $this->finding('TOTAL_MISMATCH', 'all', 0, 'product',
                    (int) $total->product_id, (int) $total->total, (int) $total->permanent_total);
            }
        }

        foreach ($this->accounting->inventoryGap(today()) as $row) {
            // Pending rows carry their recorded zero cost in both projections. Show their
            // unresolved estimate separately; never hide an actual book discrepancy with it.
            if ($row['gap_minor'] !== 0) {
                $finding = $this->finding('BOOK_MISMATCH', 'all', 0,
                    'account', $row['account'], $row['physical_minor'], $row['book_minor']);
                // An account gap is aggregate: no one item can be named as its cause.
                // Include zero-balance history: a missing sale journal may be the only gap.
                $candidates = $balances->filter(fn ($balance): bool => ($row['account'] === '1-1600'
                    ? $balance->product_id !== null
                        : $balance->raw_material_id !== null));
                $candidateLabels = $candidates->map(function ($balance): string {
                    $kind = $balance->product_id === null ? 'raw' : 'product';
                    $context = $this->finding('CONTEXT', $balance->location_type,
                        (int) $balance->location_id, $kind,
                        (int) ($balance->product_id ?? $balance->raw_material_id), 0, 0);

                    return $context['locationLabel'].' / '.$context['itemLabel'];
                })->unique()->values();
                $finding['stockCandidates'] = $candidateLabels->take(5)->implode(', ')
                    .($candidateLabels->count() > 5 ? ' dan '.($candidateLabels->count() - 5).' lokasi/produk lain' : '');
                $findings[] = $finding;
            }
        }

        usort($findings, fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));

        return $findings;
    }

    /** @return array<string, int>|null */
    private function expectedTransfer(?string $refType, ?int $refId, ?int $recordedKitchenId): ?array
    {
        if ($refId === null) {
            return null;
        }
        if ($refType === 'cart_closeout_return') {
            $cart = DB::table('carts')->where('id', $refId)->first(['id', 'kitchen_id']);
            // A legacy cart can have no fixed kitchen. Close-out records the selected
            // kitchen on both transfer legs, so use that batch's persisted provenance.
            $kitchenId = $cart?->kitchen_id ?? $recordedKitchenId;

            return [StockLedgerService::KITCHEN => (int) $kitchenId,
                StockLedgerService::CART => (int) ($cart?->id ?? 0)];
        }
        $table = match ($refType) {
            'showcase_handover' => 'staff_assignments',
            'daily_allocation' => 'daily_allocations',
            'refill_request' => 'refill_requests',
            'settlement_return' => 'settlements',
            default => null,
        };
        if ($table === null) {
            return null;
        }
        $row = DB::table($table)->where('id', $refId)->first(['cart_id', ...($table === 'settlements' ? [] : ['kitchen_id'])]);
        if ($row === null) {
            return [StockLedgerService::KITCHEN => 0, StockLedgerService::CART => 0];
        }
        $kitchenId = $table === 'settlements'
            ? DB::table('carts')->where('id', $row->cart_id)->value('kitchen_id')
            : $row->kitchen_id;

        return [StockLedgerService::KITCHEN => (int) $kitchenId,
            StockLedgerService::CART => (int) $row->cart_id];
    }

    private function key(string $type, int $id, string $kind, int $itemId): string
    {
        return $type.':'.$id.':'.$kind.':'.$itemId;
    }

    /** @return array<string, int|string|null> */
    private function finding(string $code, string $locationType, int $locationId, string $itemType,
        int|string $itemId, int $ledger, ?int $compared, ?string $reference = null,
        ?string $eventKey = null): array
    {
        $locationLabel = match ($locationType) {
            StockLedgerService::KITCHEN, StockLedgerService::RAW_MATERIAL_STORE => CentralKitchen::query()->find($locationId)?->name ?? 'Dapur #'.$locationId,
            StockLedgerService::CART => 'Coffee Bike '.(Cart::query()->find($locationId)?->code ?? '#'.$locationId),
            'transfer' => 'Transfer #'.$locationId,
            default => 'Semua lokasi',
        };
        $itemLabel = match ($itemType) {
            'product' => Product::query()->find($itemId)?->name ?? 'Produk #'.$itemId,
            'raw' => RawMaterial::query()->find($itemId)?->name ?? 'Bahan #'.$itemId,
            default => 'Persediaan '.$itemId,
        };

        $key = hash('sha256', json_encode([$code, $locationType, $locationId, $itemType,
            $itemId, $eventKey], JSON_THROW_ON_ERROR));

        return compact('code', 'locationType', 'locationId', 'locationLabel', 'itemType', 'itemId',
            'itemLabel', 'ledger', 'compared', 'reference', 'key');
    }
}
