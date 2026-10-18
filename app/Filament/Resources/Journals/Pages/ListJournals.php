<?php

namespace App\Filament\Resources\Journals\Pages;

use App\Filament\Pages\ManualJournal;
use App\Filament\Resources\Journals\JournalResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Gate;

class ListJournals extends ListRecords
{
    protected static string $resource = JournalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('manualJournal')->label('Jurnal Manual')
                ->visible(fn (): bool => Gate::allows('post-manual-journal'))
                ->url(ManualJournal::getUrl()),
        ];
    }
}
