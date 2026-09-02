<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Role;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone_e164')
                    ->label('Nomor HP')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (Role $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('kitchen.name')
                    ->label('Dapur')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('role')
            ->filters([
                SelectFilter::make('role')
                    ->label('Role')
                    ->options(collect(Role::cases())->mapWithKeys(
                        fn (Role $role) => [$role->value => $role->label()]
                    )),
                TernaryFilter::make('is_active')
                    ->label('Status akun')
                    ->placeholder('Semua')
                    ->trueLabel('Aktif saja')
                    ->falseLabel('Nonaktif saja'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
            // Deliberately no delete action: users are referenced by refill requests, staff
            // assignments and audit rows. Deactivating (is_active) is the reversible way to
            // retire an account without orphaning that history.
    }
}
