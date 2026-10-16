<?php

namespace App\Filament\Resources\OfficeItems\Pages;

use App\Filament\Resources\OfficeItems\OfficeItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOfficeItems extends ListRecords
{
    protected static string $resource = OfficeItemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
