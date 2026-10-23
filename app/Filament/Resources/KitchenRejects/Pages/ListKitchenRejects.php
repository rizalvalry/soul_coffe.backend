<?php

namespace App\Filament\Resources\KitchenRejects\Pages;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Exports\KitchenRejectExport;
use App\Filament\Resources\KitchenRejects\KitchenRejectResource;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Services\Access\PermissionMatrix;
use App\Services\KitchenRejectService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class ListKitchenRejects extends ListRecords
{
    protected static string $resource = KitchenRejectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setLimit')->label('Atur batas harian')
                ->visible(fn (): bool => PermissionMatrix::can(Auth::user(), PanelModule::KITCHEN_REJECT, 'edit'))
                ->schema([
                    Select::make('kitchen_id')->label('Dapur')->options(fn (): array => CentralKitchen::query()
                        ->where('is_active', true)
                        ->when(Auth::user()?->role === Role::BARISTA,
                            fn ($query) => $query->whereKey(Auth::user()->kitchen_id))
                        ->pluck('name', 'id')->all())->required(),
                    Select::make('product_id')->label('Produk')->options(fn (): array => Product::query()
                        ->operational()->pluck('name', 'id')->all())->required(),
                    TextInput::make('daily_limit')->label('Batas cups per hari')->numeric()->integer()
                        ->minValue(0)->maxValue(9999)->required()->default(5),
                ])->action(function (array $data): void {
                    app(KitchenRejectService::class)->setLimit(Auth::user(), (int) $data['kitchen_id'],
                        (int) $data['product_id'], (int) $data['daily_limit']);
                    Notification::make()->success()->title('Batas harian tersimpan')->send();
                }),
            Action::make('exportExcel')->label('Ekspor Excel')
                ->action(function () {
                    abort_unless(PermissionMatrix::can(Auth::user(), PanelModule::KITCHEN_REJECT, 'view'), 403);

                    return Excel::download(new KitchenRejectExport($this->getFilteredTableQuery(),
                        KitchenRejectResource::maySeeCost()), 'reject-dapur.xlsx');
                }),
        ];
    }
}
