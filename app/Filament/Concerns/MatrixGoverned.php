<?php

namespace App\Filament\Concerns;

use App\Enums\PanelModule;
use App\Services\Access\PermissionMatrix;
use Illuminate\Support\Facades\Auth;

/**
 * Gates a Filament resource through the access matrix (App\Services\Access\PermissionMatrix)
 * instead of a hardcoded role check.
 *
 * Formerly `AdministratorOnly`, which is what every resource using this trait still gets by
 * default: ADMINISTRATOR always passes (see PermissionMatrix::can()), and every other role starts
 * with nothing, because no `role_permissions` row exists for it out of the box. The behaviour
 * changes only once an Administrator opens "Management Users Role" and grants another role a
 * module — until then this trait is exactly as restrictive as the one it replaced.
 *
 * Filament allows a resource by default when no policy exists, so every resource that reaches the
 * panel must use this trait (or define its own authorisation) — `MatrixResourcesTest` enumerates
 * the panel's registered resources and fails if a new one is added without either.
 */
trait MatrixGoverned
{
    // A menu the matrix governs is a menu that can be renamed. Both hang off the same
    // `panelModule()` key, so there is no second place to register anything — see
    // App\Filament\Concerns\RenameableModule.
    use RenameableModule;

    public static function canViewAny(): bool
    {
        return PermissionMatrix::can(Auth::user(), static::panelModule(), 'view');
    }

    public static function canView(mixed $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return PermissionMatrix::can(Auth::user(), static::panelModule(), 'create');
    }

    public static function canEdit(mixed $record): bool
    {
        return PermissionMatrix::can(Auth::user(), static::panelModule(), 'edit');
    }

    public static function canDelete(mixed $record): bool
    {
        return PermissionMatrix::can(Auth::user(), static::panelModule(), 'delete');
    }

    public static function canDeleteAny(): bool
    {
        return static::canDelete(null);
    }

    /** Which row of the matrix governs this resource. */
    abstract public static function panelModule(): PanelModule;
}
