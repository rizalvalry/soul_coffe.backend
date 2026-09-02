<?php

namespace App\Filament\Concerns;

use App\Enums\Role;
use Illuminate\Support\Facades\Auth;

/**
 * Restricts a Filament resource to ADMINISTRATOR.
 *
 * Panel access alone stopped being the authorisation the moment CONTENT_CREATOR was given a way
 * in to write the news feed. Without this, that role would inherit the whole panel — users,
 * prices, carts, assignments — because Filament allows a resource by default when no policy
 * exists.
 *
 * Applied to every resource except NewsPostResource. `AdminPanelAccessTest` enumerates the
 * panel's registered resources and fails if a new one is added without it, so this cannot be
 * forgotten later.
 */
trait AdministratorOnly
{
    public static function canViewAny(): bool
    {
        return Auth::user()?->role === Role::ADMINISTRATOR;
    }

    public static function canView(mixed $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(mixed $record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete(mixed $record): bool
    {
        return static::canViewAny();
    }

    public static function canDeleteAny(): bool
    {
        return static::canViewAny();
    }
}
