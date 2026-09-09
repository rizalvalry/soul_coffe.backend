<?php

namespace App\Filament\Resources\Sales\Pages;

use App\Filament\Resources\Sales\SaleResource;
use Filament\Resources\Pages\ListRecords;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    /** Nothing to create here — sales come from the carts. See SaleResource. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
