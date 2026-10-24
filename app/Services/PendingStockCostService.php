<?php

namespace App\Services;

use App\Enums\MovementType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\StockLedger;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Settles zero-valued stock movements without rewriting the append-only stock ledger. */
class PendingStockCostService
{
    public function __construct(private readonly JournalService $journals) {}

    public function resolveProduct(Product $product, User $actor): int
    {
        Gate::forUser($actor)->authorize('revalue-inventory');

        return DB::transaction(function () use ($product, $actor): int {
            DB::table('stock_cost_revaluation_locks')->insertOrIgnore(['product_id' => $product->id]);
            DB::table('stock_cost_revaluation_locks')->where('product_id', $product->id)->lockForUpdate()->first();
            $cutover = $this->cutoverDate();
            $pending = StockLedger::query()->where('product_id', $product->id)->where('cost_pending', true)
                ->whereNotIn('id', DB::table('stock_cost_resolutions')->select('stock_ledger_id'))
                ->when($cutover, fn ($query) => $query->whereDate('created_at', '>=', $cutover))
                ->orderBy('id')->get();
            if ($pending->isEmpty()) {
                return 0;
            }

            $versions = ProductPriceVersion::query()->where('product_id', $product->id)
                ->where('cost_price_minor', '>', 0)->orderBy('created_at')->orderBy('id')->get();
            $history = StockLedger::query()->where('product_id', $product->id)->orderBy('id')->get();
            $existing = DB::table('stock_cost_resolutions')->whereIn('stock_ledger_id', $history->pluck('id'))
                ->get()->keyBy('stock_ledger_id');
            $states = [];
            $transfers = [];
            $sales = [];
            $adjustments = [];
            $firstVersionId = null;
            $firstPendingId = $pending->first()->id;
            foreach ($history as $row) {
                $location = $row->location_type.':'.$row->location_id;
                $states[$location] ??= ['quantity' => 0, 'value' => 0, 'original' => 0];
                $state = $states[$location];
                $oldCost = (int) $row->cost_minor + (int) ($existing[$row->id]->resolved_cost_minor ?? 0);
                $cost = $oldCost;
                $versionId = null;
                $isPending = $row->id >= $firstPendingId && $row->cost_pending && ! isset($existing[$row->id])
                    && ($cutover === null || $row->created_at->toDateString() >= $cutover);
                $pair = $row->ref_type.':'.$row->ref_id.':'.$row->product_id;

                if ($row->id >= $firstPendingId && ! isset($existing[$row->id])) {
                    if ($row->movement_type === MovementType::PRODUCTION_IN) {
                        if ($isPending) {
                            $version = $this->firstLaterHpp($versions, $row);
                            $versionId = $version->id;
                            $cost += (int) $row->cost_pending_qty * (int) $version->cost_price_minor;
                        }
                    } elseif (in_array($row->movement_type, [MovementType::ALLOCATION_IN,
                        MovementType::REFILL_IN, MovementType::RETURN_IN], true)) {
                        $outType = match ($row->movement_type) {
                            MovementType::ALLOCATION_IN => MovementType::ALLOCATION_OUT,
                            MovementType::REFILL_IN => MovementType::REFILL_OUT,
                            default => MovementType::RETURN_OUT,
                        };
                        $cost = array_shift($transfers[$outType->value.':'.$pair]) ?? $oldCost;
                    } elseif ($row->movement_type === MovementType::SALE_VOID_IN) {
                        $originalPair = str_replace('_void', '', (string) $row->ref_type).':'.$row->ref_id.':'.$row->product_id;
                        $cost = $sales[$originalPair] ?? $oldCost;
                    } elseif ($state['value'] !== $state['original'] || $isPending) {
                        $quantity = abs((int) $row->qty_delta);
                        $covered = min($quantity, max(0, $state['quantity']));
                        $unit = (int) ($this->effectiveHpp($versions, $row)?->cost_price_minor ?? 0);
                        if (($covered < $quantity || $state['value'] <= 0) && $isPending) {
                            $version = $this->firstLaterHpp($versions, $row);
                            $versionId = $version->id;
                            $unit = (int) $version->cost_price_minor;
                        }
                        if ($state['quantity'] > 0 && $state['value'] > 0) {
                            $cost = $covered === $state['quantity'] ? $state['value']
                                : intdiv($state['value'] * $covered + intdiv($state['quantity'], 2), $state['quantity']);
                            $cost += ($quantity - $covered) * $unit;
                        } elseif ($isPending) {
                            $cost = $oldCost + (int) $row->cost_pending_qty * $unit;
                        }
                    }
                }

                if ($versionId !== null) {
                    $firstVersionId ??= $versionId;
                }
                $difference = $cost - $oldCost;
                if ($difference !== 0 || $isPending) {
                    $adjustments[] = [$row, $versionId, $difference, $isPending];
                }
                $states[$location] = [
                    'quantity' => $state['quantity'] + (int) $row->qty_delta,
                    'value' => $state['value'] + ($row->qty_delta < 0 ? -$cost : $cost),
                    'original' => $state['original'] + ($row->qty_delta < 0 ? -$oldCost : $oldCost),
                ];
                if (in_array($row->movement_type, [MovementType::ALLOCATION_OUT,
                    MovementType::REFILL_OUT, MovementType::RETURN_OUT], true)) {
                    $transfers[$row->movement_type->value.':'.$pair][] = $cost;
                } elseif ($row->movement_type === MovementType::SALE_OUT) {
                    $sales[$pair] = $cost;
                }
            }

            $date = today()->toDateString();
            $this->journals->assertOpenDate($date);
            $accounts = Account::query()->whereIn('code', ['1-1600', '5-1100', '6-1930', '6-1910', '6-1920'])
                ->pluck('id', 'code');
            $totals = [];
            $locationDeltas = [];
            foreach ($adjustments as [$row, , $difference]) {
                if ($difference === 0) {
                    continue;
                }
                [$debit, $credit] = $this->accountsFor($row);
                if ($difference < 0) {
                    [$debit, $credit] = [$credit, $debit];
                }
                $amount = abs($difference);
                $totals[$debit]['debit'] = ($totals[$debit]['debit'] ?? 0) + $amount;
                $totals[$credit]['credit'] = ($totals[$credit]['credit'] ?? 0) + $amount;
                $location = $row->location_type.':'.$row->location_id;
                $locationDeltas[$location] = ($locationDeltas[$location] ?? 0)
                    + ($row->qty_delta < 0 ? -$difference : $difference);
            }
            $lines = [];
            foreach ($totals as $code => $sides) {
                foreach (['debit', 'credit'] as $side) {
                    if (($sides[$side] ?? 0) > 0) {
                        $lines[] = ['account_id' => $accounts[$code],
                            'debit_minor' => $side === 'debit' ? $sides[$side] : 0,
                            'credit_minor' => $side === 'credit' ? $sides[$side] : 0];
                    }
                }
            }
            if ($lines === []) {
                throw ValidationException::withMessages(['cost' => 'Belum ada nilai baru yang bisa dibukukan ya.']);
            }
            $entry = $this->journals->post([
                'entry_date' => $date,
                'description' => mb_strimwidth('Nilai ulang stok '.$product->name.'; gerak #'.$pending->first()->id.'–#'.$pending->last()->id,
                    0, 255, ''),
                'source_type' => 'stock_cost_revaluation', 'source_id' => $pending->first()->id,
                'purpose' => 'REVALUE', 'posted_by' => $actor->id,
            ], $lines);

            $resolvedAt = now();
            $resolutions = [];
            foreach ($adjustments as [$row, $versionId, $difference]) {
                $resolutions[] = [
                    'stock_ledger_id' => $row->id,
                    'product_price_version_id' => $versionId ?? $firstVersionId,
                    'journal_entry_id' => $entry->id,
                    'resolved_by' => $actor->id,
                    'resolved_cost_minor' => $difference,
                    'resolved_at' => $resolvedAt,
                ];
            }
            foreach (array_chunk($resolutions, 500) as $chunk) {
                DB::table('stock_cost_resolutions')->insert($chunk);
            }
            foreach ($locationDeltas as $location => $amount) {
                [$locationType, $locationId] = explode(':', $location);
                DB::table('stock_valuation_locks')->where('location_type', $locationType)
                    ->where('location_id', (int) $locationId)->where('catalogue_type', 'product')
                    ->where('catalogue_id', $product->id)
                    ->increment('value_minor', $amount);
            }
            AuditLog::query()->create([
                'actor_id' => $actor->id, 'actor_role' => $actor->role->value,
                'action' => 'inventory.cost_revalued', 'subject_type' => Product::class,
                'subject_id' => $product->id,
                'after_json' => ['journal_entry_id' => $entry->id, 'count' => $pending->count(),
                    'first_stock_ledger_id' => $pending->first()->id, 'last_stock_ledger_id' => $pending->last()->id],
                'ip' => request()->ip(),
            ]);

            return $pending->count();
        }, 3);
    }

    public function cutoverDate(): ?string
    {
        return JournalEntry::query()->where('source_type', 'opening_balance')->where('purpose', 'OPENING')
            ->whereDoesntHave('reversal')->first()?->entry_date->toDateString();
    }

    private function firstLaterHpp($versions, StockLedger $row): ProductPriceVersion
    {
        $version = $versions->first(fn (ProductPriceVersion $price): bool => $price->created_at->gte($row->created_at));
        if ($version === null) {
            throw ValidationException::withMessages(['cost' => 'HPP baru untuk gerak stok tertunda belum diisi ya.']);
        }

        return $version;
    }

    private function effectiveHpp($versions, StockLedger $row): ?ProductPriceVersion
    {
        return $versions->filter(fn (ProductPriceVersion $price): bool => $price->effective_from->lte($row->created_at))
            ->sort(fn (ProductPriceVersion $a, ProductPriceVersion $b): int => $b->effective_from <=> $a->effective_from ?: $b->id <=> $a->id)
            ->first();
    }

    /** @return array{string,string} Debit and credit account codes for the missing value. */
    private function accountsFor(StockLedger $row): array
    {
        $stock = '1-1600';
        $counter = match (true) {
            $row->movement_type === MovementType::SALE_OUT,
            $row->movement_type === MovementType::SALE_VOID_IN,
            $row->ref_type === 'rider_closing_sale_compensation' => '5-1100',
            $row->movement_type === MovementType::WASTE_OUT && $row->ref_type === 'promotional_use' => '6-1930',
            $row->movement_type === MovementType::WASTE_OUT,
            $row->movement_type === MovementType::OPNAME_ADJUSTMENT,
            in_array($row->ref_type, ['stock_adjustment', 'cart_closeout_count_adjustment',
                'rider_closing_after_disposition', 'rider_closing_waste_compensation',
                'kitchen_reject_correction'], true) => '6-1910',
            default => '6-1920',
        };

        return $row->qty_delta < 0 ? [$counter, $stock] : [$stock, $counter];
    }
}
