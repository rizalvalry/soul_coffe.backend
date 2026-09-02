<?php

namespace App\Filament\Resources\DailyTargets\Pages;

use App\Filament\Resources\DailyTargets\DailyTargetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDailyTarget extends EditRecord
{
    protected static string $resource = DailyTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
