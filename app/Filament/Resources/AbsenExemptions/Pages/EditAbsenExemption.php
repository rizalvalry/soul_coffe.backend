<?php

namespace App\Filament\Resources\AbsenExemptions\Pages;

use App\Filament\Resources\AbsenExemptions\AbsenExemptionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAbsenExemption extends EditRecord
{
    protected static string $resource = AbsenExemptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Cabut izin'),
        ];
    }
}
