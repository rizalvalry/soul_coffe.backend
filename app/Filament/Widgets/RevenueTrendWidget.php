<?php

namespace App\Filament\Widgets;

use App\Enums\PanelModule;
use App\Services\Access\PermissionMatrix;
use App\Services\Reporting\DashboardMetricsService;
use Filament\Widgets\LineChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class RevenueTrendWidget extends LineChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $maxHeight = '260px';

    protected ?string $emptyStateHeading = 'Belum ada pendapatan dalam 7 hari terakhir';

    public static function canView(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::DASHBOARD, 'view');
    }

    public function getHeading(): string
    {
        return 'Tren Pendapatan (7 Hari Terakhir)';
    }

    protected function getData(): array
    {
        $trend = app(DashboardMetricsService::class)->revenueTrend(days: 7);

        if ($trend->every(fn (array $row): bool => $row['revenue_minor'] === 0)) {
            return [];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Pendapatan (Rp)',
                    'data' => $trend->pluck('revenue_minor')->all(),
                    'fill' => 'start',
                    'borderColor' => '#008E94',
                    'backgroundColor' => 'rgba(0, 142, 148, 0.12)',
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

    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['min' => 0, 'ticks' => ['precision' => 0]]]];
    }

    private function shortDate(string $date): string
    {
        return Carbon::parse($date)->translatedFormat('d M');
    }
}
