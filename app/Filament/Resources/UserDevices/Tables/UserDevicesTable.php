<?php

namespace App\Filament\Resources\UserDevices\Tables;

use App\Enums\PanelModule;
use App\Models\DeviceLoginAttempt;
use App\Models\UserDevice;
use App\Services\Access\PermissionMatrix;
use App\Services\DeviceBindingService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class UserDevicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.name')->label('Akun')->searchable(),
                TextColumn::make('device_name')->label('Nama HP')->searchable(),
                TextColumn::make('model')->label('Model')->placeholder('-'),
                TextColumn::make('device_id')->label('Android ID')
                    ->formatStateUsing(fn (UserDevice $record): string => $record->maskedId()),
                TextColumn::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (UserDevice $record): string => $record->statusLabel())
                    ->color(fn (string $state): string => match ($state) {
                        UserDevice::ACTIVE => 'success',
                        UserDevice::PENDING => 'warning',
                        UserDevice::REJECTED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('app_version')->label('Versi app')->placeholder('-'),
                TextColumn::make('last_login_at')->label('Login terakhir')->dateTime('d M Y H:i')->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    UserDevice::ACTIVE => 'Aktif', UserDevice::PENDING => 'Menunggu',
                    UserDevice::REJECTED => 'Ditolak', UserDevice::REVOKED => 'Dilepas',
                ]),
                SelectFilter::make('user_id')->label('Akun')
                    ->relationship('user', 'name')->searchable(),
            ])
            ->recordActions([
                Action::make('history')->label('Riwayat akun')->icon('heroicon-o-clock')
                    ->modalHeading('Riwayat login akun')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (UserDevice $record): View => view('filament.device-login-history', [
                        'attempts' => DeviceLoginAttempt::query()->where('user_id', $record->user_id)
                            ->latest()->limit(50)->get(),
                    ])),
                ...array_map(function (string $action): Action {
                    return Action::make($action)
                        ->label(match ($action) {
                            'approve' => 'Setujui', 'reject' => 'Tolak', default => 'Lepas perangkat',
                        })
                        ->visible(fn (UserDevice $record): bool => PermissionMatrix::can(Auth::user(), PanelModule::DEVICES, 'edit')
                            && ($action === 'revoke' ? $record->status !== UserDevice::REVOKED
                                : $record->status === UserDevice::PENDING))
                        ->requiresConfirmation()
                        ->schema([Textarea::make('reason')->label('Alasan')->required()->maxLength(500)])
                        ->action(function (UserDevice $record, array $data, DeviceBindingService $service) use ($action): void {
                            $service->decide($record, Auth::user(), $action, $data['reason']);
                            Notification::make()->success()->title('Perangkat diperbarui')->send();
                        });
                }, ['approve', 'reject', 'revoke']),
            ]);
    }
}
