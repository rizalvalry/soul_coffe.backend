<?php

namespace App\Filament\Resources\EmployeeProfiles;

use App\Enums\Role;
use App\Filament\Resources\EmployeeProfiles\Pages\EditEmployeeProfile;
use App\Filament\Resources\EmployeeProfiles\Pages\ListEmployeeProfiles;
use App\Filament\Resources\EmployeeProfiles\Schemas\EmployeeProfileForm;
use App\Filament\Resources\EmployeeProfiles\Tables\EmployeeProfilesTable;
use App\Models\EmployeeProfile;
use App\Services\Menu\MenuLabels;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** HR access is fixed by business policy, outside the configurable operational access matrix. */
class EmployeeProfileResource extends Resource
{
    protected static ?string $model = EmployeeProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Karyawan';

    public static function getNavigationLabel(): string
    {
        return MenuLabels::for('employees');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Master Data');
    }

    protected static ?string $modelLabel = 'Karyawan';

    protected static ?string $pluralModelLabel = 'Karyawan';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return in_array(Auth::user()?->role, [Role::ADMINISTRATOR, Role::FINANCE, Role::MANAGEMENT], true);
    }

    public static function canView(mixed $record): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(mixed $record): bool
    {
        if (! static::canViewAny()) {
            return false;
        }

        return $record instanceof EmployeeProfile
            && ($record->user?->role !== Role::MANAGEMENT || Auth::user()?->role === Role::MANAGEMENT);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'defaultCart']);
    }

    public static function form(Schema $schema): Schema
    {
        return EmployeeProfileForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeeProfilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployeeProfiles::route('/'),
            'edit' => EditEmployeeProfile::route('/{record}/edit'),
        ];
    }
}
