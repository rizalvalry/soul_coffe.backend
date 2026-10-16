<?php

namespace App\Filament\Resources\OfficeItems;

use App\Filament\Concerns\CashFinanceOnly;
use App\Filament\Resources\OfficeItems\Pages\CreateOfficeItem;
use App\Filament\Resources\OfficeItems\Pages\EditOfficeItem;
use App\Filament\Resources\OfficeItems\Pages\ListOfficeItems;
use App\Models\CashCategory;
use App\Models\OfficeItem;
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

class OfficeItemResource extends Resource
{
    use CashFinanceOnly;

    protected static ?string $model = OfficeItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Akuntansi';

    protected static ?string $navigationLabel = 'Barang Kantor';

    protected static ?string $modelLabel = 'Barang Kantor';

    protected static ?int $navigationSort = 9;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nama')->required()->maxLength(255),
            TextInput::make('unit')->label('Satuan')->required()->maxLength(32),
            Select::make('default_category_id')->label('Kategori bawaan')->options(fn (): array => CashCategory::query()->where('direction', 'OUT')->where('is_active', true)
                ->orderBy('name')->pluck('name', 'id')->all())->searchable(),
            Toggle::make('is_active')->label('Aktif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nama')->searchable(),
            TextColumn::make('unit')->label('Satuan'),
            TextColumn::make('defaultCategory.name')->label('Kategori'),
            IconColumn::make('is_active')->label('Aktif')->boolean(),
        ])->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListOfficeItems::route('/'), 'create' => CreateOfficeItem::route('/create'),
            'edit' => EditOfficeItem::route('/{record}/edit')];
    }
}
