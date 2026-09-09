<?php

namespace App\Filament\Widgets;

use App\Enums\PanelModule;
use App\Services\Access\PermissionMatrix;
use App\Services\Reporting\DashboardMetricsService;
use Filament\Widgets\LineChartWidget;
use Illuminate\Support\Facades\Auth;

class RevenueTrendWidget extends LineChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::DASHBOARD, 'view');
    }

    public function getHeading(): string
    {
        return 'Tren Pendapatan (14 Hari Terakhir)';
    }

    protected function getData(): array
    {
        $trend = app(DashboardMetricsService::class)->revenueTrend(days: 14);

        return [
            'datasets' => [
                [
                    'label' => 'Pendapatan (Rp)',
                    'data' => $trend->pluck('revenue_minor')->all(),
                    'fill' => 'start',
                    'borderColor' => '#d97706',
                    'backgroundColor' => 'rgba(217, 119, 6, 0.12)',
                    'tension' => 0.35,
                ],
            ],
            'labels' => $trend->map(fn (array $row) => $this->shortDate($row['date']))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    private function shortDate(string $date): string
    {
        return \Illuminate\Support\Carbon::parse($date)->translatedFormat('d M');
    }
}
