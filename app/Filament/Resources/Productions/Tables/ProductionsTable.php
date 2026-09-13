<?php

namespace App\Filament\Resources\Productions\Tables;

use App\Models\Production;
use App\Models\ProductionLine;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'kitchen:id,name', 'actor:id,name', 'lines.product:id,name',
            ]))
            ->defaultSort('produced_at', 'desc')
            ->columns([
                TextColumn::make('produced_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('kitchen.name')
                    ->label('Dapur Pusat'),

                TextColumn::make('actor.name')
                    ->label('Barista'),

                TextColumn::make('lines')
                    ->label('Produk Diseduh')
                    ->wrap()
                    ->state(fn (Production $record): string => $record->lines
                        ->map(fn (ProductionLine $line): string => sprintf(
                            '%s x%d%s',
                            $line->product?->name ?? '-',
                            $line->qty_brewed,
                            $line->recipe_id ? '' : ' (tanpa resep)',
                        ))
                        ->implode(', ')),

                TextColumn::make('cost')
                    ->label('Estimasi HPP Bahan Baku')
                    ->state(fn (Production $record): ?int => $record->lines
                        ->sum(fn (ProductionLine $line): int => ($line->recipe_cost_minor ?? 0) * $line->qty_brewed) ?: null)
                    ->money('IDR', 0)
                    ->placeholder('-'),
            ]);
    }
}
