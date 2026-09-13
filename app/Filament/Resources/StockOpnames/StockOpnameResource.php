<?php

namespace App\Filament\Resources\StockOpnames;

use App\Enums\PanelModule;
use App\Enums\StockOpnameStatus;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\StockOpnames\Pages\ListStockOpnames;
use App\Filament\Resources\StockOpnames\Tables\StockOpnamesTable;
use App\Models\StockOpname;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * "Stock Opname" — reconciling the ledger against a physical count.
 *
 * `MovementType::ADJUSTMENT` existed since the ledger was written and nothing ever posted it —
 * there was no menu, no form, no API that could. A stock ledger with no way to correct itself
 * against a physical count drifts from reality forever, silently, and a genuine shrinkage has no
 * honest way to be recorded.
 *
 * NO ELOQUENT CREATE/EDIT FORM. Entering a count is a small workflow of its own — pick a
 * location, see what the ledger says, type what was actually counted — that does not map onto a
 * single model's columns, so it lives in its own page (StockOpnameEntry) reached from the "Buat
 * Stock Opname" button on the list. This resource governs listing, viewing, and the two decisions
 * a draft count can receive: apply it, or abandon it.
 */
class StockOpnameResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = StockOpname::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Stock Opname';

    protected static ?string $modelLabel = 'Stock Opname';

    protected static ?string $pluralModelLabel = 'Stock Opname';

    protected static ?int $navigationSort = 5;

    public static function panelModule(): PanelModule
    {
        return PanelModule::STOCK_OPNAME;
    }

    public static function table(Table $table): Table
    {
        return StockOpnamesTable::configure($table);
    }

    /**
     * Waiting-for-a-decision count, as a badge — the same pattern DeliveryIncidents uses for its
     * open reports. A draft sitting unapplied for days means the ledger and the shelf disagree
     * for exactly that long.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $draft = StockOpname::query()->where('status', StockOpnameStatus::DRAFT)->count();

        return $draft > 0 ? (string) $draft : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockOpnames::route('/'),
        ];
    }

    /**
     * No Eloquent create form — see the class docblock. The list's "Buat Stock Opname" header
     * button links straight to the entry page instead.
     */
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
