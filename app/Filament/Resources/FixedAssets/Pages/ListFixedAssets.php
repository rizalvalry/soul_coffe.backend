<?php

namespace App\Filament\Resources\FixedAssets\Pages;

use App\Filament\Resources\FixedAssets\FixedAssetResource;
use App\Models\FixedAssetCategory;
use App\Services\FixedAssetService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ListFixedAssets extends ListRecords
{
    protected static string $resource = FixedAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Catat aset')->visible(fn (): bool => Gate::allows('record-fixed-assets')),
            Action::make('category')->label('Tambah kategori')->visible(fn (): bool => Gate::allows('record-fixed-assets'))
                ->schema([TextInput::make('name')->label('Nama kategori')->required()->maxLength(255)->unique('fixed_asset_categories', 'name')])
                ->action(function (array $data): void {
                    Gate::authorize('record-fixed-assets');
                    FixedAssetCategory::query()->create(['name' => $data['name'], 'is_active' => true]);
                    Notification::make()->success()->title('Kategori aset ditambahkan')->send();
                }),
            Action::make('depreciate')->label('Posting penyusutan bulan ini')
                ->visible(fn (): bool => Gate::allows('post-fixed-asset-depreciation'))
                ->requiresConfirmation()->action(function (): void {
                    $count = app(FixedAssetService::class)->postMonthly(today(), Auth::user());
                    Notification::make()->success()->title("Penyusutan diposting untuk {$count} aset")->send();
                }),
        ];
    }
}
