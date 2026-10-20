<?php

namespace App\Filament\Resources\FixedAssets;

use App\Filament\Resources\FixedAssets\Pages\CreateFixedAsset;
use App\Filament\Resources\FixedAssets\Pages\ListFixedAssets;
use App\Models\Account;
use App\Models\Cart;
use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Services\FixedAssetService;
use App\Services\Menu\MenuLabels;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

class FixedAssetResource extends Resource
{
    protected static ?string $model = FixedAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?int $navigationSort = 8;

    protected static ?string $navigationLabel = 'Aset Tetap';

    protected static ?string $modelLabel = 'Aset Tetap';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Akuntansi');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('view-fixed-assets');
    }

    public static function canCreate(): bool
    {
        return Gate::allows('record-fixed-assets');
    }

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
            TextInput::make('name')->label('Nama aset')->required()->maxLength(255),
            Select::make('category_id')->label('Kategori')->options(fn (): array => FixedAssetCategory::query()
                ->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())->required()->searchable(),
            DatePicker::make('acquired_on')->label('Tanggal perolehan')->required(),
            TextInput::make('cost_minor')->label('Harga perolehan (Rp)')->numeric()->rule('integer')->minValue(1)->required(),
            TextInput::make('useful_life_months')->label('Umur manfaat (bulan)')->numeric()->rule('integer')->minValue(1)->required(),
            TextInput::make('residual_minor')->label('Nilai sisa (Rp)')->numeric()->rule('integer')->minValue(0)->default(0)->required(),
            TextInput::make('location')->label('Lokasi (opsional)')->maxLength(255),
            Select::make('cart_id')->label('Coffee Bike (opsional)')->options(fn (): array => Cart::query()->orderBy('code')->pluck('code', 'id')->all())->searchable(),
            Select::make('acquisition_mode')->label('Cara perolehan')->options([
                'CASH' => 'Kas / bank', 'DEBT' => 'Hutang supplier', 'OPENING' => 'Aset saldo awal',
            ])->required()->live(),
            Select::make('funding_account_id')->label('Akun sumber dana / hutang')
                ->options(fn (): array => Account::query()->whereIn('code', ['1-1100', '1-1200', '1-1300', '2-1400'])
                    ->where('is_active', true)->orderBy('code')->get()->mapWithKeys(
                        fn (Account $account): array => [$account->id => $account->code.' '.$account->name],
                    )->all())
                ->visible(fn ($get): bool => $get('acquisition_mode') !== 'OPENING')
                ->required(fn ($get): bool => $get('acquisition_mode') !== 'OPENING'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['category', 'cart']))
            ->defaultSort('acquired_on', 'desc')->columns([
                TextColumn::make('name')->label('Aset')->searchable(),
                TextColumn::make('category.name')->label('Kategori'),
                TextColumn::make('acquired_on')->label('Perolehan')->date('d M Y'),
                TextColumn::make('cost_minor')->label('Harga (Rp)')->formatStateUsing(fn (int $state): string => number_format($state, 0, ',', '.')),
                TextColumn::make('useful_life_months')->label('Umur (bulan)'),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'ACTIVE' ? 'Aktif' : 'Dilepas'),
            ])->recordActions([
                Action::make('dispose')->label('Lepas aset')->visible(fn (FixedAsset $record): bool => $record->status === 'ACTIVE' && Gate::allows('record-fixed-assets'))
                    ->schema([
                        DatePicker::make('disposed_on')->label('Tanggal pelepasan')->default(today())->required(),
                        TextInput::make('proceeds_minor')->label('Hasil pelepasan (Rp)')->numeric()->rule('integer')->minValue(0)->default(0)->required(),
                        Select::make('cash_account_id')->label('Akun kas / bank penerimaan')
                            ->options(fn (): array => Account::query()->whereIn('code', ['1-1100', '1-1200', '1-1300'])
                                ->where('is_active', true)->pluck('name', 'id')->all()),
                    ])->action(function (FixedAsset $record, array $data): void {
                        app(FixedAssetService::class)->dispose($record, $data['disposed_on'], (int) $data['proceeds_minor'],
                            isset($data['cash_account_id']) ? (int) $data['cash_account_id'] : null, Auth::user());
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListFixedAssets::route('/'), 'create' => CreateFixedAsset::route('/create')];
    }
}
