<?php

namespace App\Filament\Concerns;

use App\Services\Menu\MenuLabels;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * Makes a resource's or page's visible name come from the naming screen instead of a constant.
 *
 * Used together with MatrixGoverned, which already requires `panelModule()` — so a menu the
 * access matrix governs is automatically a menu that can be renamed, with no second registration
 * step for anyone to forget.
 *
 * These are trait methods: they override the inherited Filament defaults while still losing to a
 * method the class defines itself, so a resource with a genuinely special case can say so in its
 * own file.
 *
 * Note what is NOT overridden: nothing to do with routing, slugs, permissions or model binding.
 * Renaming "Gerobak" to "Armada" changes a string on screen. The key stays `carts`, the URL stays
 * `/admin/carts`, and the `carts` row in the access matrix keeps governing it. That separation is
 * the point of the feature — see App\Services\Menu\MenuLabels.
 */
trait RenameableModule
{
    public static function getNavigationLabel(): string
    {
        return MenuLabels::for(static::panelModule());
    }

    /** Headings are names too, and were part of the same request. */
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $group = static::$navigationGroup ?? null;

        if ($group === null) {
            return null;
        }

        return MenuLabels::group($group instanceof UnitEnum ? (string) $group->value : (string) $group);
    }

    /**
     * Resource headings and breadcrumbs.
     *
     * Both singular and plural resolve to the same string. Indonesian does not inflect for
     * number, every resource in this panel already sets the two to the same word, and a rename
     * that changed the menu but left the page header saying the old name would be a rename that
     * did not work.
     */
    public static function getModelLabel(): string
    {
        return MenuLabels::for(static::panelModule());
    }

    public static function getPluralModelLabel(): string
    {
        return MenuLabels::for(static::panelModule());
    }

    /** Custom pages carry their own title; resource pages take theirs from the labels above. */
    public function getTitle(): string|Htmlable
    {
        return MenuLabels::for(static::panelModule());
    }
}
