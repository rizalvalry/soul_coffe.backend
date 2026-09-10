<?php

namespace App\Filament\Resources\Settlements;

use App\Enums\PanelModule;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\Settlements\Pages\ListSettlements;
use App\Filament\Resources\Settlements\Tables\SettlementsTable;
use App\Models\Settlement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * "Setoran Harian" — the money each cart handed in, and what happened to the cups left over.
 *
 * The deposit itself is taken on the phone, at the desk, with the staff member standing there.
 * This is where it is read: per day, per cart, with the gap between what the transactions say and
 * what was actually handed over, and the reason somebody typed for it.
 *
 * READ-ONLY, structurally. Editing a reconciliation in the panel would be rewriting it after the
 * fact, with nobody present to disagree — and the cups have already moved through the append-only
 * ledger by then. A mistake is corrected the way every other stock mistake is: with an adjustment
 * that names its author.
 */
class SettlementResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = Settlement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Laporan';

    protected static ?string $navigationLabel = 'Setoran Harian';

    protected static ?string $modelLabel = 'Setoran';

    protected static ?string $pluralModelLabel = 'Setoran Harian';

    protected static ?int $navigationSort = 2;

    public static function panelModule(): PanelModule
    {
        return PanelModule::SETTLEMENTS;
    }

    public static function table(Table $table): Table
    {
        return SettlementsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSettlements::route('/'),
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
