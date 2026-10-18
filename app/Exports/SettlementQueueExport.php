<?php

namespace App\Exports;

use App\Services\SettlementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SettlementQueueExport implements FromCollection, ShouldAutoSize, WithHeadings
{
    public function __construct(private readonly Carbon $date) {}

    public function headings(): array
    {
        return ['Tanggal', 'Coffee Bike', 'Rider', 'Tiba', 'Ekspektasi Tunai Live', 'Ekspektasi QRIS Live', 'Ekspektasi Transfer Live', 'Ekspektasi Total Live', 'Ekspektasi Tercatat', 'Perubahan Live', 'Tunai Diterima', 'QRIS Diterima', 'Transfer Diterima', 'Selisih', 'Status'];
    }

    public function collection(): Collection
    {
        return collect(app(SettlementService::class)->queue($this->date))->map(fn (array $row): array => [
            $this->date->toDateString(), $this->safeText($row['cart_code']), $this->safeText($row['staff_name']), $row['arrived_at'],
            $row['expected_cash_minor'], $row['expected_qris_minor'], $row['expected_transfer_minor'], $row['expected_total_minor'],
            $row['snapshot_expected_total_minor'], $row['stale_delta_minor'],
            $row['cash_minor'], $row['qris_minor'], $row['transfer_minor'], $row['variance_minor'], $row['settlement_status'],
        ]);
    }

    private function safeText(?string $value): ?string
    {
        return $value !== null && preg_match('/^\s*[=+\-@]/u', $value) ? "'".$value : $value;
    }
}
