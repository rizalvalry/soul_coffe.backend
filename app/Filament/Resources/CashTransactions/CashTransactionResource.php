<?php

namespace App\Filament\Resources\CashTransactions;

use App\Filament\Concerns\CashFinanceOnly;
use App\Filament\Resources\CashTransactions\Pages\CreateCashTransaction;
use App\Filament\Resources\CashTransactions\Pages\ListCashTransactions;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CashAccount;
use App\Models\CashCategory;
use App\Models\CashTransaction;
use App\Models\Location;
use App\Models\OfficeItem;
use App\Models\User;
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
                    $category = CashCategory::query()->find($state);
                    $set('unit_price_minor', $category?->default_unit_price_minor);
                    $set('quantity', null);
                    $set('amount_minor', null);
                    if (CashCategory::query()->whereKey($state)->where('name', 'Setor tunai ke bank')->exists()) {
                        $set('cash_account_id', CashAccount::query()->where('name', 'Kas Setoran')->value('id'));
                    }
                    if (CashCategory::query()->whereKey($state)->value('direction') === 'IN') {
                        $set('office_item_id', null);
                        $set('quantity', null);
                    }
                    if (! CashCategory::query()->join('accounts', 'accounts.id', '=', 'cash_categories.account_id')
                        ->where('cash_categories.id', $state)->where('cash_categories.direction', 'OUT')
                        ->where('accounts.type', 'EXPENSE')->exists()) {
                        $set('cart_id', null);
                    }
                }),
            Select::make('cash_account_id')->label('Akun kas / sumber dana')->options(fn (): array => CashAccount::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()->required(),
            TextInput::make('quantity')->label('Jumlah')->numeric()->rule('integer')->minValue(1)
                ->helperText(fn (Get $get): ?string => CashCategory::query()->whereKey($get('cash_category_id'))->value('unit'))
                ->required(fn (Get $get): bool => self::usesUnitPrice($get))
                ->live()->afterStateUpdated(fn (Get $get, Set $set) => self::calculateAmount($get, $set))
                ->visible(fn (Get $get): bool => self::usesUnitPrice($get)
                    || CashCategory::query()->whereKey($get('cash_category_id'))->value('direction') === 'OUT'),
            TextInput::make('unit_price_minor')->label('Harga satuan (Rp)')->numeric()->rule('integer')->minValue(1)
                ->required(fn (Get $get): bool => self::usesUnitPrice($get))
                ->live()->afterStateUpdated(fn (Get $get, Set $set) => self::calculateAmount($get, $set))
                ->visible(fn (Get $get): bool => self::usesUnitPrice($get)),
            TextInput::make('amount_minor')->label('Nominal (Rp)')->numeric()->rule('integer')->minValue(1)
                ->readOnly(fn (Get $get): bool => self::usesUnitPrice($get))
                ->required(fn (Get $get): bool => ! self::usesUnitPrice($get)),
            Textarea::make('description')->label('Keterangan')->required()->maxLength(255),
            Select::make('cart_id')->label('Beban langsung Coffee Bike (opsional)')
                ->options(fn (): array => Cart::query()->orderBy('code')->pluck('code', 'id')->all())
                ->searchable()->visible(fn (Get $get): bool => CashCategory::query()
                ->join('accounts', 'accounts.id', '=', 'cash_categories.account_id')
                ->where('cash_categories.id', $get('cash_category_id'))
                ->where('cash_categories.direction', 'OUT')->where('accounts.type', 'EXPENSE')->exists()),
            Select::make('employee_id')->label('Karyawan (opsional)')
                ->options(fn (): array => User::query()->whereHas('employeeProfile')->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
            Select::make('location_id')->label('Lokasi berjualan (opsional)')
                ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable(),
            Select::make('office_item_id')->label('Barang kantor (opsional)')
                ->options(fn (): array => OfficeItem::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()->live()->visible(fn (Get $get): bool => CashCategory::query()->whereKey($get('cash_category_id'))->value('direction') === 'OUT')
                ->afterStateUpdated(function ($state, Set $set): void {
                    $categoryId = $state ? OfficeItem::query()->whereKey($state)->value('default_category_id') : null;
                    if ($categoryId) {
                        $set('cash_category_id', $categoryId);
                    }
                }),
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
            TextColumn::make('cart.code')->label('Coffee Bike'),
            TextColumn::make('employee.name')->label('Karyawan'),
            TextColumn::make('location.name')->label('Lokasi'),
            TextColumn::make('unit_price_minor')->label('Harga satuan')
                ->formatStateUsing(fn ($state): string => $state === null ? '-' : 'Rp '.number_format((int) $state, 0, ',', '.')),
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
            SelectFilter::make('employee_id')->label('Karyawan')
                ->options(fn (): array => User::query()->whereHas('employeeProfile')->orderBy('name')->pluck('name', 'id')->all()),
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

    private static function calculateAmount(Get $get, Set $set): void
    {
        $quantity = $get('quantity');
        $price = $get('unit_price_minor');
        $set('amount_minor', is_numeric($quantity) && is_numeric($price) ? (int) $quantity * (int) $price : null);
    }

    private static function usesUnitPrice(Get $get): bool
    {
        $category = CashCategory::query()->find($get('cash_category_id'));

        return $category !== null && ($category->is_daily || $category->default_unit_price_minor !== null);
    }
}
