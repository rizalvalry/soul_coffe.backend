<?php

namespace App\Filament\Resources\RawMaterials\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class RawMaterialForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kode')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),

                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(255),

                TextInput::make('unit')
                    ->label('Satuan')
                    ->required()
                    ->maxLength(16)
                    ->helperText('Satuan terkecil yang dibeli, mis. g, ml, pcs. Jumlah selalu bilangan bulat pada satuan ini.'),

                TextInput::make('reorder_point')
                    ->label('Titik Pemesanan Ulang')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Boleh dikosongkan bila belum ditentukan.'),

                TextInput::make('sort_order')
                    ->label('Urutan')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->required(),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
            ]);
    }
}
