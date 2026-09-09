<?php

namespace App\Filament\Widgets;

use App\Enums\PanelModule;
use App\Services\Access\PermissionMatrix;
use App\Services\Reporting\SalesActivityService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * "Penjualan per gerobak hari ini" — the dashboard list that was asked for: every cart, at its
 * own location, with what it has sold so far today.
 *
 * A plain view rather than a chart on purpose. The question behind it is operational ("is 0018
 * at Pulomas moving?"), and a bar chart of eight carts answers that worse than eight rows do.
 * The charts above it already carry the trend.
 *
 * Reads SalesActivityService, the same service behind the Aktivitas Staff page, so the dashboard
 * and that page can never disagree about the same day.
 */
class CartSalesTodayWidget extends Widget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.cart-sales-today';

    public static function canView(): bool
    {
        // Each widget checks for itself rather than trusting the page it sits on — see
        // OperationsOverviewWidget.
        return PermissionMatrix::can(Auth::user(), PanelModule::DASHBOARD, 'view');
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(): array
    {
        return app(SalesActivityService::class)->perCart();
    }

    /** @return array{transactions: int, cups: int, revenue: int, flagged: int, carts: int} */
    public function totals(): array
    {
        return app(SalesActivityService::class)->dayTotals();
    }

    /** @return array{area: string|null, hour: int|null, cups: int} */
    public function peak(): array
    {
        return app(SalesActivityService::class)->areaHours()['peak'];
    }
}
