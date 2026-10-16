<?php

namespace App\Filament\Widgets;

use App\Enums\PanelModule;
use App\Enums\RefillStatus;
use App\Services\Access\PermissionMatrix;
use App\Services\Reporting\DashboardMetricsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

/**
 * The four numbers an owner checks first thing: money, demand, people, cash risk.
 *
 * Everything here reads from DashboardMetricsService rather than querying models directly, so
 * the numbers on screen and the numbers `tests/Feature/Reporting/DashboardMetricsServiceTest.php`
 * asserts against are provably the same computation.
 */
class OperationsOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        // The dashboard is a matrix module like any other, so an Administrator can hand the
        // overview to Finance without handing over the menus behind it. Each widget checks for
        // itself rather than trusting the page, because a widget can be placed anywhere.
        return PermissionMatrix::can(Auth::user(), PanelModule::DASHBOARD, 'view');
    }

    protected function getStats(): array
    {
        $metrics = app(DashboardMetricsService::class);
        $s = $metrics->todaySnapshot();

        $revenueDelta = $s['revenue_today_minor'] - $s['revenue_yesterday_minor'];
        $revenueDeltaBadge = $s['revenue_yesterday_minor'] > 0
            ? sprintf('%+.0f%%', ($revenueDelta / $s['revenue_yesterday_minor']) * 100)
            : '—';
        $revenueDeltaCaption = $s['revenue_yesterday_minor'] > 0
            ? 'dari kemarin'
            : ($s['revenue_today_minor'] > 0 ? 'Tidak ada data kemarin' : 'Belum ada transaksi');

        $attendanceRate = $s['staff_total_active'] > 0
            ? round(($s['staff_clocked_in_today'] / $s['staff_total_active']) * 100)
            : 0;

        return [
            Stat::make('Pendapatan', 'Rp '.Number::format($s['revenue_today_minor'], locale: 'id'))
                ->icon('heroicon-o-banknotes')
                ->description($this->footer($revenueDeltaBadge, $revenueDeltaCaption, $revenueDelta >= 0 ? 'success' : 'danger'))
                ->color($revenueDelta >= 0 ? 'success' : 'danger'),

            Stat::make('Refill Request', (string) $s['refills_today'])
                ->icon('heroicon-o-arrow-path')
                ->description($this->footer($s['refills_today'].' request', $this->statusBreakdownLabel($s['refills_today_by_status']), 'brand'))
                ->color('primary'),

            Stat::make('Rider Absen', sprintf('%d / %d', $s['staff_clocked_in_today'], $s['staff_total_active']))
                ->icon('heroicon-o-user-group')
                ->description($this->footer($attendanceRate.'%', 'rider aktif sudah absen', $attendanceRate >= 80 ? 'success' : ($attendanceRate >= 50 ? 'warning' : 'danger')))
                ->color($attendanceRate >= 80 ? 'success' : ($attendanceRate >= 50 ? 'warning' : 'danger')),

            Stat::make('Selisih Kas', 'Rp '.Number::format($s['variance_today_minor'], locale: 'id'))
                ->icon($s['variance_today_minor'] === 0 ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle')
                ->description($this->footer($s['variance_today_minor'] === 0 ? 'Aman' : 'Tinjau', $s['variance_today_minor'] === 0 ? 'Belum ada selisih tercatat' : 'Perlu ditinjau Finance', $s['variance_today_minor'] === 0 ? 'success' : 'danger'))
                ->color($s['variance_today_minor'] === 0 ? 'success' : 'danger'),

        ];
    }

    private function footer(string $badge, string $caption, string $tone): HtmlString
    {
        return new HtmlString('<span class="sc-stat-badge sc-stat-badge--'.e($tone).'">'.e($badge).'</span>'
            .'<span class="sc-stat-caption">'.e($caption).'</span>');
    }

    /** @param array<string,int> $byStatus */
    private function statusBreakdownLabel(array $byStatus): string
    {
        if ($byStatus === []) {
            return 'Belum ada permintaan hari ini';
        }

        $parts = [];
        foreach ($byStatus as $status => $count) {
            $parts[] = $count.' '.($this->statusLabels()[$status] ?? $status);
        }

        return implode(' · ', $parts);
    }

    /** @return array<string,string> */
    private function statusLabels(): array
    {
        return array_combine(
            array_map(fn ($case) => $case->value, RefillStatus::cases()),
            array_map(fn ($case) => $case->label(), RefillStatus::cases()),
        );
    }
}
