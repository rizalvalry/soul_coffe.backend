<?php

namespace App\Services\Menu;

use App\Enums\PanelModule;
use App\Models\ModuleLabel;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * What every menu and navigation heading in the panel is called.
 *
 * THE ONE RULE THIS ENFORCES
 * --------------------------
 * A name is copy. A key is structure. This service resolves the first and never touches the
 * second: `PanelModule::SALES` stays `sales` in `role_permissions`, in the code and in the tests
 * no matter what an operator types into the naming screen. That is what makes renaming safe — the
 * business flow underneath a menu cannot notice that its label changed.
 *
 * DEGRADES TO DEFAULTS, ALWAYS
 * ----------------------------
 * Every read is wrapped: a missing table (mid-migration, a fresh database, a deploy where the
 * migration has not run yet) returns the built-in names rather than a 500. A panel that cannot
 * render its own navigation because a cosmetic table is absent would be a bad trade for a
 * cosmetic feature.
 *
 * Resolved once per request into a static array. The navigation is rendered on every page of the
 * panel, and a query per menu item per page would be a real cost for a name that changes twice a
 * year.
 */
class MenuLabels
{
    /**
     * Menus that are real but deliberately outside the access matrix — they still have names, and
     * the request was that every name be changeable.
     *
     * @var array<string, string>
     */
    private const EXTRA_MODULES = [
        'news_feed' => 'News Feed',
        'menu_naming' => 'Penamaan Menu',
        'role_matrix' => 'Management Users Role',
    ];

    /**
     * The navigation headings, in the order the panel shows them.
     *
     * @var array<int, string>
     */
    public const GROUPS = [
        'Master Data',
        'Operasional',
        'Absensi',
        'Laporan',
        'Riwayat',
        'Konten',
        'Pengaturan',
    ];

    /** @var array<string, string>|null */
    private static ?array $modules = null;

    /** @var array<string, string>|null */
    private static ?array $groups = null;

    /**
     * Every menu key with its DEFAULT name — the catalogue the naming screen enumerates.
     *
     * Built from PanelModule plus the handful of menus outside it, so a module added to the enum
     * shows up here without anyone remembering to register it twice.
     *
     * @return array<string, string>
     */
    public static function catalogue(): array
    {
        $defaults = [];

        foreach (PanelModule::cases() as $module) {
            $defaults[$module->value] = $module->label();
        }

        return $defaults + self::EXTRA_MODULES;
    }

    /** The name to show for one menu. Accepts the enum or a raw key. */
    public static function for(PanelModule|string $module): string
    {
        $key = $module instanceof PanelModule ? $module->value : $module;

        return self::stored(ModuleLabel::SCOPE_MODULE)[$key] ?? self::catalogue()[$key] ?? $key;
    }

    /** The name to show for one navigation heading. */
    public static function group(string $group): string
    {
        return self::stored(ModuleLabel::SCOPE_GROUP)[$group] ?? $group;
    }

    /**
     * Every heading in panel order, renamed.
     *
     * @return array<int, string>
     */
    public static function groups(): array
    {
        return array_map(static fn (string $group): string => self::group($group), self::GROUPS);
    }

    /**
     * Rename one menu. A blank or unchanged-from-default name deletes the row rather than storing
     * a copy of the default, so "reset to the built-in name" needs no separate concept.
     */
    public static function set(string $scope, string $key, ?string $label): void
    {
        $label = trim((string) $label);
        $default = $scope === ModuleLabel::SCOPE_GROUP ? $key : (self::catalogue()[$key] ?? null);

        if ($label === '' || $label === $default) {
            ModuleLabel::query()->where('scope', $scope)->where('module', $key)->delete();
            self::forget();

            return;
        }

        ModuleLabel::query()->updateOrCreate(
            ['scope' => $scope, 'module' => $key],
            ['label' => $label, 'updated_by' => Auth::id()],
        );

        self::forget();
    }

    /** Drops the per-request cache. Called after every write, and by tests. */
    public static function forget(): void
    {
        self::$modules = null;
        self::$groups = null;
    }

    /**
     * @return array<string, string>
     */
    private static function stored(string $scope): array
    {
        $cached = $scope === ModuleLabel::SCOPE_GROUP ? self::$groups : self::$modules;

        if ($cached !== null) {
            return $cached;
        }

        try {
            $rows = ModuleLabel::query()
                ->where('scope', $scope)
                ->pluck('label', 'module')
                ->all();
        } catch (Throwable) {
            // No table yet, or no database at all. The panel still has to render.
            $rows = [];
        }

        if ($scope === ModuleLabel::SCOPE_GROUP) {
            self::$groups = $rows;
        } else {
            self::$modules = $rows;
        }

        return $rows;
    }
}
