<?php

namespace App\Filament\Resources\Users;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class UserResource extends Resource
{
    use MatrixGoverned {
        canCreate as matrixCanCreate;
        canEdit as matrixCanEdit;
        canDelete as matrixCanDelete;
    }

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Pengguna & Role';

    protected static ?string $modelLabel = 'Pengguna';

    protected static ?string $pluralModelLabel = 'Pengguna';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function panelModule(): PanelModule
    {
        return PanelModule::USERS;
    }

    public static function canEdit(mixed $record): bool
    {
        return static::matrixCanEdit($record)
            && ($record->role !== Role::ADMINISTRATOR || PermissionMatrix::canManagePermissions(Auth::user()))
            && (! $record->is_super_admin || Auth::user()?->is_super_admin);
    }

    public static function canCreate(): bool
    {
        // Creating an account assigns its role template, so it is an access change.
        return static::matrixCanCreate() && PermissionMatrix::canManagePermissions(Auth::user());
    }

    public static function canDelete(mixed $record): bool
    {
        return static::matrixCanDelete($record)
            && ($record instanceof User
                && ($record->role !== Role::ADMINISTRATOR || PermissionMatrix::canManagePermissions(Auth::user()))
                && ! $record->employeeProfile()->exists()
                && ! $record->is_super_admin);
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
