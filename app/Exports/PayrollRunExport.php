<?php

namespace App\Exports;

use App\Models\PayrollRun;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class PayrollRunExport extends DefaultValueBinder implements FromCollection, WithCustomValueBinder, WithHeadings
{
    public function __construct(private readonly PayrollRun $run) {}

    public function headings(): array
    {
        return ['Periode', 'Karyawan', 'Gaji pokok', 'Komisi target harian', 'Bruto', 'BPJS pegawai', 'BPJS perusahaan', 'PPh 21', 'Potongan manual', 'Take-home pay', 'Status run'];
    }

    public function collection(): Collection
    {
        return $this->run->items()->with('user')->orderBy('user_id')->get()->map(function ($item): array {
            $s = $item->snapshot;

            return [$this->run->period_start->format('Y-m'), $item->user->name,
                $s['base_salary_minor'], $s['rider_incentive_minor'], $s['gross_pay_minor'],
                $s['bpjs_employee_total_minor'], $s['bpjs_employer_total_minor'],
                $s['pph21_minor'], $s['manual_deductions_minor'], $s['take_home_minor'],
                $this->run->status === 'CANCELLED' ? 'Dibatalkan' : $this->run->status];
        });
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (in_array($cell->getColumn(), ['A', 'B', 'K'], true)) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
