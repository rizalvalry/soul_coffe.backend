<?php

namespace App\Filament\Resources\Settlements\Tables;

use App\Models\Cart;
use App\Models\Settlement;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SettlementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'cart:id,code',
                'staff:id,name',
                'reconciledBy:id,name',
            ]))
            ->defaultSort('operating_date', 'desc')
            ->columns([
                TextColumn::make('operating_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('cart.code')
                    ->label('Gerobak')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('staff.name')
                    ->label('Staff')
                    ->searchable()
                    ->placeholder('-'),

                TextColumn::make('cash_minor')
                    ->label('Tunai')
                    ->formatStateUsing(fn (int $state): string => static::rupiah($state))
                    ->summarize(Sum::make()->label('Total tunai')->formatStateUsing(fn (?int $state): string => static::rupiah((int) $state)))
                    ->toggleable(),

                TextColumn::make('qris_minor')
                    ->label('QRIS')
                    ->formatStateUsing(fn (int $state): string => static::rupiah($state))
                    ->summarize(Sum::make()->label('Total QRIS')->formatStateUsing(fn (?int $state): string => static::rupiah((int) $state)))
                    ->toggleable(),

                TextColumn::make('transfer_minor')
                    ->label('Transfer')
                    ->formatStateUsing(fn (int $state): string => static::rupiah($state))
                    ->summarize(Sum::make()->label('Total transfer')->formatStateUsing(fn (?int $state): string => static::rupiah((int) $state)))
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('declared_total_minor')
                    ->label('Diterima')
                    ->formatStateUsing(fn (int $state): string => static::rupiah($state))
                    ->summarize(Sum::make()->label('Total diterima')->formatStateUsing(fn (?int $state): string => static::rupiah((int) $state)))
                    ->sortable(),

                TextColumn::make('expected_total_minor')
                    ->label('Seharusnya')
                    ->formatStateUsing(fn (int $state): string => static::rupiah($state))
                    ->summarize(Sum::make()->label('Total transaksi')->formatStateUsing(fn (?int $state): string => static::rupiah((int) $state)))
                    ->sortable(),

                // The number this whole screen exists for. Signed, and coloured only when it is
                // not zero — a page of red on a normal day teaches people to ignore the colour.
                TextColumn::make('variance_minor')
                    ->label('Selisih')
                    // Words rather than a sign: "Rp -20.000" is read wrong at a glance, and the
                    // direction of a shortfall is the entire meaning of this column.
                    ->formatStateUsing(fn (int $state): string => match (true) {
                        $state === 0 => 'Pas',
                        $state < 0 => 'Kurang '.static::rupiah(abs($state)),
                        default => 'Lebih '.static::rupiah($state),
                    })
                    ->color(fn (int $state): string => $state === 0 ? 'gray' : ($state < 0 ? 'danger' : 'warning'))
                    ->description(fn (Settlement $record): ?string => $record->variance_reason)
                    ->sortable(),

                TextColumn::make('cups')
                    ->label('Cups')
                    ->state(fn (Settlement $record): string => sprintf(
                        '%d terjual · %d kembali · %d reject',
                        (int) $record->lines->sum('qty_sold'),
                        (int) $record->lines->sum('qty_remaining'),
                        (int) $record->lines->sum('qty_wasted'),
                    )),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'SUBMITTED' => 'Uang masuk',
                        'RECONCILED' => 'Selesai',
                        'VARIANCE_FLAGGED' => 'Perlu ditinjau',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'RECONCILED' => 'success',
                        'VARIANCE_FLAGGED' => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('reconciledBy.name')
                    ->label('Disetujui oleh')
                    ->placeholder('-')
                    ->description(fn (Settlement $record): ?string => $record->reconciled_at?->format('d M Y H:i'))
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('hari_ini')
                    ->label('Hari ini')
                    ->query(fn (Builder $query): Builder => $query->whereDate('operating_date', today())),

                Filter::make('selisih')
                    ->label('Hanya yang ada selisih')
                    ->query(fn (Builder $query): Builder => $query->where('variance_minor', '!=', 0)),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'SUBMITTED' => 'Uang masuk',
                        'RECONCILED' => 'Selesai',
                        'VARIANCE_FLAGGED' => 'Perlu ditinjau',
                    ]),

                SelectFilter::make('cart_id')
                    ->label('Gerobak')
                    ->options(fn (): array => Cart::query()->orderBy('code')->pluck('code', 'id')->all())
                    ->searchable(),
            ], layout: FiltersLayout::AboveContent)
            // No create, edit or delete. See SettlementResource for why that is structural.
            ->recordActions([])
            ->toolbarActions([]);
    }

    /** R9: whole rupiah in a BIGINT, so it is formatted, never divided. */
    private static function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
