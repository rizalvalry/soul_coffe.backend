<?php

namespace App\Filament\Resources\CentralKitchens\Pages;

use App\Filament\Resources\CentralKitchens\CentralKitchenResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCentralKitchen extends EditRecord
{
    protected static string $resource = CentralKitchenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
