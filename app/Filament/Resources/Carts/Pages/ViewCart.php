<?php

namespace App\Filament\Resources\Carts\Pages;

use App\Filament\Resources\Carts\CartResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCart extends ViewRecord
{
    protected static string $resource = CartResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
