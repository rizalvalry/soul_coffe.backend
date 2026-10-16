<?php

namespace App\Filament\Widgets;

use App\Enums\PanelModule;
use App\Services\Access\PermissionMatrix;
use App\Services\Reporting\DashboardMetricsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

class OfficeDirectSalesWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    protected int|array|null $columns = 1;

    public static function canView(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::DASHBOARD, 'view');
    }

    protected function getStats(): array
    {
        $direct = app(DashboardMetricsService::class)->directSalesToday();

        return [
            Stat::make('Penjualan Langsung Kantor', 'Rp '.Number::format($direct['total_amount_minor'], locale: 'id'))
                ->description(sprintf('%d transaksi · %d cups', $direct['transaction_count'], $direct['total_qty']))
                ->extraAttributes(['class' => 'sc-office-stat']),
        ];
    }
}
