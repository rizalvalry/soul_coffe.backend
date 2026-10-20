<?php

namespace App\Filament\Resources\ClearingReceipts;

use App\Filament\Concerns\CashFinanceOnly;
use App\Filament\Resources\ClearingReceipts\Pages\CreateClearingReceipt;
use App\Filament\Resources\ClearingReceipts\Pages\ListClearingReceipts;
use App\Models\Account;
use App\Models\ClearingReceipt;
use App\Services\ClearingService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use UnitEnum;

class ClearingReceiptResource extends Resource
{
    use CashFinanceOnly;

    protected static ?string $model = ClearingReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'Dana QRIS/transfer masuk';

    protected static ?string $modelLabel = 'Dana QRIS/transfer masuk';

    protected static ?int $navigationSort = 7;

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('uuid')->default(fn (): string => (string) Str::uuid()),
            DatePicker::make('received_on')->label('Tanggal dana masuk di rekening')->default(today())->required(),
            TextInput::make('amount_minor')->label('Dana masuk (Rp)')->integer()->minValue(1)->maxValue(1000000000000)->required(),
            Select::make('bank_account_id')->label('Rekening tujuan')->options(fn (): array => Account::query()
                ->where('code', '1-1300')->where('is_active', true)->pluck('name', 'id')->all())->required()
                ->default(fn (): ?int => Account::query()->where('code', '1-1300')->value('id')),
            TextInput::make('mdr_minor')->label('Biaya MDR (Rp, opsional)')->integer()->minValue(0)->default(0),
            Select::make('sale_dates')->label('Hari setoran/penjualan yang dicakup (opsional)')->multiple()
                ->options(fn (): array => collect(app(ClearingService::class)->aging()['rows'])
                    ->mapWithKeys(fn (array $row): array => [$row['sale_date'] => $row['sale_date'].' · Rp '.number_format($row['outstanding_minor'], 0, ',', '.')])->all())
                ->helperText('Kosongkan kalau dana ini belum bisa dipasangkan ke hari penjualan.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('received_on', 'desc')->columns([
            TextColumn::make('received_on')->label('Tanggal masuk')->date('d M Y')->sortable(),
            TextColumn::make('amount_minor')->label('Dana masuk')->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.')),
            TextColumn::make('mdr_minor')->label('MDR')->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.')),
            TextColumn::make('bankAccount.name')->label('Rekening'),
            TextColumn::make('recordedBy.name')->label('Dicatat oleh'),
            TextColumn::make('status')->label('Status')->badge(),
        ])->recordActions([
            Action::make('void')->label('Batalkan')->color('danger')->requiresConfirmation()
                ->visible(fn (ClearingReceipt $record): bool => $record->status === 'POSTED')
                ->schema([Textarea::make('reason')->label('Alasan pembatalan')->required()->maxLength(225)])
                ->action(fn (ClearingReceipt $record, array $data) => app(ClearingService::class)
                    ->void(Auth::user(), $record, $data['reason'])),
            Action::make('match')->label('Pasangkan hari')->icon('heroicon-o-link')
                ->visible(fn (ClearingReceipt $record): bool => $record->status === 'POSTED'
                    && $record->amount_minor + $record->mdr_minor > $record->allocations()->sum('amount_minor'))
                ->schema([
                    Hidden::make('uuid')->default(fn (): string => (string) Str::uuid()),
                    Select::make('sale_dates')->label('Hari penjualan')->multiple()->required()
                        ->options(fn (): array => collect(app(ClearingService::class)->aging()['rows'])
                            ->mapWithKeys(fn (array $row): array => [$row['sale_date'] => $row['sale_date'].' · Rp '.number_format($row['outstanding_minor'], 0, ',', '.')])->all()),
                ])
                ->action(fn (ClearingReceipt $record, array $data) => app(ClearingService::class)
                    ->match(Auth::user(), $record, $data)),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListClearingReceipts::route('/'), 'create' => CreateClearingReceipt::route('/create')];
    }
}
