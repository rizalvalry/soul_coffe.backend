<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class RevenueSheet extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithTitle
{
    public function __construct(private readonly string $name, private readonly array $headings, private readonly array $rows) {}

    public function title(): string
    {
        return $this->name;
    }

    public function array(): array
    {
        return [$this->headings, ...$this->rows];
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
