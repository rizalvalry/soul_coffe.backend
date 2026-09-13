<?php

namespace App\Filament\Resources\DirectSales;

use App\Enums\PanelModule;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\DirectSales\Pages\CreateDirectSale;
use App\Filament\Resources\DirectSales\Pages\ListDirectSales;
use App\Filament\Resources\DirectSales\Schemas\DirectSaleForm;
use App\Filament\Resources\DirectSales\Tables\DirectSalesTable;
use App\Models\DirectSale;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * "Penjualan Langsung Kantor" — a walk-in buyer paying at the kitchen/office, recorded by Finance
 * or Administrator straight into a cart's stock. See DirectSaleService and the migration for why
 * this is a separate table and service from the gerobak Sale.
 *
 * No free-form edit or delete, same principle as SaleResource: the one legitimate correction is a
 * VOID (DirectSalesTable's "Batalkan" action, gated on the matrix's `edit` ability), which
 * reverses the cups through the ledger rather than rewriting the row.
 */
class DirectSaleResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = DirectSale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Penjualan Langsung Kantor';

    protected static ?string $modelLabel = 'Penjualan Langsung';

    protected static ?string $pluralModelLabel = 'Penjualan Langsung Kantor';

    protected static ?int $navigationSort = 5;

    public static function panelModule(): PanelModule
    {
        return PanelModule::DIRECT_SALES;
    }

    public static function form(Schema $schema): Schema
    {
        return DirectSaleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DirectSalesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDirectSales::route('/'),
            'create' => CreateDirectSale::route('/create'),
        ];
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
