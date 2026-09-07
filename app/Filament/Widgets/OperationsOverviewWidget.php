<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Services\Reporting\DashboardMetricsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
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
        // Belt-and-suspenders: the panel itself already refuses every non-Administrator at the
        // door (AdminPanelAccessTest), but a widget checking its own visibility means this stays
        // correct even if the panel's role list is ever widened for another resource.
        return Auth::user()?->role === Role::ADMINISTRATOR;
    }

    protected function getStats(): array
    {
        $s = app(DashboardMetricsService::class)->todaySnapshot();

        $revenueDelta = $s['revenue_today_minor'] - $s['revenue_yesterday_minor'];
        $revenueDeltaLabel = $s['revenue_yesterday_minor'] > 0
            ? sprintf('%+.0f%% dari kemarin', ($revenueDelta / $s['revenue_yesterday_minor']) * 100)
            : ($s['revenue_today_minor'] > 0 ? 'Tidak ada data kemarin' : 'Belum ada transaksi');

        $attendanceRate = $s['staff_total_active'] > 0
            ? round(($s['staff_clocked_in_today'] / $s['staff_total_active']) * 100)
            : 0;

        return [
            Stat::make('Pendapatan Hari Ini', 'Rp '.Number::format($s['revenue_today_minor'], locale: 'id'))
                ->description($revenueDeltaLabel)
                ->descriptionIcon($revenueDelta >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($revenueDelta >= 0 ? 'success' : 'danger'),

            Stat::make('Refill Request Hari Ini', (string) $s['refills_today'])
                ->description($this->statusBreakdownLabel($s['refills_today_by_status']))
                ->descriptionIcon('heroicon-m-arrow-path')
                ->color('primary'),

            Stat::make('Staff Absen Hari Ini', sprintf('%d / %d', $s['staff_clocked_in_today'], $s['staff_total_active']))
                ->description($attendanceRate.'% staff aktif sudah absen')
                ->descriptionIcon('heroicon-m-user-group')
                ->color($attendanceRate >= 80 ? 'success' : ($attendanceRate >= 50 ? 'warning' : 'danger')),

            Stat::make('Selisih Kas Hari Ini', 'Rp '.Number::format($s['variance_today_minor'], locale: 'id'))
                ->description($s['variance_today_minor'] === 0 ? 'Belum ada selisih tercatat' : 'Perlu ditinjau Finance')
                ->descriptionIcon($s['variance_today_minor'] === 0 ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color($s['variance_today_minor'] === 0 ? 'success' : 'danger'),
        ];
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
            array_map(fn ($case) => $case->value, \App\Enums\RefillStatus::cases()),
            array_map(fn ($case) => $case->label(), \App\Enums\RefillStatus::cases()),
        );
    }
}
