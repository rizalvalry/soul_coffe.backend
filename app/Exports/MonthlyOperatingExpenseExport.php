<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class MonthlyOperatingExpenseExport extends DefaultValueBinder implements FromCollection, WithCustomValueBinder, WithHeadings
{
    public function __construct(private readonly array $matrix) {}

    public function headings(): array
    {
        $headings = ['Kategori'];
        foreach ($this->matrix['days'] as $day) {
            $headings[] = $day.' Jumlah';
            $headings[] = $day.' Rupiah';
        }

        return [...$headings, 'Total Jumlah', 'Total Rupiah'];
    }

    public function collection(): Collection
    {
        $rows = [];
        foreach ($this->matrix['rows'] as $category) {
            $row = [$category['category']];
            foreach ($this->matrix['days'] as $day) {
                $row[] = $category['cells'][$day]['quantity'] ?? 0;
                $row[] = $category['cells'][$day]['amount_minor'] ?? 0;
            }
            $rows[] = [...$row, $category['quantity'], $category['amount_minor']];
        }
        $total = ['Total'];
        foreach ($this->matrix['days'] as $day) {
            $total[] = array_sum(array_map(fn (array $row): int => $row['cells'][$day]['quantity'] ?? 0, $this->matrix['rows']));
            $total[] = array_sum(array_map(fn (array $row): int => $row['cells'][$day]['amount_minor'] ?? 0, $this->matrix['rows']));
        }
        $rows[] = [...$total, $this->matrix['totals']['quantity'], $this->matrix['totals']['amount_minor']];

        return collect($rows);
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if ($cell->getColumn() === 'A' && $value !== null) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
