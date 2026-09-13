<?php

namespace App\Filament\Resources\Recipes\Schemas;

use App\Models\Product;
use App\Models\RawMaterial;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RecipeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Produk')
                    ->options(fn (): array => Product::query()
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required(),

                Repeater::make('lines')
                    ->label('Bahan Baku')
                    ->schema([
                        Select::make('raw_material_id')
                            ->label('Bahan Baku')
                            ->options(fn (): array => RawMaterial::query()
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required(),

                        TextInput::make('qty_per_unit')
                            ->label('Jumlah per Unit')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText('Dalam satuan dasar bahan baku, per 1 unit produk jadi.'),
                    ])
                    ->columns(2)
                    ->minItems(1)
                    ->required()
                    ->addActionLabel('Tambah Bahan Baku')
                    ->columnSpanFull(),
            ]);
    }
}
