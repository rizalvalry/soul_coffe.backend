<?php

namespace App\Filament\Resources\DeliveryIncidents\Tables;

use App\Enums\IncidentStatus;
use App\Enums\PanelModule;
use App\Models\DeliveryIncident;
use App\Services\Access\PermissionMatrix;
use App\Services\DeliveryIncidentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The decision, as two buttons and a photograph.
 *
 * The photo is a column rather than something behind a click, because it is the whole basis of
 * the decision: a spilled crate and a cracked lid are different answers, and nobody should have
 * to open a modal to see which one they are looking at.
 *
 * Both actions require confirmation and both accept a note, because whoever decides is deciding
 * about money — cups written off the kitchen ledger — and the note is what makes that decision
 * legible next month.
 */
class DeliveryIncidentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'lines.product:id,name',
                'photo',
                'rider:id,name',
                'refillRequest:id,code,cart_id,staff_id,status',
                'refillRequest.cart:id,code',
                'refillRequest.staff:id,name',
                'decidedBy:id,name',
            ]))
            ->defaultSort('reported_at', 'desc')
            ->columns([
                TextColumn::make('reported_at')
                    ->label('Dilaporkan')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                ImageColumn::make('photo.path')
                    ->label('Foto')
                    ->disk('public')
                    ->height(56)
                    ->extraImgAttributes(['class' => 'object-cover rounded-lg']),

                TextColumn::make('refillRequest.code')
                    ->label('Request')
                    ->searchable()
                    ->description(fn (DeliveryIncident $record): string => 'Gerobak '.($record->refillRequest?->cart?->code ?? '-')),

                TextColumn::make('rider.name')
                    ->label('Rider')
                    ->searchable(),

                TextColumn::make('refillRequest.staff.name')
                    ->label('Untuk staff')
                    ->placeholder('-'),

                TextColumn::make('lines')
                    ->label('Cups rusak')
                    ->badge()
                    ->color('danger')
                    ->state(fn (DeliveryIncident $record): string => $record->lines->sum('qty_damaged').' cups')
                    ->description(fn (DeliveryIncident $record): string => $record->lines
                        ->map(fn ($line): string => $line->qty_damaged.'× '.($line->product?->name ?? 'produk'))
                        ->join(', ')),

                TextColumn::make('note')
                    ->label('Keterangan rider')
                    ->placeholder('-')
                    ->wrap()
                    ->limit(80),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (IncidentStatus $state): string => $state->label())
                    ->color(fn (IncidentStatus $state): string => match ($state) {
                        IncidentStatus::REPORTED => 'danger',
                        IncidentStatus::RESOLVED_CANCELLED => 'gray',
                        IncidentStatus::RESOLVED_PARTIAL => 'success',
                    }),

                TextColumn::make('written_off_qty')
                    ->label('Dihapus dari stok')
                    ->numeric()
                    ->toggleable(),

                TextColumn::make('decidedBy.name')
                    ->label('Diputuskan oleh')
                    ->placeholder('-')
                    ->description(fn (DeliveryIncident $record): ?string => $record->decision_note)
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('menunggu')
                    ->label('Hanya yang menunggu keputusan')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->where('status', IncidentStatus::REPORTED)),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options(fn (): array => collect(IncidentStatus::cases())
                        ->mapWithKeys(fn (IncidentStatus $case): array => [$case->value => $case->label()])
                        ->all()),
            ])
            ->recordActions([
                Action::make('partial')
                    ->label('Lanjut sebagian')
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('primary')
                    ->visible(fn (DeliveryIncident $record): bool => $record->status->isOpen() && static::mayDecide())
                    ->requiresConfirmation()
                    ->modalHeading('Lanjutkan pengantaran dengan cups yang masih layak')
                    ->modalDescription('Cups yang rusak dihapus dari stok dapur dan jumlah kirim di request ini dikurangi sebanyak itu, sehingga rider hanya bisa mencatat cups yang benar-benar sampai. Rider tetap melanjutkan pengantaran.')
                    ->modalSubmitActionLabel('Lanjut sebagian')
                    ->schema([
                        Textarea::make('note')
                            ->label('Catatan (opsional)')
                            ->maxLength(500)
                            ->helperText('Ikut terkirim ke rider, staff pemohon, dan dapur.'),
                    ])
                    ->action(function (DeliveryIncident $record, array $data, DeliveryIncidentService $service): void {
                        $service->resolve($record, Auth::user(), 'partial', $data['note'] ?? null);

                        Notification::make()
                            ->success()
                            ->title('Pengantaran lanjut sebagian')
                            ->body('Cups rusak sudah dihapus dari stok dapur dan semua pihak terkait diberi tahu.')
                            ->send();
                    }),

                Action::make('cancel')
                    ->label('Batalkan pengantaran')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (DeliveryIncident $record): bool => $record->status->isOpen() && static::mayDecide())
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan pengantaran ini?')
                    ->modalDescription('Request menjadi DIBATALKAN, rider kembali ke dapur, dan cups yang rusak dihapus dari stok dapur. Cups yang masih layak tetap menjadi stok dapur — tidak ada yang dikirim.')
                    ->modalSubmitActionLabel('Batalkan pengantaran')
                    ->schema([
                        Textarea::make('note')
                            ->label('Alasan')
                            ->required()
                            ->maxLength(500)
                            ->helperText('Wajib: ini yang dibaca staff pemohon ketika pesanannya tidak datang.'),
                    ])
                    ->action(function (DeliveryIncident $record, array $data, DeliveryIncidentService $service): void {
                        $service->resolve($record, Auth::user(), 'cancel', (string) $data['note']);

                        Notification::make()
                            ->warning()
                            ->title('Pengantaran dibatalkan')
                            ->body('Rider, staff pemohon, dan dapur sudah diberi tahu.')
                            ->send();
                    }),
            ])
            // No create, edit, delete or bulk actions: this is a report from the field with a
            // photograph attached. See DeliveryIncidentResource.
            ->toolbarActions([]);
    }

    /**
     * Deciding is a separate right from reading.
     *
     * The resource refuses create/edit/delete outright so there is no CRUD form, which means the
     * matrix's `edit` ability has nothing else to govern here — so it governs this: whether the
     * signed-in role may actually rule on an incident. Without this a role granted only "Lihat"
     * would still see two buttons that write to the stock ledger. DeliveryIncidentService checks
     * the role again on the way through, because a hidden button is not an authorisation.
     */
    private static function mayDecide(): bool
    {
        return PermissionMatrix::can(Auth::user(), PanelModule::DELIVERY_INCIDENTS, 'edit');
    }
}
