<?php

namespace App\Services\Reporting;

use App\Enums\Role;
use App\Models\Attendance;
use App\Models\DailyCartAllowance;
use App\Models\RefillRequest;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every number the admin dashboard shows, computed here rather than inside a widget.
 *
 * A widget class in Filament is nearly impossible to unit test directly — it wants a mounted
 * Livewire component. Every query that decides what an owner sees lives here instead, tested
 * directly, and the widgets (app/Filament/Widgets) are thin adapters that only shape this into
 * what Filament's Stat/ChartWidget expects.
 */
class DashboardMetricsService
{
    /**
     * @return array{
     *     revenue_today_minor: int,
     *     revenue_yesterday_minor: int,
     *     refills_today: int,
     *     refills_today_by_status: array<string,int>,
     *     staff_clocked_in_today: int,
     *     staff_total_active: int,
     *     allowance_disbursed_today_minor: int,
     *     variance_today_minor: int,
     * }
     */
    public function todaySnapshot(?Carbon $date = null): array
    {
        $today = ($date ?? Carbon::today())->toDateString();
        $yesterday = ($date ?? Carbon::today())->copy()->subDay()->toDateString();

        $refillsToday = RefillRequest::query()->whereDate('operating_date', $today)->get(['status']);

        return [
            'revenue_today_minor' => (int) Settlement::query()->whereDate('operating_date', $today)->sum('declared_total_minor'),
            'revenue_yesterday_minor' => (int) Settlement::query()->whereDate('operating_date', $yesterday)->sum('declared_total_minor'),
            'refills_today' => $refillsToday->count(),
            'refills_today_by_status' => $refillsToday
                ->groupBy(fn (RefillRequest $r) => $r->status->value)
                ->map(fn (Collection $rows) => $rows->count())
                ->all(),
            'staff_clocked_in_today' => Attendance::query()
                ->whereDate('operating_date', $today)
                ->where('role', Role::STAFF)
                ->count(),
            'staff_total_active' => User::query()->where('role', Role::STAFF)->where('is_active', true)->count(),
            'allowance_disbursed_today_minor' => (int) DailyCartAllowance::query()->whereDate('operating_date', $today)->sum('amount_minor'),
            'variance_today_minor' => (int) Settlement::query()->whereDate('operating_date', $today)->sum('variance_minor'),
        ];
    }

    /**
     * One row per day in `[start, end]`, oldest first, with every day present even when nothing
     * happened that day — a chart with a gap where a day is simply missing reads as a data
     * outage, not as "zero that day".
     *
     * @return Collection<int, array{date: string, revenue_minor: int}>
     */
    public function revenueTrend(int $days = 14, ?Carbon $end = null): Collection
    {
        $end = ($end ?? Carbon::today())->copy()->startOfDay();
        $start = $end->copy()->subDays($days - 1);

        $byDate = Settlement::query()
            ->whereBetween('operating_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('operating_date, SUM(declared_total_minor) as total')
            ->groupBy('operating_date')
            ->pluck('total', 'operating_date')
            ->mapWithKeys(fn ($total, $date) => [Carbon::parse($date)->toDateString() => (int) $total]);

        return $this->fillDateRange($start, $end, fn (string $date) => [
            'date' => $date,
            'revenue_minor' => $byDate[$date] ?? 0,
        ]);
    }

    /**
     * @return Collection<int, array{date: string, count: int}>
     */
    public function refillVolumeTrend(int $days = 14, ?Carbon $end = null): Collection
    {
        $end = ($end ?? Carbon::today())->copy()->startOfDay();
        $start = $end->copy()->subDays($days - 1);

        $byDate = RefillRequest::query()
            ->whereBetween('operating_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('operating_date, COUNT(*) as total')
            ->groupBy('operating_date')
            ->pluck('total', 'operating_date')
            ->mapWithKeys(fn ($total, $date) => [Carbon::parse($date)->toDateString() => (int) $total]);

        return $this->fillDateRange($start, $end, fn (string $date) => [
            'date' => $date,
            'count' => $byDate[$date] ?? 0,
        ]);
    }

    /**
     * Distribution over the window, not a running total since forever — a pie of "all requests
     * ever" is dominated by CLOSED the moment the app has run for a month and stops being useful
     * for "what does this week look like".
     *
     * @return array<string,int> status value => count
     */
    public function refillStatusDistribution(int $days = 30, ?Carbon $end = null): array
    {
        $end = ($end ?? Carbon::today())->copy()->startOfDay();
        $start = $end->copy()->subDays($days - 1);

        return RefillRequest::query()
            ->whereBetween('operating_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->mapWithKeys(fn ($total, $status) => [
                ($status instanceof \BackedEnum ? $status->value : $status) => (int) $total,
            ])
            ->all();
    }

    /**
     * Attendance rate per day — clocked-in STAFF divided by active STAFF headcount AT THAT TIME.
     *
     * Uses TODAY's active headcount as the denominator for every day in the window rather than
     * reconstructing historical headcount (nothing records when a staff account was activated),
     * so a rate over 100% is possible right after someone is deactivated and is a known,
     * documented approximation rather than a silent error.
     *
     * @return Collection<int, array{date: string, rate: float}>
     */
    public function attendanceRateTrend(int $days = 14, ?Carbon $end = null): Collection
    {
        $end = ($end ?? Carbon::today())->copy()->startOfDay();
        $start = $end->copy()->subDays($days - 1);

        $activeStaff = max(1, User::query()->where('role', Role::STAFF)->where('is_active', true)->count());

        $byDate = Attendance::query()
            ->where('role', Role::STAFF)
            ->whereBetween('operating_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('operating_date, COUNT(*) as total')
            ->groupBy('operating_date')
            ->pluck('total', 'operating_date')
            ->mapWithKeys(fn ($total, $date) => [Carbon::parse($date)->toDateString() => (int) $total]);

        return $this->fillDateRange($start, $end, fn (string $date) => [
            'date' => $date,
            'rate' => round((($byDate[$date] ?? 0) / $activeStaff) * 100, 1),
        ]);
    }

    /**
     * @param  callable(string):array<string,mixed>  $row
     * @return Collection<int, array<string,mixed>>
     */
    private function fillDateRange(Carbon $start, Carbon $end, callable $row): Collection
    {
        $out = collect();
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $out->push($row($cursor->toDateString()));
        }

        return $out;
    }
}
