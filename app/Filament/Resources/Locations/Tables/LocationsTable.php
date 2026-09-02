<?php

namespace App\Filament\Resources\Locations\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('lat')
                    ->label('Latitude'),
                TextColumn::make('lng')
                    ->label('Longitude'),
                TextColumn::make('geofence_m')
                    ->label('Radius')
                    ->suffix(' m')
                    ->sortable(),
                TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(40)
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
