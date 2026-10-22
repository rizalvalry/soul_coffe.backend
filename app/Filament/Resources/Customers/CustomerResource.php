<?php

namespace App\Filament\Resources\Customers;

use App\Enums\PanelModule;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Models\Customer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class CustomerResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Pelanggan';

    protected static ?string $modelLabel = 'Pelanggan';

    protected static ?string $pluralModelLabel = 'Pelanggan';

    public static function panelModule(): PanelModule
    {
        return PanelModule::CUSTOMERS;
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListCustomers::route('/')];
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
