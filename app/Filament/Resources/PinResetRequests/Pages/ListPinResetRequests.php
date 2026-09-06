<?php

namespace App\Filament\Resources\PinResetRequests\Pages;

use App\Filament\Resources\PinResetRequests\PinResetRequestResource;
use App\Models\PinResetRequest;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListPinResetRequests extends ListRecords
{
    protected static string $resource = PinResetRequestResource::class;

    /** No create action: only the locked-out user can raise one of these. */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Menunggu')
                ->badge(PinResetRequest::query()->pending()->count())
                ->badgeColor('danger')
                ->modifyQueryUsing(fn ($query) => $query->where('status', PinResetRequest::STATUS_PENDING)),
            'resolved' => Tab::make('Selesai')
                ->modifyQueryUsing(fn ($query) => $query->where('status', PinResetRequest::STATUS_RESOLVED)),
            'rejected' => Tab::make('Ditolak')
                ->modifyQueryUsing(fn ($query) => $query->where('status', PinResetRequest::STATUS_REJECTED)),
            'all' => Tab::make('Semua'),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return 'pending';
    }
}
