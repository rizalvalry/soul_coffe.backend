<?php

namespace App\Filament\Resources\RefillRequests\Pages;

use App\Filament\Resources\RefillRequests\RefillRequestResource;
use App\Services\RefillExpiryService;
use Filament\Resources\Pages\ListRecords;

class ListRefillRequests extends ListRecords
{
    protected static string $resource = RefillRequestResource::class;

    public function mount(): void
    {
        parent::mount();
        app(RefillExpiryService::class)->expire();
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
