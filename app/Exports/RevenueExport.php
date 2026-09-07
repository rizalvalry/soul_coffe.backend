<?php

namespace App\Exports;

use App\Models\Settlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * One row per cart-settlement in the window — the detail behind `RevenueTrendWidget`'s daily
 * totals and `OperationsOverviewWidget`'s "Selisih Kas" figure.
 */
class RevenueExport implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(private readonly Carbon $from, private readonly Carbon $to) {}

    public function headings(): array
    {
        return [
            'Tanggal Operasional', 'Gerobak', 'Staff', 'Status',
            'Tunai (Rp)', 'QRIS (Rp)', 'Transfer (Rp)',
            'Total Dilaporkan (Rp)', 'Total Diharapkan (Rp)', 'Selisih (Rp)', 'Alasan Selisih',
            'Direkonsiliasi Oleh', 'Waktu Rekonsiliasi',
        ];
    }

    public function collection(): Collection
    {
        return Settlement::query()
            ->with(['cart:id,code', 'staff:id,name', 'reconciledBy:id,name'])
            ->whereBetween('operating_date', [$this->from->toDateString(), $this->to->toDateString()])
            ->orderBy('operating_date')
            ->orderBy('id')
            ->get()
            ->map(fn (Settlement $s) => [
                $s->operating_date->toDateString(),
                $s->cart?->code,
                $s->staff?->name,
                $s->status,
                $s->cash_minor,
                $s->qris_minor,
                $s->transfer_minor,
                $s->declared_total_minor,
                $s->expected_total_minor,
                $s->variance_minor,
                $s->variance_reason,
                $s->reconciledBy?->name,
                $s->reconciled_at?->format('Y-m-d H:i'),
            ]);
    }

    public function title(): string
    {
        return 'Pendapatan';
    }
}
