<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class ManagementReportSheet extends DefaultValueBinder implements FromArray, WithCustomValueBinder, WithTitle
{
    public function __construct(private readonly string $key, private readonly array $section) {}

    public function title(): string
    {
        return str_replace('_', ' ', $this->key);
    }

    public function array(): array
    {
        return [$this->section['headings'], ...$this->section['rows']];
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
