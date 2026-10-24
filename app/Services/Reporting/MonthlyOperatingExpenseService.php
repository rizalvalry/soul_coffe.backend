<?php

namespace App\Services\Reporting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MonthlyOperatingExpenseService
{
    public function matrix(string $month, ?int $employeeId = null): array
    {
        Validator::make(['month' => $month, 'employee_id' => $employeeId], [
            'month' => ['required', 'date_format:Y-m'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
        ])->validate();
        $start = Carbon::createFromFormat('!Y-m', $month)->startOfMonth();
        $days = (int) $start->daysInMonth;
        $endDate = $start->copy()->endOfMonth()->toDateString();
        $records = DB::table('cash_transactions as t')
            ->join('cash_categories as c', 'c.id', '=', 't.cash_category_id')
            ->join('accounts as a', 'a.id', '=', 'c.account_id')
            ->join('journal_entries as original', function ($join): void {
                $join->on('original.source_id', '=', 't.id')->where('original.source_type', 'cash_transaction')
                    ->where('original.purpose', 'CASH_TX');
            })
            ->leftJoin('journal_entries as reversal', function ($join): void {
                $join->on('reversal.reverses_entry_id', '=', 'original.id')
                    ->where('reversal.source_type', 'journal_reversal');
            })
            ->where('t.is_daily_expense', true)->where('t.direction', 'OUT')->where('a.type', 'EXPENSE')
            ->where(function ($query) use ($start, $endDate): void {
                $query->whereBetween('original.entry_date', [$start->toDateString(), $endDate])
                    ->orWhereBetween('reversal.entry_date', [$start->toDateString(), $endDate]);
            })
            ->when($employeeId !== null, fn ($query) => $query->where('t.employee_id', $employeeId))
            ->orderBy('c.name')->orderBy('original.entry_date')
            ->select('t.cash_category_id', 'c.name', 't.quantity', 't.amount_minor',
                'original.entry_date as original_date', 'reversal.entry_date as reversal_date')->get();

        $rows = [];
        $totals = ['quantity' => 0, 'amount_minor' => 0];
        foreach ($records as $record) {
            $id = (int) $record->cash_category_id;
            $rows[$id] ??= ['category' => $record->name, 'cells' => [], 'quantity' => 0, 'amount_minor' => 0];
            foreach ([[$record->original_date, 1], [$record->reversal_date, -1]] as [$date, $sign]) {
                if ($date === null || $date < $start->toDateString() || $date > $endDate) {
                    continue;
                }
                $day = (int) Carbon::parse($date)->day;
                $rows[$id]['cells'][$day] ??= ['quantity' => 0, 'amount_minor' => 0];
                $quantity = $sign * (int) $record->quantity;
                $amount = $sign * (int) $record->amount_minor;
                $rows[$id]['cells'][$day]['quantity'] += $quantity;
                $rows[$id]['cells'][$day]['amount_minor'] += $amount;
                $rows[$id]['quantity'] += $quantity;
                $rows[$id]['amount_minor'] += $amount;
                $totals['quantity'] += $quantity;
                $totals['amount_minor'] += $amount;
            }
        }

        // A shared expense account can also contain non-daily cash or manual journals.
        // Show that difference separately so the unfiltered month agrees with the full ledger.
        if ($employeeId === null) {
            $accountIds = DB::table('cash_categories')->where('is_daily', true)->pluck('account_id')
                ->merge(DB::table('cash_transactions as t')->join('cash_categories as c', 'c.id', '=', 't.cash_category_id')
                    ->where('t.is_daily_expense', true)->pluck('c.account_id'))->unique()->values()->all();
            $ledgerDays = DB::table('journal_lines as line')
                ->join('journal_entries as entry', 'entry.id', '=', 'line.entry_id')
                ->whereIn('line.account_id', $accountIds)
                ->whereBetween('entry.entry_date', [$start->toDateString(), $endDate])
                ->groupBy('entry.entry_date')->select('entry.entry_date')
                ->selectRaw('SUM(CAST(line.debit_minor AS SIGNED) - CAST(line.credit_minor AS SIGNED)) AS amount_minor')
                ->get();
            $adjustments = [];
            foreach ($ledgerDays as $ledgerDay) {
                $day = (int) Carbon::parse($ledgerDay->entry_date)->day;
                $reported = array_sum(array_map(fn (array $row): int => $row['cells'][$day]['amount_minor'] ?? 0, $rows));
                $difference = (int) $ledgerDay->amount_minor - $reported;
                if ($difference !== 0) {
                    $adjustments[$day] = ['quantity' => 0, 'amount_minor' => $difference];
                    $totals['amount_minor'] += $difference;
                }
            }
            if ($adjustments !== []) {
                $rows['adjustment'] = [
                    'category' => 'Biaya lain / penyesuaian akun terkait', 'cells' => $adjustments,
                    'quantity' => 0, 'amount_minor' => array_sum(array_column($adjustments, 'amount_minor')),
                ];
            }
        }

        return ['month' => $month, 'days' => range(1, $days), 'rows' => array_values($rows), 'totals' => $totals];
    }
}
