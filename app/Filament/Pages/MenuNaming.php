<?php

namespace App\Filament\Pages;

use App\Enums\Role;
use App\Models\ModuleLabel;
use App\Services\Menu\MenuLabels;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * "Penamaan Menu" — rename anything in the sidebar without touching what it does.
 *
 * WHY THIS EXISTS
 * ---------------
 * The words in this panel were chosen by whoever built it. The people who use it every day have
 * their own words, and up to now changing one meant editing code and shipping a release. This
 * screen makes the name a piece of data.
 *
 * WHAT IT CANNOT BREAK, AND WHY THAT IS STRUCTURAL
 * ------------------------------------------------
 * Every menu has a KEY and a LABEL. The key (`carts`, `sales`, `attendance`) is what the access
 * matrix stores, what the URLs are built from, and what the tests assert. This screen writes only
 * labels, into their own table, and nothing reads that table except the navigation. So renaming
 * "Gerobak" to "Armada" leaves `/admin/carts` reachable, leaves every `carts` permission granted,
 * and leaves the refill flow untouched — which is exactly the guarantee that was asked for before
 * going any further with the system.
 *
 * ADMINISTRATOR ONLY, deliberately, and hardcoded rather than governed by the matrix — the same
 * reasoning as the access matrix itself. A screen that renames every menu in the panel, including
 * the one that hands out permissions, must not be reachable through a permission somebody could
 * grant themselves by accident.
 */
class MenuNaming extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?int $navigationSort = 9;

    protected string $view = 'filament.pages.menu-naming';

    /**
     * Menu key => the name being edited.
     *
     * @var array<string, string>
     */
    public array $modules = [];

    /**
     * Group heading => the name being edited.
     *
     * @var array<string, string>
     */
    public array $groups = [];

    public static function getNavigationLabel(): string
    {
        return MenuLabels::for('menu_naming');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return MenuLabels::group('Pengaturan');
    }

    public function getTitle(): string
    {
        return MenuLabels::for('menu_naming');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === Role::ADMINISTRATOR;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        foreach (MenuLabels::catalogue() as $key => $default) {
            $this->modules[$key] = MenuLabels::for($key);
        }

        foreach (MenuLabels::GROUPS as $group) {
            $this->groups[$group] = MenuLabels::group($group);
        }
    }

    /**
     * The built-in name for one key, shown beside the input so an operator can always see what
     * they are overriding and what "kosongkan" restores.
     */
    public function defaultFor(string $key): string
    {
        return MenuLabels::catalogue()[$key] ?? $key;
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        foreach ($this->modules as $key => $label) {
            MenuLabels::set(ModuleLabel::SCOPE_MODULE, (string) $key, $label);
        }

        foreach ($this->groups as $group => $label) {
            MenuLabels::set(ModuleLabel::SCOPE_GROUP, (string) $group, $label);
        }

        // Re-read so a blanked field visibly snaps back to its built-in name rather than staying
        // empty and looking saved.
        $this->mount();

        Notification::make()
            ->success()
            ->title('Penamaan menu disimpan')
            ->body('Nama baru langsung dipakai di seluruh panel. Hak akses, alamat halaman, dan alur bisnisnya tidak berubah.')
            ->send();
    }

    /** Puts every name back to the one this build shipped with. */
    public function resetAll(): void
    {
        abort_unless(static::canAccess(), 403);

        ModuleLabel::query()->delete();
        MenuLabels::forget();

        $this->mount();

        Notification::make()
            ->success()
            ->title('Semua nama dikembalikan ke bawaan')
            ->send();
    }
}
