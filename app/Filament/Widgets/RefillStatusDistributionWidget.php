<?php

namespace App\Filament\Widgets;

use App\Enums\PanelModule;
use App\Enums\RefillStatus;
use App\Services\Access\PermissionMatrix;
use App\Services\Reporting\DashboardMetricsService;
use Filament\Widgets\DoughnutChartWidget;
use Illuminate\Support\Facades\Auth;

/**
 * Where the last 30 days of refill requests actually ended up — a wall of CLOSED with a thin
 * sliver of REJECTED is a healthy operation; a fat wedge of SUBMITTED or PREPARING is a bottleneck
 * an owner needs to see without reading a table.
 */
class RefillStatusDistributionWidget extends DoughnutChartWidget
{
    protected static ?int $sort = 5;

    protected ?string $maxHeight = '260px';

    protected ?string $emptyStateHeading = 'Belum ada refill dalam 30 hari terakhir';

    public static function canView(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::DASHBOARD, 'view');
    }

    public function getHeading(): string
    {
        return 'Distribusi Status Refill (30 Hari Terakhir)';
    }

    protected function getData(): array
    {
        $counts = app(DashboardMetricsService::class)->refillStatusDistribution(days: 30);

        if ($counts === []) {
            return [];
        }

        $palette = [
            'SUBMITTED' => '#8A5A0F',
            'APPROVED' => '#4DBFC4',
            'PREPARING' => '#8CD6D9',
            'READY_TO_PICK' => '#B3E3E6',
            'PICKED_UP' => '#008E94',
            'DELIVERED' => '#5FB58C',
            'CLOSED' => '#007277',
            'REJECTED' => '#A63D3D',
            'CANCELLED' => '#587272',
            'EXPIRED' => '#445C5C',
        ];

        return [
            'datasets' => [
                [
                    'data' => array_values($counts),
                    'backgroundColor' => array_map(fn (string $status) => $palette[$status] ?? '#587272', array_keys($counts)),
                ],
            ],
            'labels' => array_map(
                fn (string $status) => RefillStatus::from($status)->label(),
                array_keys($counts),
            ),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
