<?php

namespace App\Filament\Resources\DailyTargets\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DailyTargetForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Produk')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                // cart_id and location_id are both nullable in the schema: a target may be
                // pinned to a cart, to a selling point, or to both.
                Select::make('cart_id')
                    ->label('Gerobak')
                    ->relationship('cart', 'code')
                    ->searchable()
                    ->preload()
                    ->placeholder('Semua gerobak'),

                Select::make('location_id')
                    ->label('Lokasi')
                    ->relationship('location', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Semua lokasi'),

                TextInput::make('target_qty')
                    ->label('Target (qty)')
                    ->numeric()
                    // R7: positive integers only -- cups are not divisible.
                    ->minValue(1)
                    ->step(1)
                    ->required(),

                Select::make('weekday')
                    ->label('Hari')
                    // 0 = Sunday .. 6 = Saturday; null = applies every day.
                    ->options([
                        0 => 'Minggu',
                        1 => 'Senin',
                        2 => 'Selasa',
                        3 => 'Rabu',
                        4 => 'Kamis',
                        5 => 'Jumat',
                        6 => 'Sabtu',
                    ])
                    ->placeholder('Setiap hari')
                    ->native(false),
            ]);
    }
}
