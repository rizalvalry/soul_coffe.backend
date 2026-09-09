<?php

namespace App\Filament\Widgets;

use App\Enums\PanelModule;
use App\Services\Access\PermissionMatrix;
use App\Services\Reporting\DashboardMetricsService;
use Filament\Widgets\BarChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class RefillVolumeWidget extends BarChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::DASHBOARD, 'view');
    }

    public function getHeading(): string
    {
        return 'Volume Refill Request (14 Hari Terakhir)';
    }

    protected function getData(): array
    {
        $trend = app(DashboardMetricsService::class)->refillVolumeTrend(days: 14);

        return [
            'datasets' => [
                [
                    'label' => 'Refill Request',
                    'data' => $trend->pluck('count')->all(),
                    'backgroundColor' => '#0f766e',
                ],
            ],
            'labels' => $trend->map(fn (array $row) => Carbon::parse($row['date'])->translatedFormat('d M'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
