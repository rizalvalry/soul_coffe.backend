<?php

namespace App\Exports;

use App\Models\DirectSale;
use App\Models\Settlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class RevenueExport implements WithMultipleSheets
{
    public function __construct(private readonly Carbon $from, private readonly Carbon $to) {}

    public function sheets(): array
    {
        // Both sheets use the same accounting snapshot.
        return DB::transaction(function (): array {
            $rows = DB::table('journal_lines as line')
                ->join('journal_entries as entry', 'entry.id', '=', 'line.entry_id')
                ->join('accounts as account', 'account.id', '=', 'line.account_id')
                ->whereBetween('entry.entry_date', [$this->from->toDateString(), $this->to->toDateString()])
                ->whereIn('account.code', ['4-1100', '4-1200'])
                ->orderBy('entry.entry_date')->orderBy('entry.id')->orderBy('line.id')
                ->get(['entry.id as entry_id', 'entry.entry_date', 'entry.source_type', 'entry.source_id',
                    'entry.purpose', 'entry.description', 'line.debit_minor', 'line.credit_minor']);
            $entries = $rows->groupBy('entry_id');
            $tax = DB::table('journal_lines as line')
                ->join('accounts as account', 'account.id', '=', 'line.account_id')
                ->whereIn('line.entry_id', $entries->keys())
                ->where('account.code', '2-1500')
                ->groupBy('line.entry_id')->select('line.entry_id')
                ->selectRaw('SUM(CAST(line.credit_minor AS SIGNED) - CAST(line.debit_minor AS SIGNED)) AS amount')
                ->pluck('amount', 'entry_id');
            $settlements = Settlement::query()->with(['cart:id,code', 'staff:id,name'])
                ->whereIn('id', $rows->where('source_type', 'settlement')->pluck('source_id')->unique())
                ->get()->keyBy('id');
            $directSales = DirectSale::query()->with('cart:id,code')
                ->whereIn('id', $rows->where('source_type', 'direct_sale')->pluck('source_id')->unique())
                ->get()->keyBy('id');
            $detail = [];
            $total = 0;
            $representedSettlements = [];
            foreach ($entries as $group) {
                $entry = $group->first();
                $net = (int) $group->sum(fn ($line): int => (int) $line->credit_minor - (int) $line->debit_minor);
                $total += $net;
                $settlement = $entry->source_type === 'settlement' && $entry->purpose === 'SETTLEMENT'
                    ? $settlements->get($entry->source_id) : null;
                $direct = $entry->source_type === 'direct_sale' && $entry->purpose === 'DIRECT_SALE'
                    ? $directSales->get($entry->source_id) : null;
                if ($settlement?->status === 'RECONCILED') {
                    $representedSettlements[$settlement->id] = true;
                    $pbjt = (int) ($tax[$entry->entry_id] ?? 0);
                    $detail[] = [$entry->entry_date, 'Setoran', $settlement->cart?->code,
                        $settlement->staff?->name, $net + $pbjt, $pbjt, $net, $entry->description];
                } elseif ($direct && ! $direct->isVoided()) {
                    $pbjt = (int) ($tax[$entry->entry_id] ?? 0);
                    $detail[] = [$entry->entry_date, 'Penjualan langsung', $direct->cart?->code,
                        null, $net + $pbjt, $pbjt, $net, $entry->description];
                } else {
                    $pbjt = (int) ($tax[$entry->entry_id] ?? 0);
                    $detail[] = [$entry->entry_date, 'Koreksi lain', null, null,
                        $net + $pbjt, $pbjt, $net, $entry->description];
                }
            }
            // A reconciled zero-sales settlement can have no revenue journal. Keep its detail row.
            $zeroJournalSettlements = Settlement::query()->with(['cart:id,code', 'staff:id,name'])
                ->whereBetween('operating_date', [$this->from->toDateString(), $this->to->toDateString()])
                ->where('status', 'RECONCILED')->whereNotIn('id', array_keys($representedSettlements))
                ->orderBy('operating_date')->orderBy('id')->get();
            foreach ($zeroJournalSettlements as $settlement) {
                $detail[] = [$settlement->operating_date->toDateString(), 'Setoran', $settlement->cart?->code,
                    $settlement->staff?->name, 0, 0, 0, 'Tanpa jurnal pendapatan'];
            }
            usort($detail, fn (array $a, array $b): int => strcmp((string) $a[0], (string) $b[0]));
            $detail[] = [null, 'TOTAL', null, null, null, null, $total, null];

            $pending = Settlement::query()->with(['cart:id,code', 'staff:id,name'])
                ->whereBetween('operating_date', [$this->from->toDateString(), $this->to->toDateString()])
                ->where('status', '!=', 'RECONCILED')->orderBy('operating_date')->orderBy('id')->get()
                ->map(fn (Settlement $row): array => [$row->operating_date->toDateString(),
                    $row->cart?->code, $row->staff?->name, $row->status, $row->declared_total_minor])->all();

            return [
                new RevenueSheet('Pendapatan', ['Tanggal', 'Sumber', 'Coffee Bike', 'Rider',
                    'Bruto (Rp)', 'PBJT (Rp)', 'Neto (Rp)', 'Keterangan'], $detail),
                new RevenueSheet('Belum disetujui', ['Tanggal', 'Coffee Bike', 'Rider', 'Status',
                    'Bruto dilaporkan (Rp)'], $pending),
            ];
        });
    }
}
