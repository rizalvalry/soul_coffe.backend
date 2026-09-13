<?php

namespace App\Filament\Resources\Productions;

use App\Enums\PanelModule;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\Productions\Pages\ListProductions;
use App\Filament\Resources\Productions\Tables\ProductionsTable;
use App\Models\Production;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * A brew event, read only. There is no decision to make here and nothing to correct in place —
 * a production record is a fact about what happened in the kitchen, backed by append-only ledger
 * rows (PRODUCTION_IN, RECIPE_CONSUME_OUT). More locked down than even StockOpnameResource: not
 * one action button, only a report.
 */
class ProductionResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = Production::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Produksi';

    protected static ?string $modelLabel = 'Produksi';

    protected static ?string $pluralModelLabel = 'Produksi';

    protected static ?int $navigationSort = 7;

    public static function panelModule(): PanelModule
    {
        return PanelModule::PRODUCTION;
    }

    public static function table(Table $table): Table
    {
        return ProductionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProductions::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
