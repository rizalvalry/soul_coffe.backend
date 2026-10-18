<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Enums\PanelModule;
use App\Enums\PurchaseOrderStatus;
use App\Models\CashAccount;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Services\Access\PermissionMatrix;
use App\Services\PurchaseOrderService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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

                TextColumn::make('accounting_note')
                    ->label('Jurnal')
                    ->state(fn (PurchaseOrder $record): ?string => $record->status->isReceived() && $record->payment_method === null
                        ? 'Sebelum cutover — tanpa jurnal' : null)
                    ->badge()
                    ->color('gray')
                    ->placeholder('-'),

                TextColumn::make('createdBy.name')
                    ->label('Dibuat oleh')
                    ->placeholder('-'),

                TextColumn::make('received_at')
                    ->label('Diterima')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-')
                    ->description(fn (PurchaseOrder $record): ?string => $record->receivedBy?->name)
                    ->toggleable(),
                TextColumn::make('unpaid_minor')
                    ->label('Sisa hutang')
                    ->state(fn (PurchaseOrder $record): ?int => $record->status->isReceived() && $record->payment_method === 'CREDIT'
                        ? $record->unpaidMinor() : null)
                    ->money('IDR', 0)
                    ->placeholder('-'),
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
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                        $query->whereIn('status', [PurchaseOrderStatus::DRAFT->value, PurchaseOrderStatus::ORDERED->value])
                            ->orWhere(function (Builder $query): void {
                                $query->where('status', PurchaseOrderStatus::RECEIVED->value)
                                    ->where('payment_method', 'CREDIT')
                                    ->whereHas('receiptJournal', fn (Builder $receipt): Builder => $receipt
                                        ->whereDoesntHave('reversal')
                                        ->whereRaw('(
                                            SELECT COALESCE(SUM(debit_minor), 0) FROM journal_lines
                                            WHERE entry_id = journal_entries.id
                                        ) > (
                                            SELECT COALESCE(SUM(payment.amount_minor), 0)
                                            FROM purchase_order_payments payment
                                            JOIN journal_entries payment_journal
                                              ON payment_journal.source_type = ?
                                             AND payment_journal.source_id = payment.id
                                             AND payment_journal.purpose = ?
                                            WHERE payment.purchase_order_id = purchase_orders.id
                                              AND NOT EXISTS (
                                                SELECT 1 FROM journal_entries reversal
                                                WHERE reversal.reverses_entry_id = payment_journal.id
                                              )
                                        )', ['purchase_order_payment', 'PAYMENT']));
                            });
                    })),
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
                        Select::make('payment_method')->label('Cara bayar')
                            ->options(['DIRECT' => 'Bayar langsung', 'CREDIT' => 'Kredit / hutang supplier'])
                            ->required()->live(),
                        Select::make('cash_account_id')->label('Akun kas / bank')
                            ->options(fn (): array => static::cashAccountOptions())
                            ->visible(fn (Get $get): bool => $get('payment_method') === 'DIRECT')
                            ->required(fn (Get $get): bool => $get('payment_method') === 'DIRECT'),
                        Repeater::make('lines')
                            ->label('Jumlah Diterima')
                            ->schema([
                                Hidden::make('raw_material_id'),
                                TextInput::make('raw_material_name')->label('Bahan Baku')->disabled()->dehydrated(false),
                                TextInput::make('qty_received')
                                    ->label('Jumlah Diterima')
                                    ->integer()
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
                            ->mapWithKeys(fn (array $line): array => [(int) $line['raw_material_id'] => $line['qty_received']])
                            ->all();

                        try {
                            $service->receive($record, $quantities, Auth::user(), $data['payment_method'], isset($data['cash_account_id']) ? (int) $data['cash_account_id'] : null);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Tidak bisa diterima')->body($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title('Purchase order diterima')
                            ->body('Stok dan jurnalnya sudah tercatat sesuai jumlah yang diterima.')
                            ->send();
                    }),

                Action::make('payDebt')
                    ->label('Bayar hutang')
                    ->icon('heroicon-o-banknotes')
                    ->authorize(fn (): bool => static::mayOperate())
                    ->visible(fn (PurchaseOrder $record): bool => $record->status->isReceived()
                        && $record->payment_method === 'CREDIT'
                        && $record->unpaidMinor() > 0
                        && static::mayOperate())
                    ->schema([
                        Hidden::make('uuid')->default(fn (): string => (string) Str::uuid()),
                        DatePicker::make('paid_on')->label('Tanggal bayar')->default(today())->required(),
                        Select::make('cash_account_id')->label('Akun kas / bank')->options(fn (): array => static::cashAccountOptions())->required(),
                        TextInput::make('amount_minor')->label('Nominal (Rp)')->numeric()->rule('integer')->minValue(1)->required(),
                    ])
                    ->fillForm(fn (PurchaseOrder $record): array => [
                        'uuid' => (string) Str::uuid(), 'paid_on' => today()->toDateString(), 'amount_minor' => $record->unpaidMinor(),
                    ])
                    ->modalDescription(fn (PurchaseOrder $record): string => 'Sisa hutang: Rp'.number_format($record->unpaidMinor(), 0, ',', '.'))
                    ->action(function (PurchaseOrder $record, array $data, PurchaseOrderService $service): void {
                        abort_unless(static::mayOperate(), 403);
                        $service->payDebt($record, Auth::user(), $data);
                        Notification::make()->success()->title('Pembayaran hutang sudah tercatat')->send();
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

    private static function cashAccountOptions(): array
    {
        return CashAccount::query()->where('is_active', true)->whereHas('account', fn (Builder $query): Builder => $query
            ->where('is_active', true)->whereIn('code', ['1-1100', '1-1200', '1-1300']))
            ->orderBy('name')->pluck('name', 'id')->all();
    }
}
