<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Product;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('unit')
                    ->label('Satuan')
                    ->badge(),
                // Prices live in product_price_versions, never on the product row, so the
                // current price has to be resolved per record (R10).
                TextColumn::make('cost_price')
                    ->label('HPP')
                    ->state(fn (Product $record): ?int => $record->currentPriceVersion()?->cost_price_minor)
                    ->money('IDR', 0)
                    ->placeholder('belum diisi'),
                TextColumn::make('sell_price')
                    ->label('Harga Jual')
                    ->state(fn (Product $record): ?int => $record->currentPriceVersion()?->sell_price_minor)
                    ->money('IDR', 0)
                    ->placeholder('belum diisi'),
                IconColumn::make('is_sellable')
                    ->label('Dijual')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_active')->label('Status')->placeholder('Semua'),
                TernaryFilter::make('is_sellable')->label('Dijual')->placeholder('Semua'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
            // No delete: products are referenced by allocation lines, refill lines and stock
            // ledger rows. Deactivate instead — history must stay readable.
    }
}
