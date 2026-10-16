<?php

namespace App\Filament\Widgets;

use App\Enums\PanelModule;
use App\Services\Access\PermissionMatrix;
use App\Services\Reporting\DashboardMetricsService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
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
        $average = $direct['transaction_count'] > 0
            ? 'Rp '.Number::format(intdiv($direct['total_amount_minor'], $direct['transaction_count']), locale: 'id')
            : '—';
        $summary = sprintf('%d transaksi · %d cups', $direct['transaction_count'], $direct['total_qty']);

        return [
            Stat::make('Penjualan Langsung Kantor', 'Rp '.Number::format($direct['total_amount_minor'], locale: 'id'))
                ->description(new HtmlString('<span class="sc-office-summary">'.e($summary).'</span>'
                    .'<span class="sc-office-detail"><span><small>Rata-rata transaksi</small><strong>'.e($average).'</strong></span>'
                    .'<span><small>Produk terlaris</small><strong>—</strong></span></span>'))
                ->extraAttributes(['class' => 'sc-office-stat']),
        ];
    }
}
