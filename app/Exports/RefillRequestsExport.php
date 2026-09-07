<?php

namespace App\Exports;

use App\Models\RefillRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * One row per refill request in the reporting window — the operational detail behind
 * `RefillVolumeWidget`'s daily counts and `RefillStatusDistributionWidget`'s breakdown.
 */
class RefillRequestsExport implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(private readonly Carbon $from, private readonly Carbon $to) {}

    public function headings(): array
    {
        return [
            'Tanggal Operasional', 'Kode', 'Gerobak', 'Dapur', 'Staff', 'Status',
            'Diajukan', 'Diputuskan Finance', 'Disiapkan', 'Diambil Rider', 'Diterima Staff',
            'Total Biaya (Rp)', 'Alasan Keputusan/Kekurangan',
        ];
    }

    public function collection(): Collection
    {
        return RefillRequest::query()
            ->with(['cart:id,code', 'kitchen:id,name', 'staff:id,name'])
            ->whereBetween('operating_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->orderBy('operating_date')
            ->orderBy('id')
            ->get()
            ->map(fn (RefillRequest $r) => [
                $r->operating_date->toDateString(),
                $r->code,
                $r->cart?->code,
                $r->kitchen?->name,
                $r->staff?->name,
                $r->status->label(),
                $this->fmt($r->submitted_at),
                $this->fmt($r->decided_at),
                $this->fmt($r->prepared_at),
                $this->fmt($r->picked_up_at),
                $this->fmt($r->delivered_at),
                $r->total_cost_minor,
                $r->decision_reason ?? $r->shortfall_reason,
            ]);
    }

    public function title(): string
    {
        return 'Refill Requests';
    }

    private function fmt(?Carbon $moment): ?string
    {
        return $moment?->format('Y-m-d H:i');
    }
}
