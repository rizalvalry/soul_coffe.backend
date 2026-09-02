<?php

namespace App\Filament\Resources\DailyTargets;

use App\Filament\Resources\DailyTargets\Pages\CreateDailyTarget;
use App\Filament\Resources\DailyTargets\Pages\EditDailyTarget;
use App\Filament\Resources\DailyTargets\Pages\ListDailyTargets;
use App\Filament\Resources\DailyTargets\Schemas\DailyTargetForm;
use App\Filament\Resources\DailyTargets\Tables\DailyTargetsTable;
use App\Models\DailyTarget;
use BackedEnum;
use UnitEnum;
use App\Filament\Concerns\AdministratorOnly;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DailyTargetResource extends Resource
{
    use AdministratorOnly;

    protected static ?string $model = DailyTarget::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Target Harian';

    protected static ?string $modelLabel = 'Target Harian';

    protected static ?string $pluralModelLabel = 'Target Harian';

    protected static ?int $navigationSort = 1;

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
