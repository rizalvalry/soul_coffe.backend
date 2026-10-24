<?php

namespace App\Exports;

use App\Models\CashTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class CashTransactionsExport extends DefaultValueBinder implements FromCollection, WithCustomValueBinder, WithHeadings
{
    public function __construct(private readonly Builder $query) {}

    public function headings(): array
    {
        return ['Tanggal', 'Arah', 'Kategori', 'Akun Kas', 'Nominal (Rp)', 'Keterangan', 'Barang Kantor', 'Jumlah', 'Status', 'Pencatat', 'Harga Satuan (Rp)', 'Karyawan', 'Lokasi'];
    }

    public function collection(): Collection
    {
        return $this->query->with(['category', 'cashAccount', 'officeItem', 'recordedBy', 'employee', 'location'])
            ->orderBy('date')->get()->map(fn (CashTransaction $row): array => [
                $row->date->toDateString(), $row->direction, $row->category->name,
                $row->cashAccount->name, $row->amount_minor, $row->description,
                $row->officeItem?->name, $row->quantity, $row->status, $row->recordedBy?->name,
                $row->unit_price_minor, $row->employee?->name, $row->location?->name,
            ]);
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if ($value !== null && ! in_array($cell->getColumn(), ['E', 'H', 'K'], true)) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
