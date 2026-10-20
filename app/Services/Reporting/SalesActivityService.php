<?php

namespace App\Services\Reporting;

use App\Enums\Role;
use App\Models\RiderClosing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Reads `sales` for the panel: who sold what, where, and at which hour.
 *
 * This is the layer the requested AI insight will eventually consume, so the shape of what comes
 * out matters more than the screens that show it today. Three questions, three methods:
 *
 *   perCart()   — the dashboard list: every cart's day, at its own location, with its rider.
 *   areaHours() — the engagement grid: area × hour of day, in cups. "Pulomas is busy at 09:00,
 *                 Cempaka Mas at 10:00" is a claim about THIS table.
 *   suspects()  — flagged transactions awaiting a human look.
 *
 * Aggregation is done in SQL rather than in PHP. On a shared host the difference between one
 * grouped query and pulling a day of rows into memory is the difference between a page that
 * opens and a page that times out during the busy hour it is meant to show.
 *
 * Hours come from `occurred_at`, which is the server clock (R16) — comparing areas by hour is
 * only meaningful if every row's hour was stamped by the same clock.
 */
class SalesActivityService
{
    /**
     * Per cart for one operating day: transactions, cups, revenue, and when it last sold.
     *
     * @return array<int, array<string, mixed>>
     */
    public function perCart(?Carbon $date = null): array
    {
        $date = ($date ?? Carbon::today())->toDateString();
        if ($this->usesClosing($date)) {
            $showClosingLocation = $this->canSeeClosingLocation();
            $closings = RiderClosing::query()->with(['cart:id,code', 'rider:id,name', 'lines'])
                ->whereDate('operating_date', $date)->get();
            $locations = DB::table('staff_assignments')
                ->leftJoin('locations', 'locations.id', '=', 'staff_assignments.location_id')
                ->whereDate('staff_assignments.operating_date', $date)
                ->whereIn('staff_assignments.cart_id', $closings->pluck('cart_id'))
                ->orderBy('staff_assignments.id')
                ->get(['staff_assignments.cart_id', 'locations.name'])->keyBy('cart_id');

            return $closings->map(fn (RiderClosing $closing): array => [
                'cart_code' => $closing->cart?->code,
                'staff_id' => $closing->rider_id,
                'staff_name' => $closing->rider?->name,
                'area' => $locations->get($closing->cart_id)?->name,
                'transactions' => 1,
                'cups' => (int) $closing->lines->sum('qty_sold'),
                'revenue' => $closing->total_minor,
                'flagged' => (int) (($showClosingLocation && $closing->outside_closing_point) || $closing->lines->contains(fn ($line): bool => $line->qty_carry > 0)),
                'closing_location_warning' => $showClosingLocation && $closing->outside_closing_point
                    ? ($closing->closing_distance_m === null ? 'Pernah Closing tanpa GPS' : 'Pernah Closing di luar titik; lokasi terbaru '.$closing->closing_distance_m.' m') : null,
                'last_sale_at' => $closing->closed_at,
            ])->sortByDesc('cups')->values()->all();
        }

        return DB::table('sales')
            ->join('carts', 'carts.id', '=', 'sales.cart_id')
            ->leftJoin('users', 'users.id', '=', 'sales.staff_id')
            ->leftJoin('locations', 'locations.id', '=', 'sales.location_id')
            ->whereDate('sales.operating_date', $date)
            // A voided sale never happened, as far as every total on this page is concerned.
            ->whereNull('sales.voided_at')
            ->groupBy('carts.id', 'carts.code', 'users.id', 'users.name', 'locations.id', 'locations.name')
            ->orderByDesc('cups')
            ->get([
                'carts.code as cart_code',
                'users.id as staff_id',
                'users.name as staff_name',
                'locations.name as area',
                DB::raw('COUNT(*) as transactions'),
                DB::raw('COALESCE(SUM(sales.total_qty), 0) as cups'),
                DB::raw('COALESCE(SUM(sales.total_amount_minor), 0) as revenue'),
                DB::raw('SUM(CASE WHEN sales.is_suspect = 1 THEN 1 ELSE 0 END) as flagged'),
                DB::raw('MAX(sales.occurred_at) as last_sale_at'),
            ])
            ->map(fn (object $row): array => [
                'cart_code' => $row->cart_code,
                'staff_id' => $row->staff_id,
                'staff_name' => $row->staff_name,
                'area' => $row->area,
                'transactions' => (int) $row->transactions,
                'cups' => (int) $row->cups,
                'revenue' => (int) $row->revenue,
                'flagged' => (int) $row->flagged,
                'last_sale_at' => $row->last_sale_at ? Carbon::parse($row->last_sale_at) : null,
            ])
            ->all();
    }

    /**
     * Area × hour, in cups — the grid the whole "analytics orientation mapping" idea rests on.
     *
     * Returns only the hours that actually traded, plus per-area and per-hour totals, so a caller
     * can render a heat grid without deciding what an empty hour means.
     *
     * @param  int  $days  how many operating days back from $date to include (1 = that day only)
     * @return array{areas: array<int, string>, hours: array<int, int>, cells: array<string, int>, area_totals: array<string, int>, hour_totals: array<int, int>, peak: array{area: string|null, hour: int|null, cups: int}}
     */
    public function areaHours(?Carbon $date = null, int $days = 1): array
    {
        $end = ($date ?? Carbon::today())->startOfDay();
        $start = $end->copy()->subDays(max(0, $days - 1));

        $legacyRows = DB::table('sales')
            ->leftJoin('locations', 'locations.id', '=', 'sales.location_id')
            ->whereBetween('sales.operating_date', [$start->toDateString(), $end->toDateString()])
            ->whereDate('sales.operating_date', '<', (string) config('soul.closing_started_on', '2026-10-18'))
            ->whereNull('sales.voided_at')
            ->groupBy('area', 'hour')
            ->orderBy('area')
            ->orderBy('hour')
            ->get([
                DB::raw("COALESCE(locations.name, 'Tanpa lokasi') as area"),
                DB::raw('HOUR(sales.occurred_at) as hour'),
                DB::raw('COALESCE(SUM(sales.total_qty), 0) as cups'),
            ]);
        $rows = collect($legacyRows);
        $modernStart = max($start->toDateString(), (string) config('soul.closing_started_on', '2026-10-18'));
        if ($modernStart <= $end->toDateString()) {
            // These are activity signals, not inferred sales. No per-transaction time exists
            // after Closing, so the heat grid counts refill and location evidence by hour.
            $pings = DB::table('staff_location_pings')
                ->leftJoin('locations', 'locations.id', '=', 'staff_location_pings.location_id')
                ->whereBetween('staff_location_pings.operating_date', [$modernStart, $end->toDateString()])
                ->groupBy('area', 'hour')->get([
                    DB::raw("COALESCE(locations.name, 'Tanpa lokasi') AS area"),
                    DB::raw('HOUR(staff_location_pings.recorded_at) AS hour'),
                    DB::raw('COUNT(*) AS cups'),
                ]);
            $rows = $rows->concat($pings);
            $assignmentAreas = DB::table('staff_assignments')
                ->leftJoin('locations', 'locations.id', '=', 'staff_assignments.location_id')
                ->whereBetween('staff_assignments.operating_date', [$modernStart, $end->toDateString()])
                ->orderBy('staff_assignments.id')
                ->get(['staff_assignments.cart_id', 'staff_assignments.operating_date', 'locations.name'])
                ->keyBy(fn ($row): string => $row->cart_id.'|'.$row->operating_date);
            $refills = DB::table('refill_requests')
                ->whereBetween('operating_date', [$modernStart, $end->toDateString()])
                ->whereNotNull('submitted_at')->get(['cart_id', 'operating_date', 'submitted_at']);
            $rows = $rows->concat($refills->map(function ($refill) use ($assignmentAreas): object {
                $location = $assignmentAreas->get($refill->cart_id.'|'.$refill->operating_date);

                return (object) [
                    'area' => $location?->name ?? 'Tanpa lokasi',
                    'hour' => Carbon::parse($refill->submitted_at)->hour,
                    'cups' => 1,
                ];
            }));
        }

        $areas = [];
        $hours = [];
        $cells = [];
        $areaTotals = [];
        $hourTotals = [];
        $peak = ['area' => null, 'hour' => null, 'cups' => 0];

        foreach ($rows as $row) {
            $area = (string) $row->area;
            $hour = (int) $row->hour;
            $cups = (int) $row->cups;

            $areas[$area] = true;
            $hours[$hour] = true;
            $cells[$area.'|'.$hour] = ($cells[$area.'|'.$hour] ?? 0) + $cups;
            $areaTotals[$area] = ($areaTotals[$area] ?? 0) + $cups;
            $hourTotals[$hour] = ($hourTotals[$hour] ?? 0) + $cups;

            if ($cells[$area.'|'.$hour] > $peak['cups']) {
                $peak = ['area' => $area, 'hour' => $hour, 'cups' => $cells[$area.'|'.$hour]];
            }
        }

        $areaNames = array_keys($areas);
        sort($areaNames);
        $hourList = array_keys($hours);
        sort($hourList);

        return [
            'basis' => $modernStart <= $end->toDateString() ? 'activity_signals' : 'sales',
            'areas' => $areaNames,
            'hours' => $hourList,
            'cells' => $cells,
            'area_totals' => $areaTotals,
            'hour_totals' => $hourTotals,
            'peak' => $peak,
        ];
    }

    /**
     * Flagged transactions for a day, newest first — the queue behind the suspect notification.
     *
     * @return array<int, array<string, mixed>>
     */
    public function suspects(?Carbon $date = null, int $limit = 50): array
    {
        $date = ($date ?? Carbon::today())->toDateString();

        return DB::table('sales')
            ->leftJoin('carts', 'carts.id', '=', 'sales.cart_id')
            ->leftJoin('users', 'users.id', '=', 'sales.staff_id')
            ->leftJoin('locations', 'locations.id', '=', 'sales.location_id')
            ->where('sales.is_suspect', true)
            ->whereDate('sales.operating_date', $date)
            // Voided means it was already looked at and undone — it should not sit in the
            // review queue forever.
            ->whereNull('sales.voided_at')
            ->orderByDesc('sales.occurred_at')
            ->limit($limit)
            ->get([
                'sales.id',
                'sales.occurred_at',
                'sales.total_qty',
                'sales.total_amount_minor',
                'sales.suspect_reason',
                'carts.code as cart_code',
                'users.name as staff_name',
                'locations.name as area',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'occurred_at' => Carbon::parse($row->occurred_at),
                'cups' => (int) $row->total_qty,
                'revenue' => (int) $row->total_amount_minor,
                'reason' => $row->suspect_reason,
                'cart_code' => $row->cart_code,
                'staff_name' => $row->staff_name,
                'area' => $row->area,
            ])
            ->all();
    }

    /**
     * Day totals for the summary strip.
     *
     * @return array{transactions: int, cups: int, revenue: int, flagged: int, carts: int}
     */
    public function dayTotals(?Carbon $date = null): array
    {
        $date = ($date ?? Carbon::today())->toDateString();
        if ($this->usesClosing($date)) {
            $showClosingLocation = $this->canSeeClosingLocation();
            $closings = RiderClosing::query()->with('lines')->whereDate('operating_date', $date)->get();

            return [
                'transactions' => $closings->count(),
                'cups' => (int) $closings->sum(fn (RiderClosing $closing): int => $closing->lines->sum('qty_sold')),
                'revenue' => (int) $closings->sum('total_minor'),
                'flagged' => $closings->filter(fn (RiderClosing $closing): bool => ($showClosingLocation && $closing->outside_closing_point)
                    || $closing->lines->contains(fn ($line): bool => $line->qty_carry > 0))->count(),
                'carts' => $closings->pluck('cart_id')->unique()->count(),
            ];
        }

        $row = DB::table('sales')
            ->whereDate('operating_date', $date)
            ->whereNull('voided_at')
            ->first([
                DB::raw('COUNT(*) as transactions'),
                DB::raw('COALESCE(SUM(total_qty), 0) as cups'),
                DB::raw('COALESCE(SUM(total_amount_minor), 0) as revenue'),
                DB::raw('SUM(CASE WHEN is_suspect = 1 THEN 1 ELSE 0 END) as flagged'),
                DB::raw('COUNT(DISTINCT cart_id) as carts'),
            ]);

        return [
            'transactions' => (int) ($row->transactions ?? 0),
            'cups' => (int) ($row->cups ?? 0),
            'revenue' => (int) ($row->revenue ?? 0),
            'flagged' => (int) ($row->flagged ?? 0),
            'carts' => (int) ($row->carts ?? 0),
        ];
    }

    private function canSeeClosingLocation(): bool
    {
        $role = Auth::user()?->role;

        return in_array($role, [Role::ADMINISTRATOR, Role::FINANCE], true);
    }

    private function usesClosing(string $date): bool
    {
        return $date >= (string) config('soul.closing_started_on', '2026-10-18');
    }
}
