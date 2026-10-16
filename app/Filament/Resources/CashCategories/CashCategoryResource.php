<?php

namespace App\Filament\Resources\CashCategories;

use App\Filament\Concerns\CashFinanceOnly;
use App\Filament\Resources\CashCategories\Pages\CreateCashCategory;
use App\Filament\Resources\CashCategories\Pages\EditCashCategory;
use App\Filament\Resources\CashCategories\Pages\ListCashCategories;
use App\Models\Account;
use App\Models\CashCategory;
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

class CashCategoryResource extends Resource
{
    use CashFinanceOnly;

    protected static ?string $model = CashCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'Kategori Kas';

    protected static ?string $modelLabel = 'Kategori Kas';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nama')->required()->maxLength(255),
            Select::make('direction')->label('Arah')->options(['IN' => 'Masuk', 'OUT' => 'Keluar'])->required(),
            Select::make('account_id')->label('Akun kategori')
                ->options(fn (): array => Account::query()->where('is_active', true)
                    ->whereIn('type', ['EXPENSE', 'ASSET', 'REVENUE', 'EQUITY'])
                    ->orderBy('code')->get()->mapWithKeys(fn (Account $account): array => [$account->id => $account->code.' '.$account->name])->all())
                ->searchable()->required(),
            Toggle::make('is_active')->label('Aktif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nama')->searchable(),
            TextColumn::make('direction')->label('Arah')->badge(),
            TextColumn::make('account.code')->label('Akun'),
            IconColumn::make('is_active')->label('Aktif')->boolean(),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCashCategories::route('/'), 'create' => CreateCashCategory::route('/create'),
            'edit' => EditCashCategory::route('/{record}/edit')];
    }
}
