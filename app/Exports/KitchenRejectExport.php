<?php

namespace App\Exports;

use App\Enums\KitchenRejectReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class KitchenRejectExport extends DefaultValueBinder implements FromQuery, WithCustomValueBinder, WithHeadings, WithMapping
{
    public function __construct(private readonly Builder $filtered, private readonly bool $includeCost) {}

    public function headings(): array
    {
        return ['Tanggal asal', 'Dapur', 'Produk', 'Jumlah neto saat ini', 'HPP neto saat ini (Rp)', 'Alasan', 'Pelaku', 'Status', 'Catatan'];
    }

    public function query()
    {
        $ids = (clone $this->filtered)->select('kitchen_rejects.id')->reorder()->toBase();

        return DB::table('kitchen_reject_lines as l')
            ->join('kitchen_rejects as r', 'r.id', '=', 'l.kitchen_reject_id')
            ->join('central_kitchens as k', 'k.id', '=', 'r.kitchen_id')
            ->join('products as p', 'p.id', '=', 'l.product_id')
            ->join('users as u', 'u.id', '=', 'r.actor_id')
            ->join('stock_ledger as s', function ($join): void {
                $join->on('s.ref_id', '=', 'r.id')->on('s.product_id', '=', 'l.product_id')
                    ->where('s.ref_type', '=', 'kitchen_reject');
            })
            ->leftJoin('stock_cost_resolutions as cr', 'cr.stock_ledger_id', '=', 's.id')
            ->whereIn('r.id', $ids)
            ->orderBy('l.id')
            ->select('l.id', 'r.operating_date', 'k.name as kitchen_name', 'p.name as product_name',
                'l.qty', 's.cost_minor', 'cr.resolved_cost_minor', 'r.reason_code',
                'u.name as actor_name', 'r.status', 'r.note');
    }

    public function map($row): array
    {
        $active = $row->status !== 'CORRECTED';

        return [$row->operating_date, $row->kitchen_name, $row->product_name,
            $active ? (int) $row->qty : 0,
            $this->includeCost ? ($active ? (int) $row->cost_minor + (int) $row->resolved_cost_minor : 0) : '',
            KitchenRejectReason::labelFor($row->reason_code), $row->actor_name, $row->status, $row->note];
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
