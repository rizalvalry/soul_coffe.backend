<?php

namespace App\Filament\Resources\CashAccounts;

use App\Filament\Concerns\CashFinanceOnly;
use App\Filament\Resources\CashAccounts\Pages\CreateCashAccount;
use App\Filament\Resources\CashAccounts\Pages\EditCashAccount;
use App\Filament\Resources\CashAccounts\Pages\ListCashAccounts;
use App\Models\Account;
use App\Models\CashAccount;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class CashAccountResource extends Resource
{
    use CashFinanceOnly;

    protected static ?string $model = CashAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'Akun Kas';

    protected static ?string $modelLabel = 'Akun Kas';

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nama')->required()->maxLength(255)->unique(ignoreRecord: true),
            Select::make('account_id')->label('Akun aset')
                ->options(fn (): array => Account::query()->where('type', 'ASSET')->where('is_active', true)
                    ->orderBy('code')->get()->mapWithKeys(fn (Account $account): array => [$account->id => $account->code.' '.$account->name])->all())
                ->searchable()->required()->unique(ignoreRecord: true),
            Toggle::make('is_active')->label('Aktif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nama')->searchable(),
            TextColumn::make('account.code')->label('Akun aset'),
            TextColumn::make('balance_minor')->label('Saldo')->state(fn (CashAccount $record): int => $record->balanceMinor())
                ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.')),
            IconColumn::make('is_active')->label('Aktif')->boolean(),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCashAccounts::route('/'), 'create' => CreateCashAccount::route('/create'),
            'edit' => EditCashAccount::route('/{record}/edit')];
    }
}
