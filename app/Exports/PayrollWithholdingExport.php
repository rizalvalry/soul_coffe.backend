<?php

namespace App\Exports;

use App\Models\PayrollItem;
use App\Models\ThrItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/** Recap for manual Coretax preparation, not a Coretax import template. */
class PayrollWithholdingExport extends DefaultValueBinder implements FromCollection, WithCustomValueBinder, WithHeadings
{
    public function __construct(private readonly string $period, private readonly bool $annual = false) {}

    public function headings(): array
    {
        return ['Jenis', 'Masa', 'Nama', 'NIK KTP', 'NPWP', 'Status PTKP', 'Bruto (Rp)', 'PPh 21 dipotong (Rp)'];
    }

    public function collection(): Collection
    {
        return DB::transaction(fn (): Collection => $this->buildCollection());
    }

    private function buildCollection(): Collection
    {
        $year = substr($this->period, 0, 4);
        $rows = [];
        $payroll = PayrollItem::query()->with(['run', 'user.employeeProfile'])
            ->whereHas('run', fn ($query) => $query->whereYear('period_start', $year)
                ->whereIn('status', ['APPROVED', 'PAID']))->get();
        foreach ($payroll as $item) {
            $month = $item->run->period_start->format('Y-m');
            if (! $this->annual && $month !== $this->period) {
                continue;
            }
            $s = $item->snapshot;
            $key = $item->user_id.'|'.$month;
            $rows[$key] = [
                'user_id' => $item->user_id, 'month' => $month,
                'gross' => (int) $s['gross_pay_minor'], 'tax' => (int) $s['pph21_minor'],
                'ptkp' => $s['ptkp_status'] ?? $item->user->employeeProfile?->ptkp_status,
            ];
        }
        $thr = ThrItem::query()->with(['run', 'user.employeeProfile'])
            ->whereHas('run', fn ($query) => $query->whereYear('planned_payment_date', $year)
                ->whereIn('status', ['APPROVED', 'PAID']))->get();
        foreach ($thr as $item) {
            $month = $item->run->planned_payment_date->format('Y-m');
            if (! $this->annual && $month !== $this->period) {
                continue;
            }
            $key = $item->user_id.'|'.$month;
            $rows[$key] ??= [
                'user_id' => $item->user_id, 'month' => $month,
                'gross' => 0, 'tax' => 0,
                'ptkp' => $item->snapshot['ptkp_status'] ?? $item->user->employeeProfile?->ptkp_status,
            ];
            $rows[$key]['gross'] += (int) $item->snapshot['gross_minor'];
            $rows[$key]['tax'] += (int) $item->snapshot['pph21_minor'];
        }
        $users = User::query()->with('employeeProfile')->whereIn('id', array_column($rows, 'user_id'))->get()->keyBy('id');
        $result = collect();
        $totals = [];
        usort($rows, fn (array $a, array $b): int => [$a['user_id'], $a['month']] <=> [$b['user_id'], $b['month']]);
        foreach ($rows as $row) {
            $user = $users->get($row['user_id']);
            $result->push(['Masa', $row['month'], $user->name, $user->national_id ?? '',
                $user->employeeProfile?->npwp ?? '', $row['ptkp'] ?? '', $row['gross'], $row['tax']]);
            $totals[$user->id]['gross'] = ($totals[$user->id]['gross'] ?? 0) + $row['gross'];
            $totals[$user->id]['tax'] = ($totals[$user->id]['tax'] ?? 0) + $row['tax'];
            $totals[$user->id]['ptkp'] = $row['ptkp'] ?? '';
        }
        if ($this->annual) {
            foreach ($totals as $id => $total) {
                $user = $users->get($id);
                $result->push(['Rekap A1', $year, $user->name, $user->national_id ?? '',
                    $user->employeeProfile?->npwp ?? '', $total['ptkp'], $total['gross'], $total['tax']]);
            }
        }

        return $result;
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (in_array($cell->getColumn(), ['A', 'B', 'C', 'D', 'E', 'F'], true)) {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
