<?php

namespace App\Filament\Resources\DeviceLoginAttempts;

use App\Enums\PanelModule;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\DeviceLoginAttempts\Pages\ListDeviceLoginAttempts;
use App\Models\DeviceLoginAttempt;
use App\Models\UserDevice;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class DeviceLoginAttemptResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = DeviceLoginAttempt::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function panelModule(): PanelModule
    {
        return PanelModule::DEVICES;
    }

    public static function getModelLabel(): string
    {
        return 'Riwayat Login';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Riwayat Login';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i')->sortable(),
            TextColumn::make('user.name')->label('Akun')->placeholder('Akun tidak dikenal')->searchable(),
            TextColumn::make('device_name')->label('Nama HP')->placeholder('HP tidak dikenal'),
            TextColumn::make('device_id')->label('Android ID')
                ->formatStateUsing(fn (?string $state): string => UserDevice::maskId($state)),
            TextColumn::make('model')->label('Model')->placeholder('-'),
            TextColumn::make('succeeded')->label('Hasil')->badge()
                ->formatStateUsing(fn (bool $state): string => $state ? 'Berhasil' : 'Ditolak')
                ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
            TextColumn::make('reason')->label('Alasan')
                ->formatStateUsing(fn (DeviceLoginAttempt $record): string => $record->reasonLabel()),
            TextColumn::make('ip')->label('IP')->placeholder('-'),
        ])->filters([
            SelectFilter::make('user_id')->label('Akun')->relationship('user', 'name')->searchable(),
            SelectFilter::make('succeeded')->label('Hasil')->options(['1' => 'Berhasil', '0' => 'Ditolak']),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDeviceLoginAttempts::route('/')];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
