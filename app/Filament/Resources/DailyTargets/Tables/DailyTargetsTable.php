<?php

namespace App\Filament\Resources\DailyTargets\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DailyTargetsTable
{
    public const WEEKDAYS = [
        0 => 'Minggu',
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produk')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('cart.code')
                    ->label('Gerobak')
                    ->placeholder('Semua')
                    ->sortable(),
                TextColumn::make('location.name')
                    ->label('Lokasi')
                    ->placeholder('Semua')
                    ->sortable(),
                TextColumn::make('target_qty')
                    ->label('Target')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('weekday')
                    ->label('Hari')
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => self::WEEKDAYS[$state] ?? 'Setiap hari')
                    ->placeholder('Setiap hari'),
            ])
            ->filters([
                SelectFilter::make('product')
                    ->label('Produk')
                    ->relationship('product', 'name'),
                SelectFilter::make('cart')
                    ->label('Gerobak')
                    ->relationship('cart', 'code'),
                SelectFilter::make('weekday')
                    ->label('Hari')
                    ->options(self::WEEKDAYS),
            ])
            ->recordActions([
                EditAction::make(),
                // Targets are configuration, not history: deleting one removes a planning row,
                // it does not erase anything that already happened.
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
