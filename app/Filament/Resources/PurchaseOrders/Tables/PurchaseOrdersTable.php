<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Enums\PanelModule;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Services\Access\PermissionMatrix;
use App\Services\PurchaseOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * The history of purchase orders, with the three decisions one can receive right there in the
 * row: mark it as placed with the supplier, receive the actual delivery, or abandon it. "Terima"
 * and "Batalkan" are gated on the matrix's `edit` ability, the same pattern StockOpnamesTable
 * already uses for its own two decision buttons.
 */
class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'supplier:id,name', 'kitchen:id,name', 'lines.rawMaterial:id,name,unit',
                'createdBy:id,name', 'receivedBy:id,name',
            ]))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('supplier.name')
                    ->label('Pemasok')
                    ->searchable(),

                TextColumn::make('kitchen.name')
                    ->label('Dapur Pusat'),

                TextColumn::make('lines')
                    ->label('Bahan Baku')
                    ->wrap()
                    ->state(fn (PurchaseOrder $record): string => $record->lines
                        ->map(fn (PurchaseOrderLine $line): string => sprintf(
                            '%s %dx@Rp%s',
                            $line->rawMaterial?->name ?? '-',
                            $line->qty_ordered,
                            number_format($line->unit_cost_minor, 0, ',', '.'),
                        ))
                        ->implode(', ')),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (PurchaseOrderStatus $state): string => $state->label())
                    ->color(fn (PurchaseOrderStatus $state): string => match ($state) {
                        PurchaseOrderStatus::DRAFT => 'gray',
                        PurchaseOrderStatus::ORDERED => 'warning',
                        PurchaseOrderStatus::RECEIVED => 'success',
                        PurchaseOrderStatus::CANCELLED => 'danger',
                    }),

                TextColumn::make('createdBy.name')
                    ->label('Dibuat oleh')
                    ->placeholder('-'),

                TextColumn::make('received_at')
                    ->label('Diterima')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-')
                    ->description(fn (PurchaseOrder $record): ?string => $record->receivedBy?->name)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(fn (): array => collect(PurchaseOrderStatus::cases())
                        ->mapWithKeys(fn (PurchaseOrderStatus $case): array => [$case->value => $case->label()])
                        ->all()),

                Filter::make('open')
                    ->label('Hanya yang masih berjalan')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', [
                        PurchaseOrderStatus::DRAFT->value,
                        PurchaseOrderStatus::ORDERED->value,
                    ])),
            ])
            ->recordActions([
                Action::make('markOrdered')
                    ->label('Tandai Dipesan')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->visible(fn (PurchaseOrder $record): bool => $record->status->isDraft() && static::mayOperate())
                    ->requiresConfirmation()
                    ->modalHeading('Tandai purchase order ini sebagai sudah dipesan?')
                    ->action(function (PurchaseOrder $record, PurchaseOrderService $service): void {
                        try {
                            $service->markOrdered($record, Auth::user());
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Tidak bisa ditandai')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Purchase order ditandai dipesan')->send();
                    }),

                Action::make('receive')
                    ->label('Terima')
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->color('success')
                    ->visible(fn (PurchaseOrder $record): bool => $record->status->isOrdered() && static::mayOperate())
                    ->schema([
                        Repeater::make('lines')
                            ->label('Jumlah Diterima')
                            ->schema([
                                Hidden::make('raw_material_id'),
                                TextInput::make('raw_material_name')->label('Bahan Baku')->disabled()->dehydrated(false),
                                TextInput::make('qty_received')
                                    ->label('Jumlah Diterima')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false),
                    ])
                    ->fillForm(fn (PurchaseOrder $record): array => [
                        'lines' => $record->lines->map(fn (PurchaseOrderLine $line): array => [
                            'raw_material_id' => $line->raw_material_id,
                            'raw_material_name' => $line->rawMaterial?->name,
                            'qty_received' => $line->qty_ordered,
                        ])->all(),
                    ])
                    ->modalHeading('Terima purchase order ini')
                    ->modalDescription('Jumlah yang benar-benar diterima diposting ke buku besar stok bahan baku dengan biayanya masing-masing. Boleh berbeda dari jumlah yang dipesan.')
                    ->modalSubmitActionLabel('Terima')
                    ->action(function (PurchaseOrder $record, array $data, PurchaseOrderService $service): void {
                        $quantities = collect($data['lines'] ?? [])
                            ->mapWithKeys(fn (array $line): array => [(int) $line['raw_material_id'] => (int) $line['qty_received']])
                            ->all();

                        try {
                            $service->receive($record, $quantities, Auth::user());
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Tidak bisa diterima')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Purchase order diterima')
                            ->body('Stok bahan baku sudah bertambah sesuai jumlah yang diterima.')
                            ->send();
                    }),

                Action::make('cancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (PurchaseOrder $record): bool => ! $record->status->isReceived()
                        && $record->status !== PurchaseOrderStatus::CANCELLED
                        && static::mayOperate())
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan purchase order ini?')
                    ->modalDescription('Belum ada yang diposting ke stok, jadi membatalkannya tidak mengubah stok apa pun.')
                    ->action(function (PurchaseOrder $record, PurchaseOrderService $service): void {
                        try {
                            $service->cancel($record, Auth::user());
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Tidak bisa dibatalkan')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title('Purchase order dibatalkan')->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    private static function mayOperate(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::PURCHASE_ORDERS, 'edit');
    }
}
