<?php

namespace App\Filament\Resources\RawMaterials\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RawMaterialsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('code')->label('Kode')->searchable()->copyable(),
                TextColumn::make('name')->label('Nama')->searchable()->sortable(),
                TextColumn::make('unit')->label('Satuan')->badge(),
                TextColumn::make('reorder_point')->label('Titik Pemesanan Ulang')->placeholder('-'),
                IconColumn::make('is_active')->label('Aktif')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_active')->label('Status')->placeholder('Semua'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
            // No delete: a raw material is referenced by recipe lines, purchase order lines and
            // stock ledger rows once it is used — deactivate instead, the same rule ProductsTable
            // follows for finished products.
    }
}
