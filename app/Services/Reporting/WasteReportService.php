<?php

namespace App\Services\Reporting;

use App\Enums\WasteReason;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class WasteReportService
{
    /** @return array<string, array{title:string,headings:array,rows:array,money:array}> */
    public function sections(string $month, ?int $kitchenId = null, ?int $cartId = null): array
    {
        Validator::make(compact('month', 'kitchenId', 'cartId'), [
            'month' => ['required', 'date_format:Y-m'],
            'kitchenId' => ['nullable', 'integer', 'exists:central_kitchens,id'],
            'cartId' => ['nullable', 'integer', 'exists:carts,id'],
        ])->validate();
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $days = range(1, $end->day);
        $headings = ['Produk', 'Alasan'];
        $money = [];
        foreach ($days as $day) {
            $headings[] = sprintf('%02d cup', $day);
            $headings[] = sprintf('%02d HPP', $day);
            $money[] = count($headings) - 1;
        }
        $headings = [...$headings, 'Total cup', 'Total HPP', '% produk'];
        $money[] = count($headings) - 2;

        $movements = DB::table('stock_ledger as s')
            ->join('waste_classifications as w', 'w.stock_ledger_id', '=', 's.id')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->leftJoin('stock_cost_resolutions as cr', 'cr.stock_ledger_id', '=', 's.id')
            ->where('s.movement_type', 'WASTE_OUT')->whereNotNull('s.product_id')
            ->whereBetween('s.created_at', [$start, $end])
            ->when($kitchenId, fn ($query) => $query->where('s.kitchen_id', $kitchenId))
            ->when($cartId, fn ($query) => $query->where('s.location_type', 'cart')->where('s.location_id', $cartId))
            ->orderBy('p.name')->orderBy('s.id')
            ->get(['p.id as product_id', 'p.name as product_name', 's.qty_delta', 's.cost_minor',
                's.created_at', 'w.reason_code', 'cr.resolved_cost_minor']);
        $products = [];
        $grandQty = 0;
        foreach ($movements as $movement) {
            $id = (int) $movement->product_id;
            $reason = (string) $movement->reason_code;
            $day = (int) Carbon::parse($movement->created_at)->day;
            $products[$id] ??= ['name' => $movement->product_name, 'reasons' => [], 'qty' => 0, 'cost' => 0, 'cells' => []];
            $products[$id]['reasons'][$reason] ??= ['qty' => 0, 'cost' => 0, 'cells' => []];
            $qty = -(int) $movement->qty_delta;
            $cost = (int) $movement->cost_minor + (int) $movement->resolved_cost_minor;
            foreach ([$reason, null] as $key) {
                $target = $key === null ? $products[$id] : $products[$id]['reasons'][$key];
                $target['qty'] += $qty;
                $target['cost'] += $cost;
                $target['cells'][$day]['qty'] = ($target['cells'][$day]['qty'] ?? 0) + $qty;
                $target['cells'][$day]['cost'] = ($target['cells'][$day]['cost'] ?? 0) + $cost;
                if ($key === null) {
                    $products[$id] = $target;
                } else {
                    $products[$id]['reasons'][$key] = $target;
                }
            }
            $grandQty += $qty;
        }
        $rows = [];
        foreach ($products as $product) {
            foreach (WasteReason::cases() as $reason) {
                $record = $product['reasons'][$reason->value] ?? null;
                if ($record === null) {
                    continue;
                }
                $rows[] = $this->matrixRow($product['name'], $reason->label(), $days, $record,
                    $this->percent($record['qty'], $product['qty']));
            }
            $rows[] = $this->matrixRow('Total '.$product['name'], 'Semua alasan', $days, $product,
                $this->percent($product['qty'], $grandQty));
        }

        $packaging = DB::table('packaging_wastes as w')
            ->join('raw_materials as r', 'r.id', '=', 'w.raw_material_id')
            ->whereBetween('w.operating_date', [$start->toDateString(), $end->toDateString()])
            ->when($kitchenId, fn ($query) => $query->where('w.kitchen_id', $kitchenId))
            ->when($cartId, fn ($query) => $query->whereRaw('1 = 0'))
            ->orderBy('r.name')->get(['r.id as material_id', 'r.name', 'w.operating_date', 'w.qty', 'w.cost_minor']);
        $packagingRows = [];
        foreach ($packaging as $record) {
            $day = (int) Carbon::parse($record->operating_date)->day;
            $id = (int) $record->material_id;
            $packagingRows[$id] ??= ['name' => $record->name, 'qty' => 0, 'cost' => 0, 'cells' => []];
            $packagingRows[$id]['qty'] += (int) $record->qty;
            $packagingRows[$id]['cost'] += (int) $record->cost_minor;
            $packagingRows[$id]['cells'][$day]['qty'] = ($packagingRows[$id]['cells'][$day]['qty'] ?? 0) + (int) $record->qty;
            $packagingRows[$id]['cells'][$day]['cost'] = ($packagingRows[$id]['cells'][$day]['cost'] ?? 0) + (int) $record->cost_minor;
        }
        $packagingHeadings = ['Cup kemasan'];
        $packagingMoney = [];
        foreach ($days as $day) {
            $packagingHeadings[] = sprintf('%02d cup', $day);
            $packagingHeadings[] = sprintf('%02d biaya', $day);
            $packagingMoney[] = count($packagingHeadings) - 1;
        }
        $packagingHeadings = [...$packagingHeadings, 'Total cup', 'Total biaya'];
        $packagingMoney[] = count($packagingHeadings) - 1;
        $cupRows = [];
        foreach ($packagingRows as $record) {
            $line = [$record['name']];
            foreach ($days as $day) {
                $line[] = $record['cells'][$day]['qty'] ?? 0;
                $line[] = $record['cells'][$day]['cost'] ?? 0;
            }
            $cupRows[] = [...$line, $record['qty'], $record['cost']];
        }

        return [
            'barang_terbuang' => ['title' => 'Barang Terbuang '.$month, 'headings' => $headings,
                'rows' => $rows, 'money' => $money],
            'cup_kemasan' => ['title' => 'Cup kemasan terbuang '.$month, 'headings' => $packagingHeadings,
                'rows' => $cupRows, 'money' => $packagingMoney],
        ];
    }

    private function matrixRow(string $name, string $reason, array $days, array $record, string $percent): array
    {
        $row = [$name, $reason];
        foreach ($days as $day) {
            $row[] = $record['cells'][$day]['qty'] ?? 0;
            $row[] = $record['cells'][$day]['cost'] ?? 0;
        }

        return [...$row, $record['qty'], $record['cost'], $percent];
    }

    private function percent(int $part, int $whole): string
    {
        if ($whole === 0) {
            return '0,00%';
        }
        $basisPoints = intdiv($part * 10000, $whole);

        return intdiv($basisPoints, 100).','.str_pad((string) ($basisPoints % 100), 2, '0', STR_PAD_LEFT).'%';
    }
}
