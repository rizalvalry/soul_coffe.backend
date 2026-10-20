<?php

namespace App\Filament\Resources\FixedAssets\Pages;

use App\Filament\Resources\FixedAssets\FixedAssetResource;
use App\Services\FixedAssetService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateFixedAsset extends CreateRecord
{
    protected static string $resource = FixedAssetResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(FixedAssetService::class)->acquire($data, Auth::user());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
