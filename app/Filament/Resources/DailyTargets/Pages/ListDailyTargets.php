<?php

namespace App\Filament\Resources\DailyTargets\Pages;

use App\Filament\Resources\DailyTargets\DailyTargetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDailyTargets extends ListRecords
{
    protected static string $resource = DailyTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
