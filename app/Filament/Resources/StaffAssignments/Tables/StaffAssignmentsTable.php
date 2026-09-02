<?php

namespace App\Filament\Resources\StaffAssignments\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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
                    ->label('Staff')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('cart.code')
                    ->label('Gerobak')
                    ->searchable()
                    ->sortable(),
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
                    ->label('Gerobak')
                    ->relationship('cart', 'code'),
                SelectFilter::make('location')
                    ->label('Lokasi')
                    ->relationship('location', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
