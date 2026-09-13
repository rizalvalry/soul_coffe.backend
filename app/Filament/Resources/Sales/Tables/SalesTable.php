<?php

namespace App\Filament\Resources\Sales\Tables;

use App\Enums\PanelModule;
use App\Models\Cart;
use App\Models\Location;
use App\Models\Sale;
use App\Services\Access\PermissionMatrix;
use App\Services\SaleService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

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
                'voidedBy:id,name',
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

                // The voided state is a column, not a filter tucked away: a reconciler scanning
                // this list needs to see at a glance which rows no longer count, without having
                // to remember to toggle a filter first.
                TextColumn::make('voided_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Sale $record): string => $record->isVoided() ? 'Dibatalkan' : 'Aktif')
                    ->color(fn (Sale $record): string => $record->isVoided() ? 'gray' : 'success')
                    ->description(fn (Sale $record): ?string => $record->isVoided()
                        ? sprintf('oleh %s: %s', $record->voidedBy?->name ?? '-', $record->void_reason)
                        : null),

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

                Filter::make('dibatalkan')
                    ->label('Hanya yang dibatalkan')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('voided_at')),

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

                // The one write this read-only resource allows, and it does not write the row
                // directly — see SaleResource for why editing a sale in place is refused
                // outright. This goes through SaleService::void(), which reverses the stock
                // through the ledger and refuses once the day is already settled.
                Action::make('void')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Sale $record): bool => ! $record->isVoided() && static::mayVoid())
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan transaksi ini?')
                    ->modalDescription('Cups yang terjual dikembalikan ke stok gerobak lewat buku besar. Tidak bisa dilakukan lagi setelah setoran gerobak ini untuk tanggal tersebut direkonsiliasi.')
                    ->modalSubmitActionLabel('Batalkan transaksi')
                    ->schema([
                        Textarea::make('reason')
                            ->label('Alasan')
                            ->required()
                            ->maxLength(500)
                            ->helperText('Wajib. Tercatat pada transaksinya dan dikirim ke Administrator & Finance.'),
                    ])
                    ->action(function (Sale $record, array $data, SaleService $service): void {
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
            // No create, edit, delete or bulk actions: a sale is a record of something that
            // happened at a cart and moved stock through the append-only ledger. See
            // SaleResource for why that is a design decision rather than a missing feature.
            ->toolbarActions([]);
    }

    /**
     * Voiding writes to the stock ledger, so it is gated on the matrix's `edit` ability rather
     * than being available to anyone who can merely view the list — the same pattern
     * DeliveryIncidentsTable uses for its two decision buttons, and for the same reason: a hidden
     * button is not an authorisation, but it stops a read-only grant from seeing a button that
     * would only fail underneath it.
     */
    private static function mayVoid(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::SALES, 'edit');
    }
}
