<?php

namespace App\Filament\Widgets;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Services\Access\PermissionMatrix;
use App\Services\StockReconciliationService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

class StockIntegrityWidget extends Widget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.stock-integrity';

    public static function canView(): bool
    {
        return Auth::user()?->role === Role::FINANCE
            && PermissionMatrix::can(Auth::user(), PanelModule::DASHBOARD, 'view');
    }

    /** @return array<string, mixed>|null */
    public function reconciliation(): ?array
    {
        return app(StockReconciliationService::class)->latestResult();
    }
}
