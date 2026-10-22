<?php

namespace App\Filament\Resources\DeviceLoginAttempts\Pages;

use App\Filament\Resources\DeviceLoginAttempts\DeviceLoginAttemptResource;
use Filament\Resources\Pages\ListRecords;

class ListDeviceLoginAttempts extends ListRecords
{
    protected static string $resource = DeviceLoginAttemptResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
