<?php

namespace App\Filament\Resources\Recipes\Tables;

use App\Models\Recipe;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RecipesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['product:id,name', 'recipeLines.rawMaterial:id,name,unit', 'createdBy:id,name']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produk')
                    ->searchable(),

                TextColumn::make('version')
                    ->label('Versi')
                    ->badge(),

                TextColumn::make('lines')
                    ->label('Bahan Baku')
                    ->wrap()
                    ->state(fn (Recipe $record): string => $record->recipeLines
                        ->map(fn ($line): string => sprintf(
                            '%s: %d%s',
                            $line->rawMaterial?->name ?? '-',
                            $line->qty_per_unit,
                            $line->rawMaterial?->unit ?? '',
                        ))
                        ->implode(', ')),

                TextColumn::make('effective_from')
                    ->label('Berlaku Sejak')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('computed_cost_minor')
                    ->label('HPP Terhitung')
                    ->money('IDR', 0)
                    ->placeholder('belum diketahui'),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                TextColumn::make('createdBy.name')
                    ->label('Dibuat oleh')
                    ->placeholder('-')
                    ->toggleable(),
            ])
            // No record actions: a recipe version is never edited or deleted — see
            // RecipeResource. A correction is a new "Buat Resep" instead.
            ->recordActions([])
            ->toolbarActions([]);
    }
}
