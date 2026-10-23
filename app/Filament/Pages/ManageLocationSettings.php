<?php

namespace App\Filament\Pages;

use App\Enums\PanelModule;
use App\Filament\Concerns\RenameableModule;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Services\Access\PermissionMatrix;
use App\Services\LocationSettings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ManageLocationSettings extends Page
{
    use RenameableModule;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Pengaturan Lokasi';

    protected static ?string $title = 'Pengaturan Lokasi';

    protected string $view = 'filament.pages.manage-location-settings';

    public array $defaults = [];

    public string $defaultReason = '';

    public int $settingsVersion = 0;

    public array $kitchenRadii = [];

    public array $kitchenReasons = [];

    public array $kitchenUseDefault = [];

    public array $locationRadii = [];

    public array $locationReasons = [];

    public array $locationUseDefault = [];

    public static function panelModule(): PanelModule
    {
        return PanelModule::LOCATION_SETTINGS;
    }

    public static function canAccess(): bool
    {
        return PermissionMatrix::can(Auth::user(), static::panelModule(), 'view');
    }

    public function canEdit(): bool
    {
        return PermissionMatrix::can(Auth::user(), static::panelModule(), 'edit');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->reloadValues();
    }

    public function saveDefaults(): void
    {
        app(LocationSettings::class)->saveDefaults(Auth::user(), $this->defaults, $this->defaultReason, $this->settingsVersion);
        $this->defaultReason = '';
        $this->reloadValues();
        Notification::make()->title('Pengaturan bawaan tersimpan ya.')->success()->send();
    }

    public function saveKitchen(int $id): void
    {
        $kitchen = CentralKitchen::query()->where('is_active', true)->findOrFail($id);
        app(LocationSettings::class)->saveRadius(Auth::user(), $kitchen,
            $this->kitchenRadii[$id] ?? '', $this->kitchenReasons[$id] ?? '',
            useDefault: (bool) ($this->kitchenUseDefault[$id] ?? false));
        $this->kitchenReasons[$id] = '';
        $this->reloadValues();
        Notification::make()->title('Radius dapur tersimpan ya.')->success()->send();
    }

    public function saveLocation(int $id): void
    {
        app(LocationSettings::class)->saveRadius(Auth::user(), Location::query()->findOrFail($id),
            $this->locationRadii[$id] ?? '', $this->locationReasons[$id] ?? '',
            useDefault: (bool) ($this->locationUseDefault[$id] ?? false));
        $this->locationReasons[$id] = '';
        $this->reloadValues();
        Notification::make()->title('Radius titik jualan tersimpan ya.')->success()->send();
    }

    private function reloadValues(): void
    {
        $snapshot = app(LocationSettings::class)->snapshot();
        $this->defaults = $snapshot['values'];
        $this->settingsVersion = $snapshot['version'];
        $kitchens = CentralKitchen::query()->where('is_active', true)->get(['id', 'geofence_m']);
        $locations = Location::query()->get(['id', 'geofence_m']);
        $this->kitchenUseDefault = $kitchens->mapWithKeys(fn (CentralKitchen $row): array => [$row->id => ! (bool) $row->geofence_m])->all();
        $this->kitchenRadii = $kitchens->mapWithKeys(fn (CentralKitchen $row): array => [$row->id => $row->geofence_m ?: $this->defaults['absen_radius_m']])->all();
        $this->locationUseDefault = $locations->mapWithKeys(fn (Location $row): array => [$row->id => ! (bool) $row->geofence_m])->all();
        $this->locationRadii = $locations->mapWithKeys(fn (Location $row): array => [$row->id => $row->geofence_m ?: $this->defaults['absen_radius_m']])->all();
    }
}
