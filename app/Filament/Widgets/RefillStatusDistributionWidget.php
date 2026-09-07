<?php

namespace App\Filament\Widgets;

use App\Enums\Role;
use App\Enums\RefillStatus;
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
    protected static ?int $sort = 4;

    protected ?string $maxHeight = '260px';

    public static function canView(): bool
    {
        return Auth::user()?->role === Role::ADMINISTRATOR;
    }

    public function getHeading(): string
    {
        return 'Distribusi Status Refill (30 Hari Terakhir)';
    }

    protected function getData(): array
    {
        $counts = app(DashboardMetricsService::class)->refillStatusDistribution(days: 30);

        $palette = [
            'SUBMITTED' => '#f59e0b',
            'APPROVED' => '#3b82f6',
            'PREPARING' => '#8b5cf6',
            'READY_TO_PICK' => '#06b6d4',
            'PICKED_UP' => '#0ea5e9',
            'DELIVERED' => '#22c55e',
            'CLOSED' => '#16a34a',
            'REJECTED' => '#ef4444',
            'CANCELLED' => '#94a3b8',
            'EXPIRED' => '#64748b',
        ];

        return [
            'datasets' => [
                [
                    'data' => array_values($counts),
                    'backgroundColor' => array_map(fn (string $status) => $palette[$status] ?? '#94a3b8', array_keys($counts)),
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
