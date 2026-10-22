<?php

namespace App\Filament\Resources\RefillRequests\Tables;

use App\Enums\PanelModule;
use App\Enums\RefillStatus;
use App\Models\RefillRequest;
use App\Services\Access\PermissionMatrix;
use App\Services\RefillExpiryDecisionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class RefillRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'cart:id,code', 'staff:id,name', 'lines.product:id,name', 'expiryDecision.decidedBy:id,name',
            ]))
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('code')->label('Kode')->searchable(),
                TextColumn::make('operating_date')->label('Tanggal operasional')->date('d M Y')->sortable(),
                TextColumn::make('cart.code')->label('Coffee Bike'),
                TextColumn::make('staff.name')->label('Rider'),
                TextColumn::make('status')->label('Status')
                    ->formatStateUsing(fn (RefillStatus $state): string => $state->label())->badge(),
                TextColumn::make('lines')->label('Disiapkan')
                    ->state(fn (RefillRequest $record): string => $record->lines
                        ->map(fn ($line): string => ($line->qty_prepared ?? 0).'× '.($line->product?->name ?? 'produk'))
                        ->join(', '))->wrap(),
                TextColumn::make('expiryDecision.outcome')->label('Keputusan')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'failed' => 'Tutup sebagai gagal',
                        'recognized' => 'Akui sebagian/seluruh',
                        default => 'Belum diputuskan',
                    })
                    ->description(fn (RefillRequest $record): ?string => $record->expiryDecision
                        ? $record->expiryDecision->decidedBy?->name.' · '.$record->expiryDecision->reason : null)
                    ->wrap(),
            ])
            ->filters([
                Filter::make('pending_expiry')->label('Kedaluwarsa menunggu keputusan')->default()
                    ->query(fn (Builder $query): Builder => $query->where('status', RefillStatus::EXPIRED)
                        ->whereDoesntHave('expiryDecision')),
            ])
            ->recordActions([
                Action::make('failed')->label('Tutup sebagai gagal')->color('danger')
                    ->visible(fn (RefillRequest $record): bool => self::mayDecide($record))
                    ->requiresConfirmation()->schema([self::reasonField()])
                    ->action(function (RefillRequest $record, array $data, RefillExpiryDecisionService $service): void {
                        $service->decide($record, Auth::user(), 'failed', (string) $data['reason']);
                        Notification::make()->success()->title('Request ditutup sebagai gagal')->send();
                    }),
                Action::make('recognize')->label('Akui sebagian/seluruh')->color('success')
                    ->visible(fn (RefillRequest $record): bool => self::mayDecide($record)
                        && $record->lines->contains(fn ($line): bool => (int) $line->qty_approved > 0))
                    ->schema([
                        self::reasonField(),
                        Repeater::make('lines')->label('Jumlah fisik yang diakui')
                            ->schema([
                                Hidden::make('line_id'),
                                TextInput::make('product_name')->label('Produk')->disabled()->dehydrated(false),
                                TextInput::make('qty_recognized')->label('Diakui')->integer()->minValue(0)->required(),
                                TextInput::make('qty_prepared_confirmed')->label('Disiapkan (konfirmasi Finance)')
                                    ->integer()->minValue(0)->required()
                                    ->helperText('Jika Produksi belum mencatat, isi jumlah fisik yang sudah disiapkan; batasnya jumlah disetujui.'),
                                TextInput::make('qty_prepared')->label('Catatan Produksi')->disabled()->dehydrated(false),
                            ])->columns(4)->addable(false)->deletable(false)->reorderable(false),
                    ])
                    ->fillForm(fn (RefillRequest $record): array => [
                        'lines' => $record->lines->map(fn ($line): array => [
                            'line_id' => $line->id,
                            'product_name' => $line->product?->name,
                            'qty_prepared' => $line->qty_prepared ?? 0,
                            'qty_prepared_confirmed' => $line->qty_prepared ?? 0,
                            'qty_recognized' => 0,
                        ])->all(),
                    ])
                    ->action(function (RefillRequest $record, array $data, RefillExpiryDecisionService $service): void {
                        $service->decide($record, Auth::user(), 'recognized', (string) $data['reason'], $data['lines'] ?? []);
                        Notification::make()->success()->title('Stok yang diakui telah diposting')->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    private static function reasonField(): Textarea
    {
        return Textarea::make('reason')->label('Alasan keputusan')->required()->minLength(10)->maxLength(1000);
    }

    private static function mayDecide(RefillRequest $record): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::REFILL_APPROVAL, 'edit')
            && $record->status === RefillStatus::EXPIRED && $record->expiryDecision === null;
    }
}
