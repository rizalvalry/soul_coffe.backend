<?php

namespace App\Filament\Resources\CashCategories\Pages;

use App\Filament\Resources\CashCategories\CashCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCashCategories extends ListRecords
{
    protected static string $resource = CashCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
