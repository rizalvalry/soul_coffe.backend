<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Services\Reporting\DashboardMetricsService;
use Filament\Widgets\LineChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class AttendanceRateWidget extends LineChartWidget
{
    protected static ?int $sort = 5;

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return Auth::user()?->role === Role::ADMINISTRATOR;
    }

    public function getHeading(): string
    {
        return 'Tingkat Kehadiran Staff (14 Hari Terakhir)';
    }

    protected function getData(): array
    {
        $trend = app(DashboardMetricsService::class)->attendanceRateTrend(days: 14);

        return [
            'datasets' => [
                [
                    'label' => 'Kehadiran (%)',
                    'data' => $trend->pluck('rate')->all(),
                    'borderColor' => '#7c3aed',
                    'backgroundColor' => 'rgba(124, 58, 237, 0.12)',
                    'fill' => 'start',
                    'tension' => 0.35,
                ],
            ],
            'labels' => $trend->map(fn (array $row) => Carbon::parse($row['date'])->translatedFormat('d M'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        // A percentage axis pinned to 0–100 reads at a glance; letting Chart.js auto-scale to
        // "whatever the data happens to be" would make a 90% day and a 40% day look equally full.
        return [
            'scales' => [
                'y' => ['min' => 0, 'max' => 100],
            ],
        ];
    }
}
