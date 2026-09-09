<?php

namespace App\Exports;

use App\Enums\AttendanceCode;
use App\Enums\Role;
use App\Models\User;
use App\Services\AttendanceSheetService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * The absensi sheet as a real .xlsx — same column shape as the sheet this module was built from,
 * so the file can go straight into whatever payroll process already consumes it.
 *
 * A lowercase code marks a cell that came from the employee's own clock-in rather than from the
 * office, so the provenance the screen shows survives the export instead of being flattened away.
 */
class AttendanceSheetExport implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
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
        $rows = app(AttendanceSheetService::class)->monthlySheet($this->month, $this->role);

        return $rows->values()->map(function (array $row, int $index): array {
            /** @var User $user */
            $user = $row['user'];
            $summary = $row['summary'];

            $codes = array_map(
                function (array $cell): string {
                    $code = $cell['code'] ?? null;

                    if (! $code instanceof AttendanceCode) {
                        return '';
                    }

                    return $cell['source'] === 'app'
                        ? strtolower($code->value)
                        : $code->value;
                },
                $row['cells'],
            );

            return array_merge(
                [$index + 1, $user->nik, $user->name, $user->uniform_size],
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
