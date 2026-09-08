<?php

namespace App\Exports;

use App\Enums\PartnerAttendanceCode;
use App\Enums\Role;
use App\Models\Partner;
use App\Services\PartnerAttendanceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * The absensi grid as a real .xlsx — the same shape as the sheet this module was built from, so
 * the file can go straight into whatever payroll process already consumes it.
 *
 * One row per partner, one column per day, then the summary columns. Day headers are plain day
 * numbers (1..31) exactly as on the reference sheet.
 */
class PartnerAttendanceExport implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(
        private readonly Carbon $month,
        private readonly ?Role $role = null,
    ) {}

    public function headings(): array
    {
        $days = range(1, $this->month->copy()->endOfMonth()->day);

        return array_merge(
            ['No', 'NIK', 'Nama Karyawan', 'SIZE'],
            array_map('strval', $days),
            [
                'Libur (L)',
                'Sakit (S)',
                'Berangkat Siang / Tidak Target',
                'Hadir (M)',
                'Jatah Klibur',
                'Lebih dari Jatah / Tidak Masuk',
                'Presentase Kehadiran',
            ],
        );
    }

    public function collection(): Collection
    {
        $rows = app(PartnerAttendanceService::class)->monthlySheet($this->month, $this->role);

        return $rows->values()->map(function (array $row, int $index): array {
            /** @var Partner $partner */
            $partner = $row['partner'];
            $summary = $row['summary'];

            $codes = array_map(
                fn (?PartnerAttendanceCode $code): string => $code?->value ?? '',
                $row['codes'],
            );

            return array_merge(
                [$index + 1, $partner->nik, $partner->name, $partner->size],
                array_values($codes),
                [
                    $summary['libur'],
                    $summary['sakit'],
                    $summary['late'],
                    $summary['hadir'],
                    $summary['quota'],
                    $summary['over_quota'],
                    // Written as a number, not "88%": a spreadsheet formula downstream can use
                    // this cell, which a string would break.
                    $summary['attendance_rate'] / 100,
                ],
            );
        });
    }

    public function title(): string
    {
        return 'Absensi '.$this->month->format('M-y');
    }
}
