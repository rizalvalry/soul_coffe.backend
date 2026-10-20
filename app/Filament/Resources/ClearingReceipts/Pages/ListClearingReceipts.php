<?php

namespace App\Filament\Resources\ClearingReceipts\Pages;

use App\Filament\Resources\ClearingReceipts\ClearingReceiptResource;
use App\Services\ClearingService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ListClearingReceipts extends ListRecords
{
    protected static string $resource = ClearingReceiptResource::class;

    public function aging(): array
    {
        return app(ClearingService::class)->aging();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.clearing-receipts.aging'),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Catat dana masuk')];
    }
}
