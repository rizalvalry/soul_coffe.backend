<?php

namespace App\Filament\Resources\CentralKitchens\Pages;

use App\Filament\Resources\CentralKitchens\CentralKitchenResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCentralKitchens extends ListRecords
{
    protected static string $resource = CentralKitchenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
