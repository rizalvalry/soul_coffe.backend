<?php

namespace App\Filament\Resources\StaffAssignments;

use App\Filament\Resources\StaffAssignments\Pages\CreateStaffAssignment;
use App\Filament\Resources\StaffAssignments\Pages\EditStaffAssignment;
use App\Filament\Resources\StaffAssignments\Pages\ListStaffAssignments;
use App\Filament\Resources\StaffAssignments\Schemas\StaffAssignmentForm;
use App\Filament\Resources\StaffAssignments\Tables\StaffAssignmentsTable;
use App\Models\StaffAssignment;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class StaffAssignmentResource extends Resource
{
    protected static ?string $model = StaffAssignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Operasional';

    protected static ?string $navigationLabel = 'Penugasan Staff';

    protected static ?string $modelLabel = 'Penugasan';

    protected static ?string $pluralModelLabel = 'Penugasan';

    protected static ?int $navigationSort = 2;

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
            'create' => CreateStaffAssignment::route('/create'),
            'edit' => EditStaffAssignment::route('/{record}/edit'),
        ];
    }
}
