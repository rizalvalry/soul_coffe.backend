<?php

namespace App\Filament\Resources\Sales;

use App\Enums\PanelModule;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Resources\Sales\Schemas\SaleInfolist;
use App\Filament\Resources\Sales\Tables\SalesTable;
use App\Models\Sale;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * "Penjualan Gerobak" — every transaction a staff member recorded, per cart and per area.
 *
 * READ-ONLY, and structurally so (see the three refusals at the bottom). A sale moved real cups
 * out of a real cart through the append-only ledger; editing the row here would leave the ledger
 * saying one thing and this table another, with no record of who changed it. A genuine mistake
 * is corrected the way every other stock mistake is — with an adjustment that names its author.
 *
 * The flagged filter is the working queue behind the suspect notification: an Administrator or
 * Finance user opens the notification, lands here, and sees the transaction in the context of
 * that cart's whole day rather than as an isolated accusation.
 */
class SaleResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = Sale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Penjualan Gerobak';

    protected static ?string $modelLabel = 'Penjualan';

    protected static ?string $pluralModelLabel = 'Penjualan Gerobak';

    protected static ?int $navigationSort = 4;

    public static function panelModule(): PanelModule
    {
        return PanelModule::SALES;
    }

    public static function infolist(Schema $schema): Schema
    {
        return SaleInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SalesTable::configure($table);
    }

    /**
     * Today's flagged transactions, as a badge on the menu item.
     *
     * Scoped to today deliberately: a permanent count of every flag ever raised would become a
     * number nobody looks at, which is the failure mode this badge exists to avoid.
     */
    public static function getNavigationBadge(): ?string
    {
        $flagged = Sale::query()
            ->where('is_suspect', true)
            ->whereDate('operating_date', now()->toDateString())
            ->count();

        return $flagged > 0 ? (string) $flagged : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSales::route('/'),
            'view' => ViewSale::route('/{record}'),
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
