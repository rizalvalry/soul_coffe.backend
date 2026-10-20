<?php

namespace App\Services\Reporting;

use App\Models\PayrollItem;
use App\Models\PayrollRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManagementReportService
{
    public function build(Carbon $from, Carbon $to): array
    {
        $this->validateRange($from, $to);

        // A single repeatable-read snapshot keeps totals and detail from observing
        // different commits while a report or export is assembled.
        return DB::transaction(fn (): array => $this->buildSnapshot($from, $to));
    }

    public function validateRange(Carbon $from, Carbon $to): void
    {
        if ($to->lt($from) || $from->diffInDays($to) > 365) {
            throw ValidationException::withMessages(['range' => 'Pilih rentang maksimal 366 hari, ya.']);
        }
    }

    private function buildSnapshot(Carbon $from, Carbon $to): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $period = $this->journalTotals($fromDate, $toDate);
        $closing = $this->journalTotals(null, $toDate);

        $revenue = 0;
        $cartRevenue = 0;
        $directRevenue = 0;
        $cogs = 0;
        $expenses = 0;
        $salary = 0;
        $profitRows = [];
        foreach ($period as $account) {
            $amount = in_array($account->type, ['REVENUE', 'LIABILITY', 'EQUITY'], true)
                ? (int) $account->credit_minor - (int) $account->debit_minor
                : (int) $account->debit_minor - (int) $account->credit_minor;
            if ($account->type === 'REVENUE') {
                $revenue += $amount;
                if ($account->code === '4-1100') {
                    $cartRevenue += $amount;
                } elseif ($account->code === '4-1200') {
                    $directRevenue += $amount;
                }
            } elseif ($account->type === 'COGS') {
                $cogs += $amount;
            } elseif ($account->type === 'EXPENSE') {
                $expenses += $amount;
                if (in_array($account->code, ['6-1200', '6-1300'], true)) {
                    $salary += $amount;
                }
            } else {
                continue;
            }
            $profitRows[] = [$account->code, $account->name, $this->accountTypeLabel($account->type), $amount];
        }
        $gross = $revenue - $cogs;
        $net = $gross - $expenses;
        $summary = [
            'cart_revenue_minor' => $cartRevenue,
            'direct_revenue_minor' => $directRevenue,
            'other_revenue_minor' => $revenue - $cartRevenue - $directRevenue,
            'revenue_minor' => $revenue,
            'cogs_minor' => $cogs,
            'gross_profit_minor' => $gross,
            'gross_margin' => $this->percent($gross, $revenue),
            'operating_expense_minor' => $expenses - $salary,
            'salary_expense_minor' => $salary,
            'net_profit_minor' => $net,
        ];

        $trialRows = [];
        $trialDebit = 0;
        $trialCredit = 0;
        foreach ($closing as $account) {
            $balance = (int) $account->debit_minor - (int) $account->credit_minor;
            if ($balance === 0) {
                continue;
            }
            $debit = max(0, $balance);
            $credit = max(0, -$balance);
            $trialRows[] = [$account->code, $account->name, $debit, $credit];
            $trialDebit += $debit;
            $trialCredit += $credit;
        }
        $trialRows[] = ['TOTAL', '', $trialDebit, $trialCredit];

        $cashAccounts = DB::table('accounts')
            ->whereIn('id', DB::table('cash_accounts')->select('account_id'))
            ->orWhere('code', '1-1200')
            ->orderBy('code')->get(['id', 'code', 'name']);
        $periodByAccount = $period->keyBy('id');
        $closingByAccount = $closing->keyBy('id');
        $cashRows = [];
        foreach ($cashAccounts as $account) {
            $movement = $periodByAccount->get($account->id);
            $end = $closingByAccount->get($account->id);
            $in = (int) ($movement?->debit_minor ?? 0);
            $out = (int) ($movement?->credit_minor ?? 0);
            $ending = (int) ($end?->debit_minor ?? 0) - (int) ($end?->credit_minor ?? 0);
            $cashRows[] = [$account->code, $account->name, $ending - $in + $out, $in, $out, $ending];
        }

        $settlements = DB::table('settlements as s')
            ->join('carts as c', 'c.id', '=', 's.cart_id')
            ->join('users as u', 'u.id', '=', 's.staff_id')
            ->whereBetween('s.operating_date', [$fromDate, $toDate])
            ->groupBy('s.cart_id', 's.staff_id', 'c.code', 'u.name')
            ->orderBy('c.code')->orderBy('u.name')
            ->select('c.code', 'u.name')
            ->selectRaw('SUM(COALESCE(s.expected_cash_minor, 0)) AS expected_cash')
            ->selectRaw('SUM(COALESCE(s.expected_qris_minor, 0) + COALESCE(s.expected_transfer_minor, 0)) AS expected_noncash')
            ->selectRaw('SUM(s.cash_minor) AS paid_cash, SUM(s.qris_minor + s.transfer_minor) AS paid_noncash')
            ->selectRaw('SUM(s.variance_minor) AS variance, SUM(CASE WHEN s.status = ? OR s.variance_minor <> 0 OR s.method_variance_flagged = 1 THEN 1 ELSE 0 END) AS flagged', ['VARIANCE_FLAGGED'])
            ->get();
        $settlementRows = $settlements->map(fn ($row): array => [
            $row->code, $row->name, (int) $row->expected_cash, (int) $row->expected_noncash,
            (int) $row->paid_cash, (int) $row->paid_noncash, (int) $row->variance, (int) $row->flagged,
        ])->all();

        // The journal owns financial totals. These grouped, non-void sale lines explain their
        // operational product/cart mix using the cost and price snapshots pinned at sale time.
        $closingStartedOn = (string) config('soul.closing_started_on', '2026-10-18');
        $cartProducts = DB::table('sale_lines as sl')
            ->join('sales as s', 's.id', '=', 'sl.sale_id')
            ->join('carts as c', 'c.id', '=', 's.cart_id')
            ->join('products as p', 'p.id', '=', 'sl.product_id')
            ->whereBetween('s.operating_date', [$fromDate, $toDate])
            ->where('s.operating_date', '<', $closingStartedOn)
            ->whereNull('s.voided_at')
            ->groupBy('s.cart_id', 'sl.product_id', 'c.code', 'p.name')
            ->select('s.cart_id', 'sl.product_id', 'c.code as cart_code', 'p.name as product_name')
            ->selectRaw('SUM(sl.subtotal_minor) AS revenue, SUM(sl.qty * COALESCE(sl.unit_cost_minor, 0)) AS cogs')
            ->get();
        $closingProducts = DB::table('rider_closing_lines as rl')
            ->join('rider_closings as rc', 'rc.id', '=', 'rl.rider_closing_id')
            ->join('carts as c', 'c.id', '=', 'rc.cart_id')
            ->join('products as p', 'p.id', '=', 'rl.product_id')
            ->whereBetween('rc.operating_date', [$fromDate, $toDate])
            ->where('rc.operating_date', '>=', $closingStartedOn)
            ->groupBy('rc.cart_id', 'rl.product_id', 'c.code', 'p.name')
            ->select('rc.cart_id', 'rl.product_id', 'c.code as cart_code', 'p.name as product_name')
            ->selectRaw('SUM(rl.subtotal_minor) AS revenue, SUM(rl.qty_sold * rl.unit_cost_minor) AS cogs')
            ->get();
        $directProducts = DB::table('direct_sale_lines as dl')
            ->join('direct_sales as d', 'd.id', '=', 'dl.direct_sale_id')
            ->join('carts as c', 'c.id', '=', 'd.cart_id')
            ->join('products as p', 'p.id', '=', 'dl.product_id')
            ->whereBetween('d.occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereNull('d.voided_at')
            ->groupBy('d.cart_id', 'dl.product_id', 'c.code', 'p.name')
            ->select('d.cart_id', 'dl.product_id', 'c.code as cart_code', 'p.name as product_name')
            ->selectRaw('SUM(dl.subtotal_minor) AS revenue, SUM(dl.qty * COALESCE(dl.unit_cost_minor, 0)) AS cogs')
            ->get();
        $carts = [];
        $products = [];
        foreach ($cartProducts->concat($closingProducts) as $row) {
            $cartKey = (int) $row->cart_id;
            $productKey = (int) $row->product_id;
            $carts[$cartKey] ??= ['name' => $row->cart_code, 'revenue' => 0, 'cogs' => 0];
            $products[$productKey] ??= ['name' => $row->product_name, 'revenue' => 0, 'cogs' => 0];
            $carts[$cartKey]['revenue'] += (int) $row->revenue;
            $carts[$cartKey]['cogs'] += (int) $row->cogs;
            $products[$productKey]['revenue'] += (int) $row->revenue;
            $products[$productKey]['cogs'] += (int) $row->cogs;
        }
        foreach ($directProducts as $row) {
            $key = (int) $row->product_id;
            $cartKey = (int) $row->cart_id;
            $carts[$cartKey] ??= ['name' => $row->cart_code, 'revenue' => 0, 'cogs' => 0];
            $products[$key] ??= ['name' => $row->product_name, 'revenue' => 0, 'cogs' => 0];
            $carts[$cartKey]['revenue'] += (int) $row->revenue;
            $carts[$cartKey]['cogs'] += (int) $row->cogs;
            $products[$key]['revenue'] += (int) $row->revenue;
            $products[$key]['cogs'] += (int) $row->cogs;
        }
        $cartRows = $this->marginRows($carts);
        $productRows = $this->marginRows($products);
        // Keep the operational mix visible while making pending sales, journal
        // corrections, and later voids explicit in each journal reconciliation.
        $this->appendMarginAdjustment($cartRows, $cartRevenue + $directRevenue, $cogs);
        $this->appendMarginAdjustment($productRows, $cartRevenue + $directRevenue, $cogs);

        $expenseRows = DB::table('cash_transactions as t')
            ->join('cash_categories as c', 'c.id', '=', 't.cash_category_id')
            ->join('accounts as a', 'a.id', '=', 'c.account_id')
            ->whereBetween('t.date', [$fromDate, $toDate])
            ->where('t.direction', 'OUT')->where('t.status', 'POSTED')->where('a.type', 'EXPENSE')
            ->groupBy('t.cash_category_id', 'c.name')->orderBy('c.name')
            ->select('c.name')->selectRaw('SUM(t.amount_minor) AS amount')
            ->get()->map(fn ($row): array => [$row->name, (int) $row->amount])->all();
        $expenseAdjustment = $summary['operating_expense_minor'] - array_sum(array_column($expenseRows, 1));
        if ($expenseAdjustment !== 0) {
            $expenseRows[] = ['Penyesuaian jurnal / biaya di luar kas keluar', $expenseAdjustment];
        }

        $payrollRows = [];
        $runs = PayrollRun::query()
            // Payroll accrual posts on month-end, while period_start is day one.
            ->whereBetween('period_start', [$from->copy()->startOfMonth()->toDateString(), $to->copy()->startOfMonth()->toDateString()])
            ->whereIn('status', ['APPROVED', 'PAID'])
            ->orderBy('period_start')->get();
        $payrollTotals = [];
        if ($runs->isNotEmpty()) {
            // The snapshots are encrypted, so their totals must be accumulated in PHP.
            // Chunks bound decrypted payroll data in memory without an item-level N+1.
            PayrollItem::query()->whereIn('run_id', $runs->pluck('id'))
                ->chunkById(500, function ($items) use (&$payrollTotals): void {
                    foreach ($items as $item) {
                        $snapshot = $item->snapshot;
                        $payrollTotals[$item->run_id] ??= ['count' => 0, 'gross' => 0, 'take_home' => 0];
                        $payrollTotals[$item->run_id]['count']++;
                        $payrollTotals[$item->run_id]['gross'] += (int) ($snapshot['gross_pay_minor'] ?? 0);
                        $payrollTotals[$item->run_id]['take_home'] += (int) ($snapshot['take_home_minor'] ?? 0);
                    }
                });
        }
        foreach ($runs as $run) {
            if (! $run->period_start->copy()->endOfMonth()->startOfDay()->betweenIncluded($from, $to)) {
                continue;
            }
            $totals = $payrollTotals[$run->id] ?? ['count' => 0, 'gross' => 0, 'take_home' => 0];
            $payrollRows[] = [$run->period_start->format('Y-m'), $run->status,
                $totals['count'], $totals['gross'], $totals['take_home']];
        }
        $salaryLine = $period->firstWhere('code', '6-1200');
        $salaryJournal = (int) ($salaryLine?->debit_minor ?? 0) - (int) ($salaryLine?->credit_minor ?? 0);
        $payrollAdjustment = $salaryJournal - array_sum(array_column($payrollRows, 3));
        if ($payrollAdjustment !== 0) {
            $payrollRows[] = ['Penyesuaian jurnal', 'Koreksi / run dibatalkan', 0, $payrollAdjustment, 0];
        }

        $expired = DB::table('refill_requests as r')->join('carts as c', 'c.id', '=', 'r.cart_id')
            ->whereBetween('r.operating_date', [$fromDate, $toDate])->where('r.status', 'EXPIRED')
            ->groupBy('r.cart_id', 'c.code')->select('r.cart_id', 'c.code')
            ->selectRaw('COUNT(*) AS qty')->get();
        $waste = DB::table('settlement_lines as l')->join('settlements as s', 's.id', '=', 'l.settlement_id')
            ->join('carts as c', 'c.id', '=', 's.cart_id')
            ->whereBetween('s.operating_date', [$fromDate, $toDate])
            ->groupBy('s.cart_id', 'c.code')->select('s.cart_id', 'c.code')
            ->selectRaw('SUM(l.qty_wasted) AS qty')->get();
        $operational = [];
        foreach ($expired as $row) {
            $operational[(int) $row->cart_id] = ['code' => $row->code, 'expired' => (int) $row->qty, 'waste' => 0];
        }
        foreach ($waste as $row) {
            $key = (int) $row->cart_id;
            $operational[$key] ??= ['code' => $row->code, 'expired' => 0, 'waste' => 0];
            $operational[$key]['waste'] = (int) $row->qty;
        }
        $operationalRows = array_values(array_map(fn ($row): array => array_values($row), $operational));

        return [
            'from' => $fromDate,
            'to' => $toDate,
            'summary' => $summary,
            'sections' => [
                'ringkasan' => $this->section('Ringkasan', ['Komponen', 'Nilai'], [
                    ['Pendapatan Coffee Bike', $cartRevenue], ['Penjualan langsung', $directRevenue],
                    ['Pendapatan lain-lain', $summary['other_revenue_minor']], ['Total pendapatan', $revenue],
                    ['HPP', $cogs], ['Laba kotor', $gross], ['Margin kotor', $summary['gross_margin']],
                    ['Biaya operasional', $summary['operating_expense_minor']], ['Beban gaji dan BPJS', $salary],
                    ['Laba bersih', $net],
                ], [1]),
                'setoran' => $this->section('Rekonsiliasi setoran per Coffee Bike',
                    ['Coffee Bike', 'Rider', 'Ekspektasi tunai', 'Ekspektasi non-tunai', 'Disetor tunai', 'Disetor non-tunai', 'Selisih', 'Setoran berselisih'], $settlementRows, [2, 3, 4, 5, 6]),
                'margin_gerobak' => $this->section('Margin per Coffee Bike', ['Coffee Bike', 'Penjualan', 'HPP snapshot', 'Laba kotor', 'Margin'], $cartRows, [1, 2, 3]),
                'margin_produk' => $this->section('Margin per produk', ['Produk', 'Penjualan', 'HPP snapshot', 'Laba kotor', 'Margin'], $productRows, [1, 2, 3]),
                'biaya' => $this->section('Biaya per kategori kas keluar', ['Kategori', 'Nominal'], $expenseRows, [1]),
                'arus_kas' => $this->section('Arus kas per akun', ['Kode', 'Akun', 'Saldo awal', 'Masuk', 'Keluar', 'Saldo akhir'], $cashRows, [2, 3, 4, 5]),
                'payroll' => $this->section('Rekap payroll', ['Periode', 'Status', 'Karyawan', 'Bruto', 'Take-home pay'], $payrollRows, [3, 4]),
                'laba_rugi' => $this->section('Laba rugi dari jurnal', ['Kode', 'Akun', 'Jenis', 'Nominal'], [
                    ...$profitRows, ['TOTAL', 'Laba bersih', '', $net],
                ], [3]),
                'neraca_saldo' => $this->section('Neraca saldo dari jurnal sampai '.$toDate,
                    ['Kode', 'Akun', 'Debit', 'Kredit'], $trialRows, [2, 3]),
                'refill_waste' => $this->section('Refill expired dan waste', ['Coffee Bike', 'Refill expired', 'Cup waste'], $operationalRows),
            ],
        ];
    }

    private function journalTotals(?string $from, string $to): Collection
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->when($from, fn ($query) => $query->where('j.entry_date', '>=', $from))
            ->where('j.entry_date', '<=', $to)
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderBy('a.code')
            ->select('a.id', 'a.code', 'a.name', 'a.type')
            ->selectRaw('SUM(l.debit_minor) AS debit_minor, SUM(l.credit_minor) AS credit_minor')
            ->get();
    }

    private function marginRows(array $groups): array
    {
        uasort($groups, fn ($a, $b): int => strcmp($a['name'], $b['name']));
        $rows = [];
        foreach ($groups as $group) {
            $profit = $group['revenue'] - $group['cogs'];
            $rows[] = [$group['name'], $group['revenue'], $group['cogs'], $profit,
                $this->percent($profit, $group['revenue'])];
        }

        return $rows;
    }

    private function appendMarginAdjustment(array &$rows, int $journalRevenue, int $journalCogs): void
    {
        $revenueDelta = $journalRevenue - array_sum(array_column($rows, 1));
        $cogsDelta = $journalCogs - array_sum(array_column($rows, 2));
        if ($revenueDelta === 0 && $cogsDelta === 0) {
            return;
        }
        $profitDelta = $revenueDelta - $cogsDelta;
        $rows[] = ['Penyesuaian jurnal / penjualan belum disetor', $revenueDelta, $cogsDelta,
            $profitDelta, $this->percent($profitDelta, $revenueDelta)];
    }

    private function percent(int $numerator, int $denominator): string
    {
        if ($denominator === 0) {
            return '0,00%';
        }
        $hundredths = intdiv(abs($numerator) * 10000, abs($denominator));

        return (($numerator < 0) !== ($denominator < 0) ? '-' : '').intdiv($hundredths, 100).','.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT).'%';
    }

    private function accountTypeLabel(string $type): string
    {
        return match ($type) {
            'REVENUE' => 'Pendapatan',
            'COGS' => 'HPP',
            default => 'Beban',
        };
    }

    private function section(string $title, array $headings, array $rows, array $money = []): array
    {
        return compact('title', 'headings', 'rows', 'money');
    }
}
