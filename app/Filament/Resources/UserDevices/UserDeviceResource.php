<?php

namespace App\Filament\Resources\UserDevices;

use App\Enums\PanelModule;
use App\Filament\Concerns\MatrixGoverned;
use App\Filament\Resources\UserDevices\Pages\ListUserDevices;
use App\Models\UserDevice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class UserDeviceResource extends Resource
{
    use MatrixGoverned;

    protected static ?string $model = UserDevice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?string $navigationLabel = 'Perangkat Login';

    protected static ?string $modelLabel = 'Perangkat Login';

    protected static ?int $navigationSort = 2;

    public static function panelModule(): PanelModule
    {
        return PanelModule::DEVICES;
    }

    public static function table(Table $table): Table
    {
        return Tables\UserDevicesTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }
        $count = UserDevice::query()->where('status', UserDevice::PENDING)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return ['index' => ListUserDevices::route('/')];
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
