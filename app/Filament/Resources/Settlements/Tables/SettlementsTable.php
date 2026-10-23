<?php

namespace App\Filament\Resources\Settlements\Tables;

use App\Enums\PanelModule;
use App\Models\Cart;
use App\Models\Product;
use App\Models\RiderClosing;
use App\Models\Settlement;
use App\Services\Access\PermissionMatrix;
use App\Services\SettlementService;
use App\Services\StockLedgerService;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class SettlementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'cart:id,code',
                'staff:id,name',
                'reconciledBy:id,name',
                'lines.product',
            ])->addSelect(['closing_note' => RiderClosing::query()->select('note')
                ->whereColumn('rider_closings.cart_id', 'settlements.cart_id')
                ->whereColumn('rider_closings.operating_date', 'settlements.operating_date')
                ->limit(1)]))
            ->defaultSort('operating_date', 'desc')
            ->columns([
                TextColumn::make('operating_date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('cart.code')
                    ->label('Coffee Bike')
                    ->badge()
                    ->searchable()
                    ->sortable(),

                TextColumn::make('staff.name')
                    ->label('Rider')
                    ->searchable()
                    ->placeholder('-'),
                TextColumn::make('closing_note')->label('Catatan lapangan')
                    ->placeholder('-')->wrap(),

                TextColumn::make('cash_minor')
                    ->label('Tunai')
                    ->formatStateUsing(fn (int $state): string => static::rupiah($state))
                    ->summarize(Sum::make()->label('Total tunai')->formatStateUsing(fn (?int $state): string => static::rupiah((int) $state)))
                    ->toggleable(),

                TextColumn::make('expected_cash_minor')
                    ->label('Ekspektasi Tunai')
                    ->placeholder('Tidak diketahui')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'Tidak diketahui' : static::rupiah($state)),

                TextColumn::make('expected_qris_minor')
                    ->label('Ekspektasi QRIS')
                    ->placeholder('Tidak diketahui')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'Tidak diketahui' : static::rupiah($state)),

                TextColumn::make('expected_transfer_minor')
                    ->label('Ekspektasi Transfer')
                    ->placeholder('Tidak diketahui')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? 'Tidak diketahui' : static::rupiah($state)),

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
                    })
                    ->description(fn (Settlement $record): ?string => $record->expected_stale ? 'Ekspektasi berubah: '.static::rupiah($record->stale_delta_minor)
                        : ($record->method_variance_flagged ? 'Selisih metode: tunai '.static::rupiah($record->cash_variance_minor)
                            .', QRIS '.static::rupiah($record->qris_variance_minor).', transfer '.static::rupiah($record->transfer_variance_minor) : null)),

                TextColumn::make('self_approved')->label('Penyetuju')->badge()
                    ->formatStateUsing(fn (Settlement $record): string => $record->reconciled_at === null ? 'Menunggu approve'
                        : ($record->self_approved ? 'Disetujui sendiri' : 'Berbeda dari pencatat'))
                    ->color(fn (Settlement $record): string => $record->reconciled_at === null ? 'gray'
                        : ($record->self_approved ? 'warning' : 'success')),

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
                    ->query(fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->where('variance_minor', '!=', 0)->orWhere('method_variance_flagged', true))),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'SUBMITTED' => 'Uang masuk',
                        'RECONCILED' => 'Selesai',
                        'VARIANCE_FLAGGED' => 'Perlu ditinjau',
                    ]),

                SelectFilter::make('cart_id')
                    ->label('Coffee Bike')
                    ->options(fn (): array => Cart::query()->orderBy('code')->pluck('code', 'id')->all())
                    ->searchable(),
            ], layout: FiltersLayout::AboveContent)
            ->recordActions([
                Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->visible(fn (Settlement $record): bool => in_array($record->status, ['SUBMITTED', 'VARIANCE_FLAGGED'], true)
                        && PermissionMatrix::can(Auth::user(), PanelModule::SETTLEMENTS, 'edit'))
                    ->requiresConfirmation()
                    ->schema([
                        Repeater::make('lines')->label('Cadangan: atur sisa cups di Coffee Bike')
                            ->schema([
                                Select::make('product_id')->label('Produk')->options(fn (): array => Product::query()->operational()->orderBy('name')->pluck('name', 'id')->all())->required(),
                                TextInput::make('qty_returned')->label('Kembali')->integer()->minValue(0)->required(),
                                TextInput::make('qty_rejected')->label('Reject')->integer()->minValue(0)->required(),
                            ])
                            ->default(fn (Settlement $record): array => collect(app(StockLedgerService::class)
                                ->stockMap(StockLedgerService::CART, $record->cart_id))
                                ->only(Product::query()->operational()->pluck('id')->all())
                                ->filter(fn (int $qty): bool => $qty > 0)
                                ->map(fn (int $qty, int $productId): array => ['product_id' => $productId, 'qty_returned' => $qty, 'qty_rejected' => 0])->values()->all()),
                    ])
                    ->action(function (Settlement $record, array $data, SettlementService $service): void {
                        abort_unless(PermissionMatrix::can(Auth::user(), PanelModule::SETTLEMENTS, 'edit'), 403);
                        try {
                            $service->approve($record, Auth::user(), $data['lines'] ?? []);
                            Notification::make()->success()->title('Setoran disetujui')->send();
                        } catch (RuntimeException $exception) {
                            Notification::make()->danger()->title('Setoran gagal disetujui')->body($exception->getMessage())->send();
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    /** R9: whole rupiah in a BIGINT, so it is formatted, never divided. */
    private static function rupiah(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }
}
