<?php

namespace App\Filament\Resources\PinResetRequests\Tables;

use App\Models\PinResetRequest;
use App\Services\PinResetService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Resolving a reset request is one action with three effects, and they only make sense together:
 * a new password is set, the login PIN is cleared, and every session is revoked. Splitting them
 * across separate buttons would let an administrator leave an account half-reset — a new password
 * that cannot be used because the PIN route is still the only way in.
 *
 * The password is typed by the administrator, not generated. That was the explicit requirement:
 * the characters come from the administrator's own authority, and they are the ones who reads it
 * out or emails it to the person waiting.
 */
class PinResetRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Pengguna')
                    ->description(fn (PinResetRequest $record): string => $record->phone_e164)
                    ->searchable(),

                TextColumn::make('user.role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? '-'),

                TextColumn::make('email')
                    ->label('Email untuk dihubungi')
                    ->copyable()
                    ->searchable(),

                IconColumn::make('password_verified')
                    ->label('Sandi cocok')
                    ->boolean()
                    ->tooltip(fn (PinResetRequest $record): string => $record->password_verified
                        ? 'Kata sandi yang diketik pemohon cocok dengan akun — identitas kemungkinan besar benar.'
                        : 'Kata sandi TIDAK cocok. Verifikasi identitas pemohon lewat jalur lain sebelum reset.'),

                TextColumn::make('attempts')
                    ->label('Pengajuan')
                    ->badge()
                    ->color(fn (int $state): string => $state > 2 ? 'warning' : 'gray'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        PinResetRequest::STATUS_PENDING => 'Menunggu',
                        PinResetRequest::STATUS_RESOLVED => 'Selesai',
                        PinResetRequest::STATUS_REJECTED => 'Ditolak',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        PinResetRequest::STATUS_PENDING => 'danger',
                        PinResetRequest::STATUS_RESOLVED => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('resolver.name')
                    ->label('Diproses oleh')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('resolved_at')
                    ->label('Diproses')
                    ->dateTime('d M Y H:i')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('requested_ip')
                    ->label('IP')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        PinResetRequest::STATUS_PENDING => 'Menunggu',
                        PinResetRequest::STATUS_RESOLVED => 'Selesai',
                        PinResetRequest::STATUS_REJECTED => 'Ditolak',
                    ]),
            ])
            ->recordActions([
                Action::make('resolve')
                    ->label('Buat Kata Sandi Baru')
                    ->icon('heroicon-o-key')
                    ->color('primary')
                    ->visible(fn (PinResetRequest $record): bool => $record->isPending())
                    ->modalHeading('Reset akses pengguna')
                    ->modalDescription('Kata sandi baru berlaku seketika, PIN lama dihapus, dan semua sesi di perangkat mana pun dikeluarkan. Sampaikan kata sandi ini ke pengguna lewat email yang tercatat.')
                    ->modalSubmitActionLabel('Terapkan')
                    ->schema([
                        TextInput::make('password')
                            ->label('Kata Sandi Baru')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8)
                            ->maxLength(72) // bcrypt truncates beyond 72 bytes; refuse rather than silently cut
                            ->helperText('Minimal 8 karakter. Ditentukan oleh Administrator, bukan dibuat otomatis.')
                            ->same('password_confirmation'),
                        TextInput::make('password_confirmation')
                            ->label('Ulangi Kata Sandi Baru')
                            ->password()
                            ->revealable()
                            ->required(),
                        Textarea::make('note')
                            ->label('Catatan (opsional)')
                            ->maxLength(500)
                            ->helperText('Tercatat di audit trail, misalnya bagaimana identitas pemohon diverifikasi.'),
                    ])
                    ->action(function (PinResetRequest $record, array $data, PinResetService $service): void {
                        $service->resolve(
                            $record,
                            Auth::user(),
                            (string) $data['password'],
                            $data['note'] ?? null,
                        );

                        Notification::make()
                            ->success()
                            ->title('Akses direset')
                            ->body('Kata sandi baru aktif, PIN dihapus, semua sesi dikeluarkan. Kirim kata sandi ini ke '.$record->email.'.')
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (PinResetRequest $record): bool => $record->isPending())
                    ->requiresConfirmation()
                    ->modalHeading('Tolak permintaan reset?')
                    ->modalDescription('Tidak ada kredensial yang diubah. Gunakan ini bila identitas pemohon tidak dapat dipastikan.')
                    ->schema([
                        Textarea::make('note')
                            ->label('Alasan')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (PinResetRequest $record, array $data, PinResetService $service): void {
                        $service->reject($record, Auth::user(), (string) $data['note']);

                        Notification::make()
                            ->warning()
                            ->title('Permintaan ditolak')
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }
}
