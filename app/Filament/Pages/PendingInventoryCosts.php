<?php

namespace App\Filament\Pages;

use App\Models\Product;
use App\Services\Menu\MenuLabels;
use App\Services\PendingStockCostService;
use App\Services\Reporting\InventoryAccountingReportService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class PendingInventoryCosts extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Biaya Stok Tertunda';

    protected static ?string $title = 'Biaya Stok Tertunda';

    protected string $view = 'filament.pages.pending-inventory-costs';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public static function canAccess(): bool
    {
        return Gate::allows('revalue-inventory');
    }

    public function mount(): void
    {
        Gate::authorize('revalue-inventory');
    }

    public function pending(): array
    {
        Gate::authorize('revalue-inventory');

        return app(InventoryAccountingReportService::class)->pendingCosts();
    }

    public function revalueProduct(int $productId): void
    {
        Gate::authorize('revalue-inventory');
        $count = app(PendingStockCostService::class)->resolveProduct(Product::query()->findOrFail($productId), Auth::user());
        Notification::make()->success()->title("{$count} gerak stok sudah dinilai ulang")->send();
    }
}
