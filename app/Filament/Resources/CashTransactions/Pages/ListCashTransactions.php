<?php

namespace App\Filament\Resources\CashTransactions\Pages;

use App\Exports\CashTransactionsExport;
use App\Filament\Resources\CashTransactions\CashTransactionResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Maatwebsite\Excel\Facades\Excel;

class ListCashTransactions extends ListRecords
{
    protected static string $resource = CashTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Catat Kas'),
            Action::make('exportExcel')->label('Ekspor Excel')
                ->action(fn () => Excel::download(new CashTransactionsExport($this->getFilteredTableQuery()), 'transaksi-kas.xlsx')),
        ];
    }
}
