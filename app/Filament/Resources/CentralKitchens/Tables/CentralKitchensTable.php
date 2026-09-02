<?php

namespace App\Filament\Resources\CentralKitchens\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CentralKitchensTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('address')
                    ->label('Alamat')
                    ->limit(45)
                    ->searchable(),
                TextColumn::make('open_at')
                    ->label('Buka')
                    ->time('H:i'),
                TextColumn::make('close_at')
                    ->label('Tutup')
                    ->time('H:i'),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                TextColumn::make('carts_count')
                    ->label('Gerobak')
                    ->counts('carts')
                    ->badge(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
