<?php

namespace App\Filament\Resources\DeliveryIncidents\Pages;

use App\Filament\Resources\DeliveryIncidents\DeliveryIncidentResource;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryIncidents extends ListRecords
{
    protected static string $resource = DeliveryIncidentResource::class;

    /** Nothing to create here — incidents are reported from the road. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
