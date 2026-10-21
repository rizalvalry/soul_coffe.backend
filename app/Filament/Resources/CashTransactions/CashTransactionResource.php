<?php

namespace App\Filament\Resources\CashTransactions;

use App\Filament\Concerns\CashFinanceOnly;
use App\Filament\Resources\CashTransactions\Pages\CreateCashTransaction;
use App\Filament\Resources\CashTransactions\Pages\ListCashTransactions;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashTransaction;
use App\Models\OfficeItem;
use App\Services\CashTransactionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use UnitEnum;

class CashTransactionResource extends Resource
{
    use CashFinanceOnly;

    protected static ?string $model = CashTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'Kas Masuk/Keluar';

    protected static ?string $modelLabel = 'Transaksi Kas';

    protected static ?int $navigationSort = 6;

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
            DatePicker::make('date')->label('Tanggal')->default(today())->required(),
            Select::make('cash_category_id')->label('Kategori')->options(fn (): array => CashCategory::query()->where('is_active', true)->orderBy('direction')->orderBy('name')->get()
                ->mapWithKeys(fn (CashCategory $category): array => [$category->id => ($category->direction === 'IN' ? 'Masuk' : 'Keluar').' · '.$category->name])->all())
                ->searchable()->required()->live()->afterStateUpdated(function ($state, Set $set): void {
                    if (CashCategory::query()->whereKey($state)->where('name', 'Setor tunai ke bank')->exists()) {
                        $set('cash_account_id', CashAccount::query()->where('name', 'Kas Setoran')->value('id'));
                    }
                    if (CashCategory::query()->whereKey($state)->value('direction') === 'IN') {
                        $set('office_item_id', null);
                        $set('quantity', null);
                    }
                }),
            Select::make('cash_account_id')->label('Akun kas / sumber dana')->options(fn (): array => CashAccount::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()->required(),
            TextInput::make('amount_minor')->label('Nominal (Rp)')->numeric()->rule('integer')->minValue(1)->required(),
            Textarea::make('description')->label('Keterangan')->required()->maxLength(255),
            Select::make('office_item_id')->label('Barang kantor (opsional)')
                ->options(fn (): array => OfficeItem::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()->live()->visible(fn (Get $get): bool => CashCategory::query()->whereKey($get('cash_category_id'))->value('direction') === 'OUT')
                ->afterStateUpdated(function ($state, Set $set): void {
                    $categoryId = $state ? OfficeItem::query()->whereKey($state)->value('default_category_id') : null;
                    if ($categoryId) {
                        $set('cash_category_id', $categoryId);
                    }
                }),
            TextInput::make('quantity')->label('Jumlah barang')->numeric()->rule('integer')->minValue(1)
                ->visible(fn (Get $get): bool => CashCategory::query()->whereKey($get('cash_category_id'))->value('direction') === 'OUT'),
            FileUpload::make('evidence_path')->label('Bukti (foto/PDF)')->disk('local')
                ->directory('cash-evidence')->visibility('private')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize(5120),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('date', 'desc')->columns([
            TextColumn::make('date')->label('Tanggal')->date('d M Y')->sortable(),
            TextColumn::make('direction')->label('Arah')->badge()
                ->formatStateUsing(fn (string $state): string => $state === 'IN' ? 'Masuk' : 'Keluar'),
            TextColumn::make('category.name')->label('Kategori'),
            TextColumn::make('cashAccount.name')->label('Akun kas'),
            TextColumn::make('amount_minor')->label('Nominal')
                ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.')),
            TextColumn::make('description')->label('Keterangan')->limit(50),
            TextColumn::make('officeItem.name')->label('Barang'),
            TextColumn::make('quantity')->label('Jumlah'),
            TextColumn::make('status')->label('Status')->badge(),
            TextColumn::make('balance_warning')->label('Pemeriksaan saldo')->badge()
                ->state(fn (CashTransaction $record): string => AuditLog::query()
                    ->where('subject_type', CashTransaction::class)->where('subject_id', $record->id)
                    ->where('action', 'cash_transaction.balance_exceeded')->exists() ? 'Melebihi saldo' : 'Sesuai'),
        ])->filters([
            Filter::make('tanggal')->schema([
                DatePicker::make('from')->label('Dari'), DatePicker::make('until')->label('Sampai'),
            ])->query(fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('date', '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $query, $date): Builder => $query->whereDate('date', '<=', $date))),
            SelectFilter::make('cash_category_id')->label('Kategori')
                ->options(fn (): array => CashCategory::query()->orderBy('name')->pluck('name', 'id')->all()),
        ])->recordActions([
            Action::make('receipt')->label('Unduh bukti')->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (CashTransaction $record): bool => $record->evidence_path !== null)
                ->url(fn (CashTransaction $record): string => route('admin.cash-receipts.download', $record)),
            Action::make('void')->label('Batalkan')->color('danger')->requiresConfirmation()
                ->visible(fn (CashTransaction $record): bool => $record->status === 'POSTED')
                ->schema([Textarea::make('reason')->label('Alasan')->required()->maxLength(225)])
                ->action(fn (CashTransaction $record, array $data) => app(CashTransactionService::class)->void(Auth::user(), $record, $data['reason'])),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCashTransactions::route('/'), 'create' => CreateCashTransaction::route('/create')];
    }
}
