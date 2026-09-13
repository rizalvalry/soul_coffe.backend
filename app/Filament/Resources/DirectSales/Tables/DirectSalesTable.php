<?php

namespace App\Filament\Resources\DirectSales\Tables;

use App\Enums\PanelModule;
use App\Models\Cart;
use App\Models\DirectSale;
use App\Models\DirectSaleLine;
use App\Services\Access\PermissionMatrix;
use App\Services\DirectSaleService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class DirectSalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'cart:id,code',
                'recordedBy:id,name',
                'voidedBy:id,name',
                'lines.product:id,name',
            ]))
            ->defaultSort('occurred_at', 'desc')
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

                TextColumn::make('lines')
                    ->label('Produk')
                    ->wrap()
                    ->state(fn (DirectSale $record): string => $record->lines
                        ->map(fn (DirectSaleLine $line): string => sprintf(
                            '%s ×%d',
                            $line->product?->name ?? '-',
                            $line->qty,
                        ))
                        ->implode(', ')),

                TextColumn::make('total_qty')
                    ->label('Cups')
                    ->numeric()
                    ->sortable()
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('Total cups')),

                TextColumn::make('total_amount_minor')
                    ->label('Nilai')
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

                TextColumn::make('recordedBy.name')
                    ->label('Dicatat oleh')
                    ->searchable(),

                TextColumn::make('voided_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (DirectSale $record): string => $record->isVoided() ? 'Dibatalkan' : 'Aktif')
                    ->color(fn (DirectSale $record): string => $record->isVoided() ? 'gray' : 'success')
                    ->description(fn (DirectSale $record): ?string => $record->isVoided()
                        ? sprintf('oleh %s: %s', $record->voidedBy?->name ?? '-', $record->void_reason)
                        : null),
            ])
            ->filters([
                Filter::make('hari_ini')
                    ->label('Hari ini')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->whereDate('occurred_at', today())),

                Filter::make('dibatalkan')
                    ->label('Hanya yang dibatalkan')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('voided_at')),

                SelectFilter::make('cart_id')
                    ->label('Gerobak')
                    ->options(fn (): array => Cart::query()->orderBy('code')->pluck('code', 'id')->all())
                    ->searchable(),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([
                Action::make('void')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (DirectSale $record): bool => ! $record->isVoided() && static::mayVoid())
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan transaksi ini?')
                    ->modalDescription('Cups yang terjual dikembalikan ke stok gerobak lewat buku besar. Tidak bisa dilakukan lagi setelah setoran gerobak ini untuk tanggal tersebut direkonsiliasi.')
                    ->modalSubmitActionLabel('Batalkan transaksi')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Alasan')
                            ->required()
                            ->maxLength(500)
                            ->helperText('Wajib. Tercatat pada transaksinya.'),
                    ])
                    ->action(function (DirectSale $record, array $data, DirectSaleService $service): void {
                        try {
                            $service->void($record, Auth::user(), (string) $data['reason']);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Tidak bisa membatalkan')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Transaksi dibatalkan')
                            ->body('Cups sudah dikembalikan ke stok gerobak.')
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    private static function mayVoid(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::DIRECT_SALES, 'edit');
    }
}
