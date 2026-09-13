<?php

namespace App\Filament\Resources\Suppliers\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable()->copyable(),
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('contact_name')->label('Kontak')->placeholder('-'),
                TextColumn::make('phone')->label('Telepon')->placeholder('-'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')->label('Status')->placeholder('Semua'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
            // No delete: a supplier is referenced by purchase orders once one exists against it —
            // deactivate instead, the same rule ProductsTable follows.
    }
}
