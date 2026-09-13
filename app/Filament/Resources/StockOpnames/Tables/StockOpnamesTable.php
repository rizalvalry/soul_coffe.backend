<?php

namespace App\Filament\Resources\StockOpnames\Tables;

use App\Enums\PanelModule;
use App\Enums\StockOpnameStatus;
use App\Models\StockOpname;
use App\Services\Access\PermissionMatrix;
use App\Services\StockOpnameService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * The history of stock opnames, with the two decisions a draft can receive right there in the
 * row: apply it to the ledger, or abandon it. Both gated on the matrix's `edit` ability — the
 * same pattern DeliveryIncidentsTable and SalesTable already use for the one legitimate write
 * action their otherwise-read-only screens allow.
 */
class StockOpnamesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['lines.product:id,name', 'createdBy:id,name', 'appliedBy:id,name']))
            ->defaultSort('counted_at', 'desc')
            ->columns([
                TextColumn::make('counted_at')
                    ->label('Waktu Hitung')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('location')
                    ->label('Lokasi')
                    ->badge()
                    ->state(fn (StockOpname $record): string => $record->locationLabel()),

                TextColumn::make('lines')
                    ->label('Produk & Selisih')
                    ->wrap()
                    ->state(fn (StockOpname $record): string => $record->lines
                        ->filter(fn ($line): bool => $line->variance_qty !== 0)
                        ->map(fn ($line): string => sprintf(
                            '%s %+d',
                            $line->product?->name ?? '-',
                            $line->variance_qty,
                        ))
                        ->implode(', ') ?: 'Semua sesuai — tidak ada selisih')
                    ->description(fn (StockOpname $record): string => $record->reason),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (StockOpnameStatus $state): string => $state->label())
                    ->color(fn (StockOpnameStatus $state): string => match ($state) {
                        StockOpnameStatus::DRAFT => 'warning',
                        StockOpnameStatus::APPLIED => 'success',
                        StockOpnameStatus::CANCELLED => 'gray',
                    }),

                TextColumn::make('createdBy.name')
                    ->label('Dihitung oleh')
                    ->placeholder('-'),

                TextColumn::make('appliedBy.name')
                    ->label('Diproses oleh')
                    ->placeholder('-')
                    ->description(fn (StockOpname $record): ?string => $record->applied_at?->format('d M Y H:i'))
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('draft')
                    ->label('Hanya draf yang belum diterapkan')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->where('status', StockOpnameStatus::DRAFT)),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options(fn (): array => collect(StockOpnameStatus::cases())
                        ->mapWithKeys(fn (StockOpnameStatus $case): array => [$case->value => $case->label()])
                        ->all()),
            ])
            ->recordActions([
                Action::make('apply')
                    ->label('Terapkan')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (StockOpname $record): bool => $record->status->isDraft() && static::mayOperate())
                    ->requiresConfirmation()
                    ->modalHeading('Terapkan stock opname ini?')
                    ->modalDescription('Selisih akan diposting ke buku besar stok, dihitung ulang terhadap stok yang berjalan saat ini juga — bukan angka saat draf ini dibuat, kalau-kalau ada transaksi lain di antaranya. Tidak bisa dibatalkan setelah ini.')
                    ->modalSubmitActionLabel('Terapkan')
                    ->action(function (StockOpname $record, StockOpnameService $service): void {
                        try {
                            $service->apply($record, Auth::user());
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Tidak bisa diterapkan')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Stock opname diterapkan')
                            ->body('Buku besar stok sudah disesuaikan dengan hasil hitung fisik.')
                            ->send();
                    }),

                Action::make('cancel')
                    ->label('Batalkan Draf')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (StockOpname $record): bool => $record->status->isDraft() && static::mayOperate())
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan draf ini?')
                    ->modalDescription('Draf ini belum pernah menyentuh stok, jadi membatalkannya tidak mengubah apa pun — draf hanya ditandai dibatalkan untuk riwayat.')
                    ->action(function (StockOpname $record, StockOpnameService $service): void {
                        try {
                            $service->cancel($record, Auth::user());
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Tidak bisa dibatalkan')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Draf dibatalkan')->send();
                    }),
            ])
            // No create, edit or delete: see StockOpnameResource for why counting happens on its
            // own page and an applied correction is never editable in place.
            ->toolbarActions([]);
    }

    private static function mayOperate(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::STOCK_OPNAME, 'edit');
    }
}
