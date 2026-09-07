<?php

namespace App\Exports;

use App\Models\Attendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * One row per clock-in — the detail behind `AttendanceRateWidget`'s daily percentage.
 */
class AttendanceExport implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(private readonly Carbon $from, private readonly Carbon $to) {}

    public function headings(): array
    {
        return ['Tanggal Operasional', 'Nama', 'Role', 'Jam Masuk'];
    }

    public function collection(): Collection
    {
        return Attendance::query()
            ->with('user:id,name')
            ->whereBetween('operating_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->orderBy('operating_date')
            ->orderBy('clocked_in_at')
            ->get()
            ->map(fn (Attendance $a) => [
                $a->operating_date->toDateString(),
                $a->user?->name,
                $a->role->label(),
                $a->clocked_in_at->format('Y-m-d H:i'),
            ]);
    }

    public function title(): string
    {
        return 'Kehadiran';
    }
}
