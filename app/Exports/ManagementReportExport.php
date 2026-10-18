<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ManagementReportExport implements WithMultipleSheets
{
    public function __construct(private readonly array $sections) {}

    public function sheets(): array
    {
        $sheets = [];
        foreach ($this->sections as $key => $section) {
            $sheets[] = new ManagementReportSheet($key, $section);
        }

        return $sheets;
    }
}
