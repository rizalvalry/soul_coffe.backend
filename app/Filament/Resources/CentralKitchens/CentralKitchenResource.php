<?php

namespace App\Filament\Resources\CentralKitchens;

use App\Filament\Resources\CentralKitchens\Pages\CreateCentralKitchen;
use App\Filament\Resources\CentralKitchens\Pages\EditCentralKitchen;
use App\Filament\Resources\CentralKitchens\Pages\ListCentralKitchens;
use App\Filament\Resources\CentralKitchens\Schemas\CentralKitchenForm;
use App\Filament\Resources\CentralKitchens\Tables\CentralKitchensTable;
use App\Models\CentralKitchen;
use BackedEnum;
use UnitEnum;
use App\Filament\Concerns\AdministratorOnly;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CentralKitchenResource extends Resource
{
    use AdministratorOnly;

    protected static ?string $model = CentralKitchen::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Dapur Pusat';

    protected static ?string $modelLabel = 'Dapur Pusat';

    protected static ?string $pluralModelLabel = 'Dapur Pusat';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return CentralKitchenForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CentralKitchensTable::configure($table);
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
            'index' => ListCentralKitchens::route('/'),
            'create' => CreateCentralKitchen::route('/create'),
            'edit' => EditCentralKitchen::route('/{record}/edit'),
        ];
    }
}
