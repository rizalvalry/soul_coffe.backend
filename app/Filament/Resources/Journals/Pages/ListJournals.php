<?php

namespace App\Filament\Resources\Journals\Pages;

use App\Filament\Resources\Journals\JournalResource;
use Filament\Resources\Pages\ListRecords;

class ListJournals extends ListRecords
{
    protected static string $resource = JournalResource::class;
}
