<?php

namespace App\Filament\Resources\StaffAssignments\Pages;

use App\Filament\Resources\StaffAssignments\StaffAssignmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStaffAssignments extends ListRecords
{
    protected static string $resource = StaffAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
