<?php

namespace App\Services\Reporting;

use App\Services\ClearingService;
use App\Services\FinancialReportSettingsService;
use App\Services\RiderCommissionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdvancedFinancialReportService
{
    public function __construct(
        private readonly RiderCommissionService $commissions,
        private readonly ClearingService $clearing,
    ) {}

    public function sections(Carbon $from, Carbon $to, Collection $closing, array $summary): array
    {
        return [
            'neraca' => $this->balanceSheet($to, $closing),
            'arus_kas_langsung' => $this->cashFlow($from, $to),
            'laba_titik' => $this->pointProfit($from, $to, $summary),
            'anggaran' => $this->budgets($from, $to),
            'kpi_keuangan' => $this->kpis($from, $to, $summary),
        ];
    }

    private function balanceSheet(Carbon $to, Collection $closing): array
    {
        $rows = [];
        $assets = 0;
        $liabilities = 0;
        $equity = 0;
        $profit = 0;
        foreach ($closing as $account) {
            $debitBalance = (int) $account->debit_minor - (int) $account->credit_minor;
            if ($debitBalance === 0) {
                continue;
            }
            switch ($account->type) {
                case 'ASSET':
                    $group = in_array($account->code, ['1-1800', '1-1900'], true) ? 'Aset tetap' : 'Aset lancar';
                    $amount = $debitBalance;
                    $assets += $amount;
                    break;
                case 'LIABILITY':
                    $group = 'Liabilitas';
                    $amount = -$debitBalance;
                    $liabilities += $amount;
                    break;
                case 'EQUITY':
                    $group = 'Ekuitas';
                    $amount = -$debitBalance;
                    $equity += $amount;
                    break;
                case 'REVENUE':
                    $profit -= $debitBalance;

                    continue 2;
                case 'COGS':
                case 'EXPENSE':
                    $profit -= $debitBalance;

                    continue 2;
                default:
                    continue 2;
            }
            $rows[] = [$group, $account->code, $account->name, $amount];
        }
        $rows[] = ['Ekuitas', '', 'Laba berjalan sejak saldo awal', $profit];
        $rows[] = ['TOTAL', '', 'Total aset', $assets];
        $rows[] = ['TOTAL', '', 'Liabilitas + ekuitas + laba berjalan', $liabilities + $equity + $profit];
        $rows[] = ['CEK', '', 'Selisih neraca', $assets - $liabilities - $equity - $profit];

        return $this->section('Neraca per '.$to->toDateString(), ['Kelompok', 'Kode', 'Akun', 'Nilai'], $rows, [3]);
    }

    private function cashFlow(Carbon $from, Carbon $to): array
    {
        $cashIds = DB::table('accounts')->whereIn('code', ['1-1100', '1-1200', '1-1300'])
            ->orWhereIn('id', DB::table('cash_accounts')->select('account_id'))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $begin = (int) DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
            ->whereIn('l.account_id', $cashIds)->where('j.entry_date', '<', $from->toDateString())
            ->selectRaw('COALESCE(SUM(CAST(l.debit_minor AS SIGNED) - CAST(l.credit_minor AS SIGNED)), 0) AS balance')->value('balance');
        $lines = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->whereBetween('j.entry_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('j.id', DB::table('journal_lines')->whereIn('account_id', $cashIds)->select('entry_id'))
            ->orderBy('j.id')->orderBy('l.id')
            ->get(['j.id as entry_id', 'l.account_id', 'l.debit_minor', 'l.credit_minor', 'a.code', 'a.name', 'a.type']);
        $mappings = DB::table('cash_flow_mappings')->pluck('category', 'account_id');
        $net = ['OPERASI' => 0, 'INVESTASI' => 0, 'PENDANAAN' => 0, 'BELUM_DIPETAKAN' => 0];
        $flows = [];
        foreach ($lines->groupBy('entry_id') as $entryLines) {
            $cash = 0;
            $counter = [];
            foreach ($entryLines as $line) {
                $movement = (int) $line->debit_minor - (int) $line->credit_minor;
                if (in_array((int) $line->account_id, $cashIds, true)) {
                    $cash += $movement;
                } else {
                    $counter[] = [$line, $movement];
                }
            }
            if ($cash === 0) {
                continue; // Transfers between cash/bank accounts are not cash flows.
            }
            $direction = $cash > 0 ? -1 : 1;
            $candidates = array_values(array_filter($counter, fn (array $row): bool => $row[1] * $direction > 0));
            $weight = array_sum(array_map(fn (array $row): int => abs($row[1]), $candidates));
            if ($weight === 0) {
                $net['OPERASI'] += $cash;
                $flows['OPERASI']['Tidak terpetakan'] ??= ['in' => 0, 'out' => 0];
                $flows['OPERASI']['Tidak terpetakan'][$cash > 0 ? 'in' : 'out'] += abs($cash);

                continue;
            }
            if (count($candidates) > 1 && $weight !== abs($cash)) {
                $net['BELUM_DIPETAKAN'] += $cash;
                $flows['BELUM_DIPETAKAN']['Jurnal campuran'] ??= ['in' => 0, 'out' => 0];
                $flows['BELUM_DIPETAKAN']['Jurnal campuran'][$cash > 0 ? 'in' : 'out'] += abs($cash);

                continue;
            }
            $remaining = abs($cash);
            foreach ($candidates as $index => [$line, $movement]) {
                $piece = $index === count($candidates) - 1
                    ? $remaining : min($remaining, abs($movement));
                $remaining -= $piece;
                $category = $mappings[$line->account_id] ?? $this->defaultCashCategory($line->code, $line->type);
                $net[$category] += $cash > 0 ? $piece : -$piece;
                $account = $line->code.' '.$line->name;
                $flows[$category][$account] ??= ['in' => 0, 'out' => 0];
                $flows[$category][$account][$cash > 0 ? 'in' : 'out'] += $piece;
            }
        }
        $change = array_sum($net);
        $end = $begin + $change;
        $rows = [['Kas dan bank awal', '', 0, 0, $begin]];
        $totalIn = 0;
        $totalOut = 0;
        foreach (['OPERASI' => 'Operasi', 'INVESTASI' => 'Investasi', 'PENDANAAN' => 'Pendanaan',
            'BELUM_DIPETAKAN' => 'Perlu dicek'] as $category => $label) {
            $categoryIn = 0;
            $categoryOut = 0;
            foreach ($flows[$category] ?? [] as $account => $amounts) {
                $rows[] = [$label, $account, $amounts['in'], $amounts['out'], $amounts['in'] - $amounts['out']];
                $categoryIn += $amounts['in'];
                $categoryOut += $amounts['out'];
            }
            $rows[] = [$label.' total', '', $categoryIn, $categoryOut, $net[$category]];
            $totalIn += $categoryIn;
            $totalOut += $categoryOut;
        }
        $rows[] = ['Arus bersih', '', $totalIn, $totalOut, $change];
        $rows[] = ['Kas dan bank akhir', '', 0, 0, $end];

        return $this->section('Arus kas metode langsung', ['Kelompok', 'Akun lawan', 'Masuk', 'Keluar', 'Arus bersih'], $rows, [2, 3, 4]);
    }

    private function defaultCashCategory(string $code, string $type): string
    {
        if (in_array($code, ['1-1800', '1-1900'], true)) {
            return 'INVESTASI';
        }

        return $type === 'EQUITY' ? 'PENDANAAN' : 'OPERASI';
    }

    private function pointProfit(Carbon $from, Carbon $to, array $summary): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $startClosing = (string) config('soul.closing_started_on', '2026-10-18');
        $assignments = DB::table('staff_assignments')->whereBetween('operating_date', [$fromDate, $toDate])
            ->where('source', 'hand_to_cart')
            ->orderBy('id')->get()->keyBy(fn ($row): string => $row->user_id.'|'.$row->cart_id.'|'.$row->operating_date);
        $assignmentByCartDay = $assignments->keyBy(fn ($row): string => $row->cart_id.'|'.$row->operating_date);
        $locations = DB::table('locations')->pluck('name', 'id');
        $carts = DB::table('carts')->pluck('code', 'id');
        $riders = DB::table('users')->pluck('name', 'id');
        $groups = [];
        $add = function (?int $cartId, ?int $riderId, string $date, string $field, int $amount) use (&$groups, $assignments, $locations, $carts, $riders): void {
            $assignment = $riderId && $cartId ? $assignments->get($riderId.'|'.$cartId.'|'.$date) : null;
            $locationId = $assignment?->location_id;
            $key = ($locationId ?? 0).'|'.($cartId ?? 0).'|'.($riderId ?? 0);
            $groups[$key] ??= [
                'location' => $locationId ? ($locations[$locationId] ?? 'Tidak berlokasi') : 'Tidak berlokasi',
                'cart' => $cartId ? ($carts[$cartId] ?? '-') : '-',
                'rider' => $riderId ? ($riders[$riderId] ?? '-') : '-',
                'revenue' => 0, 'cogs' => 0, 'commission' => 0, 'allowance' => 0, 'direct' => 0,
            ];
            $groups[$key][$field] += $amount;
        };
        $closings = DB::table('rider_closings')->whereBetween('operating_date', [$fromDate, $toDate])
            ->where('operating_date', '>=', $startClosing)->get();
        $cartExpr = 'COALESCE(l.cart_id, sale.cart_id, stock.location_id, reversed_sale.cart_id, reversed_stock.location_id)';
        $correctionRiderExpr = 'COALESCE(corrected.rider_id, reversed_corrected.rider_id)';
        $correctionDateExpr = 'COALESCE(corrected.operating_date, reversed_corrected.operating_date)';
        $trade = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('direct_sales as sale', fn ($join) => $join->on('sale.id', '=', 'j.source_id')->where('j.source_type', 'direct_sale'))
            ->leftJoin('stock_ledger as stock', fn ($join) => $join->on('stock.id', '=', 'j.source_id')
                ->where('j.source_type', 'stock_ledger')->where('stock.location_type', 'cart'))
            ->leftJoin('journal_entries as original', 'original.id', '=', 'j.reverses_entry_id')
            ->leftJoin('direct_sales as reversed_sale', fn ($join) => $join->on('reversed_sale.id', '=', 'original.source_id')
                ->where('original.source_type', 'direct_sale'))
            ->leftJoin('stock_ledger as reversed_stock', fn ($join) => $join->on('reversed_stock.id', '=', 'original.source_id')
                ->where('original.source_type', 'stock_ledger')->where('reversed_stock.location_type', 'cart'))
            ->leftJoin('rider_closing_revisions as revision', fn ($join) => $join->on('revision.id', '=', 'j.source_id')
                ->where('j.source_type', 'rider_closing_revision'))
            ->leftJoin('rider_closings as corrected', 'corrected.id', '=', 'revision.rider_closing_id')
            ->leftJoin('rider_closing_revisions as reversed_revision', fn ($join) => $join->on('reversed_revision.id', '=', 'original.source_id')
                ->where('original.source_type', 'rider_closing_revision'))
            ->leftJoin('rider_closings as reversed_corrected', 'reversed_corrected.id', '=', 'reversed_revision.rider_closing_id')
            ->whereBetween('j.entry_date', [$fromDate, $toDate])->whereIn('a.type', ['REVENUE', 'COGS'])
            ->groupByRaw($cartExpr.', j.entry_date, a.type, '.$correctionRiderExpr.', '.$correctionDateExpr)
            ->selectRaw($cartExpr.' AS cart_id, j.entry_date, a.type, '.$correctionRiderExpr.' AS corrected_rider_id, '
                .$correctionDateExpr.' AS corrected_date, SUM(CAST(l.debit_minor AS SIGNED) - CAST(l.credit_minor AS SIGNED)) AS amount')
            ->get();
        $historicalDates = $trade->pluck('corrected_date')->filter()->unique()->all();
        if ($historicalDates !== []) {
            foreach (DB::table('staff_assignments')->whereIn('operating_date', $historicalDates)
                ->where('source', 'hand_to_cart')->orderBy('id')->get() as $assignment) {
                $assignments->put($assignment->user_id.'|'.$assignment->cart_id.'|'.$assignment->operating_date, $assignment);
            }
        }
        foreach ($trade as $line) {
            $cartId = $line->cart_id ? (int) $line->cart_id : null;
            $riderId = $line->corrected_rider_id ?? ($cartId ? $assignmentByCartDay->get($cartId.'|'.$line->entry_date)?->user_id : null);
            $add($cartId, $riderId ? (int) $riderId : null, $line->corrected_date ?? $line->entry_date,
                $line->type === 'REVENUE' ? 'revenue' : 'cogs',
                $line->type === 'REVENUE' ? -(int) $line->amount : (int) $line->amount);
        }
        $closingByRiderDay = $closings->groupBy(fn ($row): string => $row->rider_id.'|'.$row->operating_date);
        $commissionDays = $this->commissions->summaries($closings->pluck('rider_id')->map(fn ($id): int => (int) $id)->all(), $fromDate, $toDate);
        foreach ($closingByRiderDay as $key => $dayClosings) {
            [$riderId, $date] = explode('|', $key);
            $commission = (int) ($commissionDays[(int) $riderId][$date]['commission_minor'] ?? 0);
            $weight = max(1, (int) $dayClosings->sum('total_minor'));
            $remaining = $commission;
            foreach ($dayClosings->values() as $index => $closing) {
                $piece = $index === $dayClosings->count() - 1 ? $remaining : intdiv($commission * (int) $closing->total_minor, $weight);
                $remaining -= $piece;
                $add((int) $closing->cart_id, (int) $riderId, $date, 'commission', $piece);
            }
        }
        foreach (['daily_allowance', 'journal_reversal'] as $source) {
            $allowances = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
                ->join('accounts as expense', 'expense.id', '=', 'l.account_id')
                ->leftJoin('journal_entries as original', 'original.id', '=', 'j.reverses_entry_id')
                ->join('daily_cart_allowances as allowance', 'allowance.id', '=', DB::raw($source === 'daily_allowance' ? 'j.source_id' : 'original.source_id'))
                ->where('expense.code', '6-1700')->where('j.source_type', $source)
                ->whereBetween('j.entry_date', [$fromDate, $toDate])
                ->when($source === 'journal_reversal', fn ($query) => $query->where('original.source_type', 'daily_allowance'))
                ->groupBy('allowance.cart_id', 'j.entry_date')
                ->select('allowance.cart_id', 'j.entry_date')
                ->selectRaw('SUM(CAST(l.debit_minor AS SIGNED) - CAST(l.credit_minor AS SIGNED)) AS amount')->get();
            foreach ($allowances as $allowance) {
                $riderId = $assignmentByCartDay->get($allowance->cart_id.'|'.$allowance->entry_date)?->user_id;
                $add((int) $allowance->cart_id, $riderId ? (int) $riderId : null,
                    $allowance->entry_date, 'allowance', (int) $allowance->amount);
            }
        }
        $directCosts = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('journal_entries as original', 'original.id', '=', 'j.reverses_entry_id')
            ->leftJoin('rider_closing_revisions as revision', fn ($join) => $join->on('revision.id', '=', 'j.source_id')
                ->where('j.source_type', 'rider_closing_revision'))
            ->leftJoin('rider_closings as corrected', 'corrected.id', '=', 'revision.rider_closing_id')
            ->leftJoin('rider_closing_revisions as reversed_revision', fn ($join) => $join->on('reversed_revision.id', '=', 'original.source_id')
                ->where('original.source_type', 'rider_closing_revision'))
            ->leftJoin('rider_closings as reversed_corrected', 'reversed_corrected.id', '=', 'reversed_revision.rider_closing_id')
            ->whereBetween('j.entry_date', [$fromDate, $toDate])->whereNotNull('l.cart_id')
            ->where('a.type', 'EXPENSE')
            ->groupByRaw('l.cart_id, j.entry_date, '.$correctionRiderExpr.', '.$correctionDateExpr)
            ->selectRaw('l.cart_id, j.entry_date, '.$correctionRiderExpr.' AS corrected_rider_id, '
                .$correctionDateExpr.' AS corrected_date, SUM(CAST(l.debit_minor AS SIGNED) - CAST(l.credit_minor AS SIGNED)) AS amount')->get();
        $costHistoricalDates = $directCosts->pluck('corrected_date')->filter()->unique()->all();
        if ($costHistoricalDates !== []) {
            foreach (DB::table('staff_assignments')->whereIn('operating_date', $costHistoricalDates)
                ->where('source', 'hand_to_cart')->orderBy('id')->get() as $assignment) {
                $assignments->put($assignment->user_id.'|'.$assignment->cart_id.'|'.$assignment->operating_date, $assignment);
            }
        }
        foreach ($directCosts as $cost) {
            $riderId = $cost->corrected_rider_id ?? $assignmentByCartDay->get($cost->cart_id.'|'.$cost->entry_date)?->user_id;
            $add((int) $cost->cart_id, $riderId ? (int) $riderId : null,
                $cost->corrected_date ?? $cost->entry_date, 'direct', (int) $cost->amount);
        }
        $revenue = array_sum(array_column($groups, 'revenue'));
        $cogs = array_sum(array_column($groups, 'cogs'));
        if ($revenue !== $summary['revenue_minor'] || $cogs !== $summary['cogs_minor']) {
            $add(null, null, $fromDate, 'revenue', $summary['revenue_minor'] - $revenue);
            $add(null, null, $fromDate, 'cogs', $summary['cogs_minor'] - $cogs);
        }
        usort($groups, fn (array $a, array $b): int => [$a['location'], $a['cart'], $a['rider']] <=> [$b['location'], $b['cart'], $b['rider']]);
        $rows = [];
        $totals = ['revenue' => 0, 'cogs' => 0, 'commission' => 0, 'allowance' => 0, 'direct' => 0];
        foreach ($groups as $group) {
            foreach ($totals as $field => $_) {
                $totals[$field] += $group[$field];
            }
            $profit = $group['revenue'] - $group['cogs'] - $group['commission'] - $group['allowance'] - $group['direct'];
            $rows[] = [$group['location'], $group['cart'], $group['rider'], $group['revenue'], $group['cogs'],
                $group['commission'], $group['allowance'], $group['direct'], $profit];
        }
        $rows[] = ['TOTAL', '', '', ...array_values($totals),
            $totals['revenue'] - $totals['cogs'] - $totals['commission'] - $totals['allowance'] - $totals['direct']];

        return $this->section('Laba per titik jualan dan Rider',
            ['Titik', 'Coffee Bike', 'Rider', 'Pendapatan', 'HPP', 'Komisi', 'Uang harian', 'Beban langsung', 'Laba'],
            $rows, [3, 4, 5, 6, 7, 8]);
    }

    private function budgets(Carbon $from, Carbon $to): array
    {
        $rows = [];
        $budgets = DB::table('financial_budgets as b')->join('accounts as a', 'a.id', '=', 'b.account_id')
            ->leftJoin('carts as c', 'c.id', '=', 'b.cart_id')
            ->whereBetween('b.month', [$from->copy()->startOfMonth()->toDateString(), $to->copy()->startOfMonth()->toDateString()])
            ->orderBy('b.month')->orderBy('a.code')->orderBy('c.code')
            ->get(['b.month', 'b.account_id', 'b.cart_id', 'b.amount_minor', 'a.code', 'a.name', 'a.type', 'c.code as cart_code']);
        $actualGlobal = [];
        $actualCart = [];
        if ($budgets->isNotEmpty()) {
            $cartExpr = 'COALESCE(l.cart_id, sale.cart_id, reversed_sale.cart_id)';
            $actualRows = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.entry_id')
                ->leftJoin('direct_sales as sale', fn ($join) => $join->on('sale.id', '=', 'j.source_id')->where('j.source_type', 'direct_sale'))
                ->leftJoin('journal_entries as original', 'original.id', '=', 'j.reverses_entry_id')
                ->leftJoin('direct_sales as reversed_sale', fn ($join) => $join->on('reversed_sale.id', '=', 'original.source_id')
                    ->where('original.source_type', 'direct_sale'))
                ->whereBetween('j.entry_date', [$from->copy()->startOfMonth()->toDateString(), $to->copy()->endOfMonth()->toDateString()])
                ->whereIn('l.account_id', $budgets->pluck('account_id')->unique())
                ->groupByRaw("DATE_FORMAT(j.entry_date, '%Y-%m'), l.account_id, ".$cartExpr)
                ->selectRaw("DATE_FORMAT(j.entry_date, '%Y-%m') AS month, l.account_id, ".$cartExpr.' AS cart_id, SUM(CAST(l.debit_minor AS SIGNED) - CAST(l.credit_minor AS SIGNED)) AS amount')
                ->get();
            foreach ($actualRows as $actual) {
                $key = $actual->month.'|'.$actual->account_id;
                $actualGlobal[$key] = ($actualGlobal[$key] ?? 0) + (int) $actual->amount;
                if ($actual->cart_id) {
                    $cartKey = $key.'|'.$actual->cart_id;
                    $actualCart[$cartKey] = ($actualCart[$cartKey] ?? 0) + (int) $actual->amount;
                }
            }
        }
        foreach ($budgets as $budget) {
            $key = substr($budget->month, 0, 7).'|'.$budget->account_id;
            $actual = $budget->cart_id ? ($actualCart[$key.'|'.$budget->cart_id] ?? 0) : ($actualGlobal[$key] ?? 0);
            $actual *= $budget->type === 'REVENUE' ? -1 : 1;
            $variance = $actual - (int) $budget->amount_minor;
            $percent = (int) $budget->amount_minor === 0 ? '—' : $this->percentage($variance, (int) $budget->amount_minor);
            $rows[] = [substr($budget->month, 0, 7), $budget->code.' · '.$budget->name,
                $budget->cart_code ?? 'Semua Coffee Bike', (int) $budget->amount_minor, $actual, $variance, $percent];
        }

        return $this->section('Anggaran vs realisasi',
            ['Bulan', 'Akun', 'Coffee Bike', 'Anggaran', 'Realisasi', 'Selisih', 'Selisih %'], $rows, [3, 4, 5]);
    }

    private function kpis(Carbon $from, Carbon $to, array $summary): array
    {
        $fromDate = $from->toDateString();
        $toDate = $to->toDateString();
        $targets = DB::table('financial_kpi_targets')->pluck('target_value', 'metric');
        $ratio = fn (int $numerator, int $denominator): int => $denominator === 0 ? 0 : intdiv($numerator * 10000, $denominator);
        $hppBps = $ratio($summary['cogs_minor'], $summary['revenue_minor']);
        $settlements = DB::table('settlements')->whereBetween('operating_date', [$fromDate, $toDate])->get();
        $byRiderMonth = [];
        $deadline = (int) ($targets['deposit_deadline_days'] ?? 0);
        $onTime = 0;
        foreach ($settlements as $settlement) {
            $key = $settlement->staff_id.'|'.substr($settlement->operating_date, 0, 7);
            $byRiderMonth[$key] = ($byRiderMonth[$key] ?? 0) + (int) $settlement->variance_minor;
            if ($settlement->reconciled_at
                && Carbon::parse($settlement->reconciled_at)->toDateString()
                    <= Carbon::parse($settlement->operating_date)->addDays($deadline)->toDateString()) {
                $onTime++;
            }
        }
        $maxVariance = $byRiderMonth === [] ? 0 : max(array_map('abs', array_values($byRiderMonth)));
        $aging = $this->clearing->aging($toDate);
        $maxAge = $aging['rows'] === [] ? 0 : max(array_column($aging['rows'], 'age_days'));
        $started = (string) config('soul.closing_started_on', '2026-10-18');
        $waste = DB::table('rider_closing_lines as l')->join('rider_closings as c', 'c.id', '=', 'l.rider_closing_id')
            ->whereBetween('c.operating_date', [$fromDate, $toDate])->where('c.operating_date', '>=', $started)
            ->selectRaw('COALESCE(SUM(l.qty_wasted), 0) AS wasted, COALESCE(SUM(l.qty_sold), 0) AS sold')->first();
        $legacyWaste = DB::table('settlement_lines as l')->join('settlements as s', 's.id', '=', 'l.settlement_id')
            ->whereBetween('s.operating_date', [$fromDate, $toDate])->where('s.operating_date', '<', $started)
            ->selectRaw('COALESCE(SUM(l.qty_wasted), 0) AS wasted, COALESCE(SUM(l.qty_sold), 0) AS sold')->first();
        $wasted = (int) $waste->wasted + (int) $legacyWaste->wasted;
        $sold = (int) $waste->sold + (int) $legacyWaste->sold;
        $wasteBps = $ratio($wasted, $wasted + $sold);
        $onTimeBps = $ratio($onTime, $settlements->count());
        $closeDays = null;
        $periods = DB::table('accounting_periods')->where('status', 'CLOSED')
            ->whereBetween('year', [(int) $from->year, (int) $to->year])->get();
        foreach ($periods as $period) {
            $month = sprintf('%04d-%02d', $period->year, $period->month);
            if ($month < $from->format('Y-m') || $month > $to->format('Y-m') || ! $period->closed_at) {
                continue;
            }
            $days = max(0, (int) Carbon::parse($month.'-01')->endOfMonth()->startOfDay()
                ->diffInDays(Carbon::parse($period->closed_at)->startOfDay()));
            $closeDays = max($closeDays ?? 0, $days);
        }
        $actual = [
            'hpp_ratio_bps' => $hppBps,
            'settlement_variance_minor' => $maxVariance,
            'qris_age_days' => $maxAge,
            'waste_ratio_bps' => $wasteBps,
            'deposit_deadline_days' => $deadline,
            'on_time_deposits_bps' => $onTimeBps,
            'period_close_days' => $closeDays,
        ];
        $rows = [];
        foreach (FinancialReportSettingsService::KPI_METRICS as $key => $label) {
            $value = $actual[$key];
            $target = $targets[$key] ?? null;
            $rows[] = [$label, $value === null ? 'Belum ditutup' : $this->formatKpi($key, $value),
                $target === null ? 'Belum diatur' : $this->formatKpi($key, (int) $target),
                $value === null || $target === null ? '—' : ($key === 'on_time_deposits_bps'
                    ? ($value >= $target ? 'Sesuai' : 'Perlu dicek')
                    : ($value <= $target ? 'Sesuai' : 'Perlu dicek'))];
        }
        $riderNames = DB::table('users')->whereIn('id', array_map(fn (string $key): int => (int) explode('|', $key)[0], array_keys($byRiderMonth)))
            ->pluck('name', 'id');
        foreach ($byRiderMonth as $key => $variance) {
            [$riderId, $month] = explode('|', $key);
            $name = $riderNames[$riderId] ?? '-';
            $rows[] = ['Selisih '.$name.' '.$month, 'Rp '.number_format($variance, 0, ',', '.'),
                isset($targets['settlement_variance_minor']) ? 'Rp '.number_format((int) $targets['settlement_variance_minor'], 0, ',', '.') : 'Belum diatur',
                isset($targets['settlement_variance_minor']) ? (abs($variance) <= $targets['settlement_variance_minor'] ? 'Sesuai' : 'Perlu dicek') : '—'];
        }

        return $this->section('KPI keuangan', ['Metrik', 'Aktual', 'Target', 'Status'], $rows);
    }

    private function formatKpi(string $key, int $value): string
    {
        if (str_ends_with($key, '_bps')) {
            return intdiv($value, 100).','.str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT).'%';
        }
        if (str_ends_with($key, '_minor')) {
            return 'Rp '.number_format($value, 0, ',', '.');
        }

        return $value.' hari';
    }

    private function percentage(int $numerator, int $denominator): string
    {
        $bps = intdiv(abs($numerator) * 10000, abs($denominator));

        return ($numerator < 0 ? '-' : '').intdiv($bps, 100).','.str_pad((string) ($bps % 100), 2, '0', STR_PAD_LEFT).'%';
    }

    private function section(string $title, array $headings, array $rows, array $money = []): array
    {
        return compact('title', 'headings', 'rows', 'money');
    }
}
