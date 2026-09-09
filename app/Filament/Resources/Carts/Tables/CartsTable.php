<?php

namespace App\Filament\Resources\Carts\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CartsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('plate')
                    ->label('Plat')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'active' => 'Aktif',
                        'maintenance' => 'Perbaikan',
                        'retired' => 'Tidak dipakai',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'maintenance' => 'warning',
                        'retired' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('kitchen.name')
                    ->label('Dapur')
                    ->placeholder('-')
                    ->sortable(),
                IconColumn::make('high_volume_zone')
                    ->label('Zona ramai')
                    ->boolean()
                    ->tooltip('Transaksi besar dari gerobak ini tidak ditandai sebagai perlu ditinjau.')
                    ->toggleable(),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'active' => 'Aktif',
                        'maintenance' => 'Perbaikan',
                        'retired' => 'Tidak dipakai',
                    ]),
                SelectFilter::make('kitchen')
                    ->label('Dapur')
                    ->relationship('kitchen', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
