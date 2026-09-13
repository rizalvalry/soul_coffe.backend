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
 * "Penjualan Gerobak" — every transaction a rider recorded, per cart and per area.
 *
 * No free-form create, edit or delete (see the three refusals at the bottom) — a sale moved real
 * cups through the append-only ledger, and editing the row directly would leave the ledger saying
 * one thing and this table another, with no record of who changed it. The one legitimate
 * correction is a VOID (SalesTable's "Batalkan" action, gated on the matrix's `edit` ability),
 * which reverses the cups through the ledger rather than rewriting the row.
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
            // Voided means it was already looked at and undone.
            ->whereNull('voided_at')
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
