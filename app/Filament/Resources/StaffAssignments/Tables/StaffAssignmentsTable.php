<?php

namespace App\Filament\Resources\StaffAssignments\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StaffAssignmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('operating_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Rider')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('cart.code')
                    ->label('Coffee Bike')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Asal')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'hand_to_cart' => 'Add Stock',
                        'carry_forward' => 'Lanjutan lama',
                        default => 'Manual lama',
                    }),
                TextColumn::make('superseded_at')
                    ->label('Diganti pada')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-'),
                TextColumn::make('location.name')
                    ->label('Lokasi')
                    ->searchable(),
                TextColumn::make('kitchen.name')
                    ->label('Dapur')
                    ->placeholder('-')
                    ->toggleable(),
                TextColumn::make('assignedBy.name')
                    ->label('Ditugaskan oleh')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('operating_date', 'desc')
            ->filters([
                Filter::make('hari_ini')
                    ->label('Hari ini')
                    ->query(fn (Builder $query): Builder => $query->whereDate('operating_date', today())),
                SelectFilter::make('cart')
                    ->label('Coffee Bike')
                    ->relationship('cart', 'code'),
                SelectFilter::make('location')
                    ->label('Lokasi')
                    ->relationship('location', 'name'),
            ])
            ->recordActions([]);
    }
}
