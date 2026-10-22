<?php

namespace App\Filament\Resources\UserDevices\Pages;

use App\Filament\Resources\DeviceLoginAttempts\DeviceLoginAttemptResource;
use App\Filament\Resources\UserDevices\UserDeviceResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListUserDevices extends ListRecords
{
    protected static string $resource = UserDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('all_login_history')->label('Riwayat semua login')
            ->url(DeviceLoginAttemptResource::getUrl('index'))];
    }
}
