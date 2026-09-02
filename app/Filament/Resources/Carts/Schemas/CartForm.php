<?php

namespace App\Filament\Resources\Carts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CartForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Kode Sepeda')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('Kode dari formulir kertas, mis. 0018.'),

                TextInput::make('plate')
                    ->label('Plat')
                    ->maxLength(255),

                Select::make('status')
                    ->label('Status Unit')
                    // Fixed set from the schema: active | maintenance | retired.
                    ->options([
                        'active' => 'Aktif',
                        'maintenance' => 'Perbaikan',
                        'retired' => 'Tidak dipakai',
                    ])
                    ->default('active')
                    ->required()
                    ->native(false),

                Select::make('kitchen_id')
                    ->label('Dapur Pusat')
                    ->relationship('kitchen', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Dapur yang memasok gerobak ini. Dipakai untuk pembatasan antar-dapur.'),
            ]);
    }
}
