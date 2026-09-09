<?php

namespace App\Filament\Resources\Sales\Tables;

use App\Models\Cart;
use App\Models\Location;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Eager-loaded because every row prints the cart code, the seller and the area —
            // without this the list is three N+1 queries deep at 200 transactions a day.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'cart:id,code',
                'staff:id,name',
                'location:id,name',
            ]))
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('cart.code')
                    ->label('Gerobak')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('staff.name')
                    ->label('Staff')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('location.name')
                    ->label('Area')
                    ->placeholder('-')
                    ->searchable(),

                TextColumn::make('total_qty')
                    ->label('Cups')
                    ->numeric()
                    ->sortable()
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('Total cups')),

                TextColumn::make('total_amount_minor')
                    ->label('Nilai')
                    // R9: money is whole rupiah in a BIGINT, so it is formatted, never divided.
                    ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state, 0, ',', '.'))
                    ->sortable()
                    ->summarize(
                        \Filament\Tables\Columns\Summarizers\Sum::make()
                            ->label('Total nilai')
                            ->formatStateUsing(fn (?int $state): string => 'Rp '.number_format((int) $state, 0, ',', '.')),
                    ),

                TextColumn::make('payment_method')
                    ->label('Bayar')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'cash' => 'Tunai',
                        'qris' => 'QRIS',
                        'transfer' => 'Transfer',
                        default => $state,
                    })
                    ->toggleable(),

                IconColumn::make('is_suspect')
                    ->label('Perlu ditinjau')
                    ->boolean()
                    ->trueIcon('heroicon-o-flag')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('gray')
                    ->tooltip(fn ($record): ?string => $record->suspect_reason),

                IconColumn::make('gps_unavailable')
                    ->label('GPS mati')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                Filter::make('hari_ini')
                    ->label('Hari ini')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->whereDate('operating_date', today())),

                Filter::make('perlu_ditinjau')
                    ->label('Hanya yang perlu ditinjau')
                    ->query(fn (Builder $query): Builder => $query->where('is_suspect', true)),

                SelectFilter::make('cart_id')
                    ->label('Gerobak')
                    ->options(fn (): array => Cart::query()->orderBy('code')->pluck('code', 'id')->all())
                    ->searchable(),

                SelectFilter::make('location_id')
                    ->label('Area')
                    ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),

                SelectFilter::make('staff_id')
                    ->label('Staff')
                    ->relationship('staff', 'name')
                    ->searchable()
                    ->preload(),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([
                ViewAction::make(),
            ])
            // No create, edit, delete or bulk actions: a sale is a record of something that
            // happened at a cart and moved stock through the append-only ledger. See
            // SaleResource for why that is a design decision rather than a missing feature.
            ->toolbarActions([]);
    }
}
