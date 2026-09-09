<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Filament\Concerns\RenameableModule;
use App\Services\Access\PermissionMatrix;
use App\Services\Reporting\StockOverviewService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * "Stok Terpusat" — every cup that exists right now, before and after it is divided up.
 *
 * The panel could not answer this before. A barista saw their own kitchen from the phone and each
 * staff member their own cart, so the only way to know the company total was to add up screens by
 * hand. Production plans against that total.
 *
 * Read-only by nature: stock moves by brewing, handing over, refilling and closing out — never by
 * typing a number into a report. Editing here would create a second way to change stock that the
 * append-only ledger (R6) could not explain.
 */
class CentralStock extends Page
{
    use RenameableModule;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Stok Terpusat';

    protected static ?string $title = 'Stok Terpusat';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.central-stock';

    public static function panelModule(): PanelModule
    {
        return PanelModule::CENTRAL_STOCK;
    }

    public static function canAccess(): bool
    {
        return PermissionMatrix::can(Auth::user(), static::panelModule(), 'view');
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return app(StockOverviewService::class)->snapshot();
    }
}
