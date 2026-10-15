<?php

namespace App\Filament\Resources\EmployeeProfiles\Pages;

use App\Filament\Resources\EmployeeProfiles\EmployeeProfileResource;
use Filament\Resources\Pages\ListRecords;

class ListEmployeeProfiles extends ListRecords
{
    protected static string $resource = EmployeeProfileResource::class;
}
