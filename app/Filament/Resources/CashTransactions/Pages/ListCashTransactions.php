<?php

namespace App\Filament\Resources\CashTransactions\Pages;

use App\Exports\CashTransactionsExport;
use App\Filament\Pages\OperatingExpenseMatrix;
use App\Filament\Resources\CashTransactions\CashTransactionResource;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\Location;
use App\Models\User;
use App\Services\CashTransactionService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class ListCashTransactions extends ListRecords
{
    protected static string $resource = CashTransactionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Catat Kas'),
            Action::make('copyYesterday')->label('Salin dari kemarin')
                ->visible(fn (): bool => CashTransactionResource::canCreate())
                ->modalDescription('Cek dan koreksi jumlah serta harga. Transaksi baru tercatat setelah kamu klik Simpan semua.')
                ->modalSubmitActionLabel('Simpan semua')
                ->schema([
                    Hidden::make('request_key')->default(fn (): string => (string) Str::uuid()),
                    Repeater::make('rows')->label('Draft biaya harian')
                        ->default(fn (): array => app(CashTransactionService::class)->draftFromYesterday(Auth::user()))
                        ->addable(false)->reorderable(false)->minItems(1)
                        ->schema([
                            Select::make('cash_category_id')->label('Kategori')->required()
                                ->options(fn (): array => CashCategory::query()->where('is_daily', true)->where('is_active', true)
                                    ->orderBy('name')->pluck('name', 'id')->all())
                                ->live()->afterStateUpdated(fn ($state, Set $set) => $set('unit_price_minor',
                                    CashCategory::query()->whereKey($state)->value('default_unit_price_minor'))),
                            Select::make('cash_account_id')->label('Akun kas')->required()
                                ->options(fn (): array => CashAccount::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
                            TextInput::make('quantity')->label('Jumlah')->numeric()->rule('integer')->minValue(1)->required(),
                            TextInput::make('unit_price_minor')->label('Harga satuan (Rp)')->numeric()->rule('integer')->minValue(1)->required(),
                            Select::make('employee_id')->label('Karyawan')->searchable()
                                ->options(fn (): array => User::query()->whereHas('employeeProfile')->orderBy('name')->pluck('name', 'id')->all()),
                            Select::make('location_id')->label('Lokasi')->searchable()
                                ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all()),
                            Textarea::make('description')->label('Keterangan')->required()->maxLength(255),
                            Hidden::make('cart_id'),
                            Hidden::make('office_item_id'),
                        ])->columns(2),
                ])
                ->action(function (array $data): void {
                    app(CashTransactionService::class)->confirmDailyDraft(Auth::user(), $data['request_key'], $data['rows']);
                    Notification::make()->title('Biaya harian sudah disimpan.')->success()->send();
                }),
            Action::make('expenseMatrix')->label('Rekap biaya harian')
                ->url(fn (): string => OperatingExpenseMatrix::getUrl()),
            Action::make('exportExcel')->label('Ekspor Excel')
                ->action(fn () => Excel::download(new CashTransactionsExport($this->getFilteredTableQuery()), 'transaksi-kas.xlsx')),
        ];
    }
}
