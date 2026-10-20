<?php

namespace App\Filament\Resources\ClearingReceipts\Pages;

use App\Filament\Resources\ClearingReceipts\ClearingReceiptResource;
use App\Services\ClearingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateClearingReceipt extends CreateRecord
{
    protected static string $resource = ClearingReceiptResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(ClearingService::class)->record(Auth::user(), $data);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
