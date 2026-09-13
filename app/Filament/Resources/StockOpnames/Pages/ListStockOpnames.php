<?php

namespace App\Filament\Resources\StockOpnames\Pages;

use App\Enums\PanelModule;
use App\Filament\Pages\StockOpnameEntry;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Services\Access\PermissionMatrix;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListStockOpnames extends ListRecords
{
    protected static string $resource = StockOpnameResource::class;

    /**
     * Links to the dedicated count-entry page instead of the default `CreateAction`, since this
     * resource has no Eloquent create form at all — see StockOpnameResource's docblock.
     *
     * Visibility checks the matrix's `create` ability directly rather than
     * `StockOpnameResource::canCreate()`, which is hardcoded false on purpose (there is no
     * Eloquent form for it to gate).
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Buat Stock Opname')
                ->icon('heroicon-o-plus')
                ->url(StockOpnameEntry::getUrl())
                ->visible(fn (): bool => PermissionMatrix::can(Auth::user(), PanelModule::STOCK_OPNAME, 'create')),
        ];
    }
}
