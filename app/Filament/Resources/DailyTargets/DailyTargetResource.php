<?php

namespace App\Filament\Resources\DailyTargets;

use App\Enums\PanelModule;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\DailyTargets\Pages\CreateDailyTarget;
use App\Filament\Resources\DailyTargets\Pages\EditDailyTarget;
use App\Filament\Resources\DailyTargets\Pages\ListDailyTargets;
use App\Filament\Resources\DailyTargets\Schemas\DailyTargetForm;
use App\Filament\Resources\DailyTargets\Tables\DailyTargetsTable;
use App\Models\DailyTarget;
use App\Services\Access\PermissionMatrix;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class DailyTargetResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = DailyTarget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Target Harian';

    protected static ?string $modelLabel = 'Target Harian';

    protected static ?string $pluralModelLabel = 'Target Harian';

    protected static ?int $navigationSort = 1;

    protected static bool $shouldRegisterNavigation = false;

    public static function panelModule(): PanelModule
    {
        return PanelModule::DAILY_TARGETS;
    }

    // This archived Flow A editor remains reachable by URL for Administrator only. The
    // RIDER_COMMISSIONS has its own matrix grant, separate from these old stock targets.
    public static function canViewAny(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::DAILY_TARGETS, 'view');
    }

    public static function canCreate(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::DAILY_TARGETS, 'create');
    }

    public static function canEdit(mixed $record): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::DAILY_TARGETS, 'edit');
    }

    public static function canDelete(mixed $record): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::DAILY_TARGETS, 'delete');
    }

    public static function form(Schema $schema): Schema
    {
        return DailyTargetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DailyTargetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDailyTargets::route('/'),
            'create' => CreateDailyTarget::route('/create'),
            'edit' => EditDailyTarget::route('/{record}/edit'),
        ];
    }
}
