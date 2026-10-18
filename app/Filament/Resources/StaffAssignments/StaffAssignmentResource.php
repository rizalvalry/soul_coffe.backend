<?php

namespace App\Filament\Resources\StaffAssignments;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\StaffAssignments\Pages\ListStaffAssignments;
use App\Filament\Resources\StaffAssignments\Schemas\StaffAssignmentForm;
use App\Filament\Resources\StaffAssignments\Tables\StaffAssignmentsTable;
use App\Models\StaffAssignment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class StaffAssignmentResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = StaffAssignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Penugasan Rider';

    protected static ?string $modelLabel = 'Penugasan';

    protected static ?string $pluralModelLabel = 'Penugasan';

    protected static ?int $navigationSort = 2;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->role === Role::ADMINISTRATOR;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(mixed $record): bool
    {
        return false;
    }

    public static function canDelete(mixed $record): bool
    {
        return false;
    }

    public static function panelModule(): PanelModule
    {
        return PanelModule::STAFF_ASSIGNMENTS;
    }

    public static function form(Schema $schema): Schema
    {
        return StaffAssignmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StaffAssignmentsTable::configure($table);
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
            'index' => ListStaffAssignments::route('/'),
        ];
    }
}
