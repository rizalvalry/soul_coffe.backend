<?php

namespace App\Filament\Resources\Settlements\Pages;

use App\Filament\Resources\Settlements\SettlementResource;
use Filament\Resources\Pages\ListRecords;

class ListSettlements extends ListRecords
{
    protected static string $resource = SettlementResource::class;

    /** Deposits are taken at the desk on the phone, not created here. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
