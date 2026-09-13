<?php

namespace App\Filament\Resources\DirectSales\Pages;

use App\Filament\Resources\DirectSales\DirectSaleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDirectSales extends ListRecords
{
    protected static string $resource = DirectSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Catat Penjualan'),
        ];
    }
}
