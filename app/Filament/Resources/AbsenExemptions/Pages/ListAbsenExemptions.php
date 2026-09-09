<?php

namespace App\Filament\Resources\AbsenExemptions\Pages;

use App\Filament\Resources\AbsenExemptions\AbsenExemptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAbsenExemptions extends ListRecords
{
    protected static string $resource = AbsenExemptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Buat izin absen'),
        ];
    }
}
