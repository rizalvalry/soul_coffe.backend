<?php

namespace App\Services\Reporting;

use App\Models\FinanceDailyTarget;
use App\Models\RiderClosing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceDailyReportService
{
    public const DEFAULT_COMPANY_CUPS = 1700;

    public const DEFAULT_RIDER_CUPS = 38;

    public function targetsFor(string $date): array
    {
        $setting = FinanceDailyTarget::query()->whereDate('operating_date', $date)->first();

        return [
            'company_cups' => $setting?->company_cups ?? self::DEFAULT_COMPANY_CUPS,
            'rider_cups' => $setting?->rider_cups ?? self::DEFAULT_RIDER_CUPS,
            'revision' => $setting?->revision ?? 0,
        ];
    }

    public function build(string $date, ?string $bikeType, string $rankingPeriod, string $scope = 'all'): array
    {
        $sections = [];
        $cards = [];
        $rankingRange = [];
        if (in_array($scope, ['all', 'per_rider', 'rekap'], true)) {
            $day = $this->day($date, $bikeType);
            $cards = $day['cards'];
            $sections['per_rider'] = $day['per_rider'];
            $sections['rekap'] = $day['rekap'];
        }
        if (in_array($scope, ['all', 'target', 'target_bulanan', 'target_rider'], true)) {
            $month = $this->month($date);
            $sections['target_bulanan'] = $month['targets'];
            $sections['target_rider'] = $month['riders'];
        }
        if (in_array($scope, ['all', 'peringkat', 'porsi_produk', 'total_produk'], true)) {
            $ranking = $this->ranking($date, $bikeType, $rankingPeriod);
            $sections['peringkat'] = $ranking['riders'];
            $sections['porsi_produk'] = $ranking['products'];
            $sections['total_produk'] = $ranking['totals'];
            $rankingRange = [$ranking['from'], $ranking['to']];
        }

        return [
            'date' => $date,
            'cards' => $cards,
            'sections' => $sections,
            'ranking_range' => $rankingRange,
        ];
    }

    private function day(string $date, ?string $bikeType): array
    {
        $closings = RiderClosing::query()->with(['cart:id,code,bike_type', 'rider:id,name', 'lines'])
            ->whereDate('operating_date', $date)
            ->when($bikeType, fn ($query) => $query->whereHas('cart', fn ($cart) => $cart->where('bike_type', $bikeType)))
            ->orderBy('cart_id')->orderBy('rider_id')->orderBy('id')->get();
        $cartIds = $closings->pluck('cart_id')->unique()->values()->all();
        $returns = $this->returns($date, $bikeType);
        $productIds = $closings->flatMap(fn ($closing) => $closing->lines->pluck('product_id'))
            ->concat(collect($returns)->flatMap(fn ($quantities) => array_keys($quantities)))->unique();
        $productNames = DB::table('products')->whereIn('id', $productIds)->pluck('name', 'id');
        $assignments = DB::table('staff_assignments')
            ->leftJoin('locations', 'locations.id', '=', 'staff_assignments.location_id')
            ->whereDate('staff_assignments.operating_date', $date)
            ->whereIn('staff_assignments.cart_id', $cartIds)
            ->orderBy('staff_assignments.id')->get([
                'staff_assignments.cart_id', 'staff_assignments.user_id', 'locations.name',
            ])->keyBy(fn ($row) => $row->cart_id.':'.$row->user_id);
        $settlements = DB::table('settlements')->whereDate('operating_date', $date)
            ->whereIn('cart_id', $cartIds)->get()->keyBy('cart_id');
        $returnOwner = $closings->groupBy('cart_id')->map(fn ($rows) => $rows->first()->id);
        $settlementOwner = $closings->groupBy('cart_id')->map(fn ($rows, $cartId) => $rows->firstWhere('rider_id', $settlements->get($cartId)?->staff_id)?->id ?? $rows->first()->id);

        $cards = [];
        $allRows = [];
        $recap = [];
        $countedReturns = [];
        foreach ($closings as $closing) {
            $cart = $closing->cart;
            $settlement = $settlementOwner->get($closing->cart_id) === $closing->id
                ? $settlements->get($closing->cart_id) : null;
            $approved = $settlement && $settlement->status === 'RECONCILED' && $settlement->reconciled_at !== null;
            $belongsToCard = $returnOwner->get($closing->cart_id) === $closing->id;
            $card = [
                'cart' => $cart?->code ?? '-', 'bike_type' => $cart?->bike_type ?? '-',
                'rider' => $closing->rider?->name ?? '-',
                'location' => $assignments->get($closing->cart_id.':'.$closing->rider_id)?->name ?? '-',
                'total_minor' => $closing->total_minor,
                'cash_minor' => $closing->cash_minor,
                'qris_minor' => $closing->qris_minor,
                'transfer_minor' => $closing->transfer_minor,
                'settlement_status' => $settlement?->status ?? 'Belum dicatat',
                'settlement_cash_minor' => $approved ? (int) $settlement->cash_minor : null,
                'settlement_qris_minor' => $approved ? (int) $settlement->qris_minor : null,
                'settlement_transfer_minor' => $approved ? (int) $settlement->transfer_minor : null,
                'cash_variance_minor' => $approved ? (int) $settlement->cash_variance_minor : null,
                'qris_variance_minor' => $approved ? (int) $settlement->qris_variance_minor : null,
                'transfer_variance_minor' => $approved ? (int) $settlement->transfer_variance_minor : null,
                'note' => $closing->note ?? '',
                'settlement_note' => $approved ? ($settlement->variance_reason ?? '') : '',
                'rows' => [],
            ];

            foreach ($closing->lines->sortBy('product_id') as $line) {
                $returned = $belongsToCard ? ($returns[$closing->cart_id][$line->product_id] ?? 0) : 0;
                $amounts = $card['rows'] === [] ? self::cardTotals($card) : array_fill(0, 13, null);
                $row = [
                    $card['location'], $card['cart'], $card['bike_type'], $card['rider'],
                    $productNames[$line->product_id] ?? '#'.$line->product_id,
                    $line->qty_in, $line->qty_carry, $line->qty_in + $line->qty_carry,
                    $line->qty_sold, $line->qty_wasted, $line->qty_remaining, $returned,
                    $line->unit_price_minor, $line->subtotal_minor,
                    ...$amounts,
                ];
                $card['rows'][] = $row;
                $allRows[] = $row;
                $productId = $line->product_id;
                $recap[$productId] ??= ['name' => $productNames[$productId] ?? '#'.$productId,
                    'in' => 0, 'sold' => 0, 'wasted' => 0, 'remaining' => 0, 'returned' => 0];
                $recap[$productId]['in'] += $line->qty_in + $line->qty_carry;
                $recap[$productId]['sold'] += $line->qty_sold;
                $recap[$productId]['wasted'] += $line->qty_wasted;
                $recap[$productId]['remaining'] += $line->qty_remaining;
                $recap[$productId]['returned'] += $returned;
                $countedReturns[$closing->cart_id][$productId] = ($countedReturns[$closing->cart_id][$productId] ?? 0) + $returned;
            }
            if ($belongsToCard) {
                foreach ($returns[$closing->cart_id] ?? [] as $productId => $returned) {
                    if (isset($countedReturns[$closing->cart_id][$productId])) {
                        continue;
                    }
                    $amounts = $card['rows'] === [] ? self::cardTotals($card) : array_fill(0, 13, null);
                    $row = [
                        $card['location'], $card['cart'], $card['bike_type'], $card['rider'],
                        $productNames[$productId] ?? '#'.$productId,
                        0, 0, 0, 0, 0, 0, $returned, 0, 0,
                        ...$amounts,
                    ];
                    $card['rows'][] = $row;
                    $allRows[] = $row;
                    $countedReturns[$closing->cart_id][$productId] = $returned;
                    $recap[$productId] ??= ['name' => $productNames[$productId] ?? '#'.$productId,
                        'in' => 0, 'sold' => 0, 'wasted' => 0, 'remaining' => 0, 'returned' => 0];
                    $recap[$productId]['returned'] += $returned;
                }
            }
            $cards[] = $card;
        }
        foreach ($returns as $cartId => $products) {
            foreach ($products as $productId => $returned) {
                $uncounted = $returned - ($countedReturns[$cartId][$productId] ?? 0);
                if ($uncounted <= 0) {
                    continue;
                }
                $recap[$productId] ??= ['name' => $productNames[$productId] ?? '#'.$productId,
                    'in' => 0, 'sold' => 0, 'wasted' => 0, 'remaining' => 0, 'returned' => 0];
                $recap[$productId]['returned'] += $uncounted;
            }
        }
        $totalSold = array_sum(array_column($recap, 'sold'));
        uasort($recap, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $recapRows = array_map(fn ($item) => [
            $item['name'], $item['in'], $item['sold'], $item['wasted'], $item['remaining'],
            $item['returned'], self::percent($item['sold'], $totalSold),
        ], array_values($recap));

        return [
            'cards' => $cards,
            'per_rider' => self::section('Per Rider', [
                'Lokasi', 'Coffee Bike', 'Jenis', 'Rider', 'Produk', 'Diambil', 'Sisa kemarin',
                'Dibawa', 'Terjual', 'Rusak', 'Sisa', 'Dibawa balik', 'Harga (Rp)', 'Subtotal (Rp)',
                'Total Closing (Rp)', 'Closing tunai (Rp)', 'Closing QRIS (Rp)', 'Closing transfer (Rp)', 'Status setoran',
                'Setoran tunai (Rp)', 'Setoran QRIS (Rp)', 'Setoran transfer (Rp)',
                'Selisih tunai (Rp)', 'Selisih QRIS (Rp)', 'Selisih transfer (Rp)',
                'Catatan Closing', 'Alasan selisih setoran',
            ], $allRows, [12, 13, 14, 15, 16, 17, 19, 20, 21, 22, 23, 24]),
            'rekap' => self::section('Rekap semua Coffee Bike', [
                'Produk', 'Dibawa', 'Terjual', 'Rusak', 'Sisa', 'Dibawa balik', 'Porsi terjual',
            ], $recapRows),
        ];
    }

    private function returns(string $date, ?string $bikeType): array
    {
        $rows = DB::table('stock_ledger')->where('location_type', 'cart')
            ->where('movement_type', 'RETURN_OUT')->where('ref_type', 'cart_closeout_return')
            ->join('carts', 'carts.id', '=', 'stock_ledger.location_id')
            ->whereDate('stock_ledger.created_at', $date)
            ->when($bikeType, fn ($query) => $query->where('carts.bike_type', $bikeType))
            ->selectRaw('stock_ledger.location_id, stock_ledger.product_id, -SUM(stock_ledger.qty_delta) AS qty')
            ->groupBy('stock_ledger.location_id', 'stock_ledger.product_id')->get();
        $settlementRows = DB::table('stock_ledger as ledger')
            ->join('settlements', 'settlements.id', '=', 'ledger.ref_id')
            ->join('carts', 'carts.id', '=', 'ledger.location_id')
            ->where('ledger.location_type', 'cart')->where('ledger.movement_type', 'RETURN_OUT')
            ->where('ledger.ref_type', 'settlement_return')
            ->whereDate('settlements.operating_date', $date)
            ->whereColumn('ledger.location_id', 'settlements.cart_id')
            ->when($bikeType, fn ($query) => $query->where('carts.bike_type', $bikeType))
            ->selectRaw('ledger.location_id, ledger.product_id, -SUM(ledger.qty_delta) AS qty')
            ->groupBy('ledger.location_id', 'ledger.product_id')->get();
        $totals = [];
        foreach ($rows->concat($settlementRows) as $row) {
            $totals[$row->location_id][$row->product_id] = ($totals[$row->location_id][$row->product_id] ?? 0) + (int) $row->qty;
        }

        return $totals;
    }

    private function month(string $date): array
    {
        $from = Carbon::parse($date)->startOfMonth();
        $to = Carbon::parse($date)->endOfMonth();
        $today = today();
        if ($to->greaterThan($today)) {
            $to = $today;
        }
        $daily = DB::table('rider_closing_lines as lines')
            ->join('rider_closings as closings', 'closings.id', '=', 'lines.rider_closing_id')
            ->whereBetween('closings.operating_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('closings.operating_date, closings.rider_id, SUM(lines.qty_sold) AS cups')
            ->groupBy('closings.operating_date', 'closings.rider_id')->get();
        $byDay = $daily->groupBy('operating_date');
        $attendance = DB::table('attendances')->where('role', 'STAFF')
            ->whereBetween('operating_date', [$from->toDateString(), $to->toDateString()])
            ->get(['operating_date', 'user_id'])->groupBy('operating_date');
        $settings = FinanceDailyTarget::query()->whereBetween('operating_date', [$from, $to])
            ->get()->keyBy(fn (FinanceDailyTarget $target) => $target->operating_date->toDateString());
        $targets = [];
        $riders = [];
        $shortfall = 0;
        for ($cursor = $from->copy(); $cursor->lessThanOrEqualTo($to); $cursor->addDay()) {
            $day = $cursor->toDateString();
            $setting = $settings->get($day);
            $companyTarget = $setting?->company_cups ?? self::DEFAULT_COMPANY_CUPS;
            $riderTarget = $setting?->rider_cups ?? self::DEFAULT_RIDER_CUPS;
            $sold = (int) $byDay->get($day, collect())->sum('cups');
            $difference = $sold - $companyTarget;
            $shortfall = max(0, $shortfall - $difference);
            $targets[] = [$day, $companyTarget, $sold, $difference, $shortfall];
            $riderCups = $byDay->get($day, collect())->pluck('cups', 'rider_id')->map(fn ($cups) => (int) $cups);
            foreach ($attendance->get($day, collect()) as $attendanceRow) {
                $riderCups[$attendanceRow->user_id] ??= 0;
            }
            foreach ($riderCups as $riderId => $cups) {
                $riders[$riderId] ??= ['cups' => 0, 'reached' => 0, 'missed' => 0];
                $riders[$riderId]['cups'] += $cups;
                $riders[$riderId][$cups >= $riderTarget ? 'reached' : 'missed']++;
            }
        }
        $names = DB::table('users')->whereIn('id', array_keys($riders))->pluck('name', 'id');
        $riderRows = [];
        foreach ($riders as $id => $stats) {
            $riderRows[] = [$names[$id] ?? '#'.$id, $stats['cups'], $stats['reached'], $stats['missed']];
        }
        usort($riderRows, fn ($a, $b) => strcmp($a[0], $b[0]));

        return [
            'targets' => self::section('Target perusahaan per hari', [
                'Tanggal', 'Target', 'Realisasi', 'Selisih', 'Akumulasi kekurangan',
            ], $targets),
            'riders' => self::section('Target Rider bulan ini', [
                'Rider', 'Cup terjual', 'Hari mencapai target', 'Hari belum mencapai target',
            ], $riderRows),
        ];
    }

    private function ranking(string $date, ?string $bikeType, string $period): array
    {
        $anchor = Carbon::parse($date);
        $from = ($period === 'weekly' ? $anchor->copy()->startOfWeek(Carbon::MONDAY) : $anchor->copy()->startOfMonth())->toDateString();
        $to = ($period === 'weekly' ? $anchor->copy()->endOfWeek(Carbon::SUNDAY) : $anchor->copy()->endOfMonth())->toDateString();
        $rows = DB::table('rider_closing_lines as lines')
            ->join('rider_closings as closings', 'closings.id', '=', 'lines.rider_closing_id')
            ->join('carts', 'carts.id', '=', 'closings.cart_id')
            ->join('users', 'users.id', '=', 'closings.rider_id')
            ->join('products', 'products.id', '=', 'lines.product_id')
            ->whereBetween('closings.operating_date', [$from, $to])
            ->when($bikeType, fn ($query) => $query->where('carts.bike_type', $bikeType))
            ->selectRaw('closings.rider_id, users.name AS rider_name, carts.code AS cart_code, closings.operating_date, lines.product_id, products.name AS product_name, SUM(lines.qty_sold) AS cups')
            ->groupBy('closings.rider_id', 'users.name', 'carts.code', 'closings.operating_date', 'lines.product_id', 'products.name')->get();
        $riders = [];
        $totals = [];
        foreach ($rows as $row) {
            $id = $row->rider_id;
            $riders[$id] ??= ['id' => $id, 'name' => $row->rider_name, 'cups' => 0, 'carts' => [], 'days' => [], 'products' => []];
            $cups = (int) $row->cups;
            $riders[$id]['cups'] += $cups;
            $riders[$id]['carts'][$row->cart_code] = true;
            $riders[$id]['days'][$row->operating_date] = true;
            $riders[$id]['products'][$row->product_id] ??= ['name' => $row->product_name, 'cups' => 0];
            $riders[$id]['products'][$row->product_id]['cups'] += $cups;
            $totals[$row->product_id] ??= ['name' => $row->product_name, 'cups' => 0];
            $totals[$row->product_id]['cups'] += $cups;
        }
        $assignments = DB::table('staff_assignments')
            ->join('carts', 'carts.id', '=', 'staff_assignments.cart_id')
            ->whereBetween('staff_assignments.operating_date', [$from, $to])
            ->when($bikeType, fn ($query) => $query->where('carts.bike_type', $bikeType))
            ->get(['staff_assignments.user_id', 'staff_assignments.operating_date', 'carts.code'])
            ->groupBy(fn ($assignment) => $assignment->user_id.':'.$assignment->operating_date);
        $attendanceDays = DB::table('attendances')
            ->join('users', 'users.id', '=', 'attendances.user_id')
            ->where('attendances.role', 'STAFF')
            ->whereBetween('attendances.operating_date', [$from, $to])
            ->get(['attendances.user_id', 'attendances.operating_date', 'users.name']);
        foreach ($attendanceDays as $attendance) {
            $assigned = $assignments->get($attendance->user_id.':'.$attendance->operating_date, collect());
            if ($bikeType && $assigned->isEmpty()) {
                continue;
            }
            $riders[$attendance->user_id] ??= ['id' => $attendance->user_id, 'name' => $attendance->name,
                'cups' => 0, 'carts' => [], 'days' => [], 'products' => []];
            $riders[$attendance->user_id]['days'][$attendance->operating_date] = true;
            foreach ($assigned as $assignment) {
                $riders[$attendance->user_id]['carts'][$assignment->code] = true;
            }
        }
        uasort($riders, fn ($a, $b) => ($b['cups'] <=> $a['cups']) ?: (strcmp($a['name'], $b['name']) ?: ($a['id'] <=> $b['id'])));
        $rankingRows = [];
        $productRows = [];
        $rank = 1;
        foreach ($riders as $rider) {
            $days = count($rider['days']);
            $rankingRows[] = [$rank++, $rider['name'], implode(', ', array_keys($rider['carts'])) ?: '-',
                $days, $rider['cups'], self::tenths($rider['cups'], $days)];
            foreach ($rider['products'] as $product) {
                $productRows[] = [$rider['name'], $product['name'], $product['cups'],
                    self::percent($product['cups'], $rider['cups'])];
            }
        }
        uasort($totals, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $allCups = array_sum(array_column($totals, 'cups'));
        $totalRows = array_map(fn ($product) => [$product['name'], $product['cups'],
            self::percent($product['cups'], $allCups)], array_values($totals));

        return [
            'from' => $from, 'to' => $to,
            'riders' => self::section('Peringkat Rider', ['Peringkat', 'Rider', 'Coffee Bike', 'Hari masuk', 'Cup terjual', 'Rata-rata per hari'], $rankingRows),
            'products' => self::section('Porsi produk per Rider', ['Rider', 'Produk', 'Cup terjual', 'Porsi'], $productRows),
            'totals' => self::section('Porsi produk total', ['Produk', 'Cup terjual', 'Porsi'], $totalRows),
        ];
    }

    private static function section(string $title, array $headings, array $rows, array $money = []): array
    {
        return compact('title', 'headings', 'rows', 'money');
    }

    private static function cardTotals(array $card): array
    {
        return [
            $card['total_minor'], $card['cash_minor'], $card['qris_minor'], $card['transfer_minor'],
            $card['settlement_status'], $card['settlement_cash_minor'], $card['settlement_qris_minor'],
            $card['settlement_transfer_minor'], $card['cash_variance_minor'],
            $card['qris_variance_minor'], $card['transfer_variance_minor'],
            $card['note'], $card['settlement_note'],
        ];
    }

    private static function tenths(int $value, int $total): string
    {
        if ($total === 0) {
            return '0,0';
        }
        $tenths = intdiv($value * 10, $total);

        return intdiv($tenths, 10).','.($tenths % 10);
    }

    private static function percent(int $value, int $total): string
    {
        return self::tenths($value * 100, $total).'%';
    }
}
