<?php

namespace App\Services\Access;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\RolePermission;
use App\Models\User;

/**
 * The one place that decides whether a role may view/create/edit/delete on a given panel menu.
 *
 * ADMINISTRATOR is never looked up in `role_permissions` at all — it always passes, before the
 * database is even queried. That is not a shortcut, it is the safety property the whole matrix
 * depends on: the role that edits this table must be structurally unable to lock itself out of
 * editing it, no matter what the table contains. Every other role starts with nothing; a row must
 * exist and name the ability before it is granted.
 *
 * Read once per request and cached here rather than re-queried per resource: a single page render
 * asks this for every item in the navigation menu, and `role_permissions` is small enough to hold
 * in full.
 */
class PermissionMatrix
{
    /** @var array<string, array<string, array<string>>>|null role => module => abilities */
    private static ?array $cache = null;

    public static function can(?User $user, PanelModule $module, string $ability): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->role === Role::ADMINISTRATOR) {
            return true;
        }

        $abilities = static::load()[$user->role->value][$module->value] ?? [];

        return in_array($ability, $abilities, true);
    }

    /** @return array<string> the abilities a role currently holds on a module — for the editor UI. */
    public static function abilitiesFor(Role $role, PanelModule $module): array
    {
        return static::load()[$role->value][$module->value] ?? [];
    }

    /**
     * Whether this role has been granted anything at all.
     *
     * This is what decides panel access for the operational roles (see User::canAccessPanel): a
     * role with no grants has no reason to be let through the door, and one granted a module it
     * cannot reach would be a permission that silently does nothing.
     */
    public static function hasAnyModule(Role $role): bool
    {
        foreach (static::load()[$role->value] ?? [] as $abilities) {
            if ($abilities !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string>  $abilities  subset of ['view','create','edit','delete']
     */
    public static function set(Role $role, PanelModule $module, array $abilities, ?User $updatedBy = null): void
    {
        if ($abilities === []) {
            RolePermission::query()->where('role', $role->value)->where('module', $module->value)->delete();
        } else {
            RolePermission::query()->updateOrCreate(
                ['role' => $role->value, 'module' => $module->value],
                ['abilities' => array_values(array_unique($abilities)), 'updated_by' => $updatedBy?->id],
            );
        }

        static::$cache = null;
    }

    /** Forces the next read to hit the database again — tests rely on this between assertions. */
    public static function forget(): void
    {
        static::$cache = null;
    }

    /** @return array<string, array<string, array<string>>> */
    private static function load(): array
    {
        if (static::$cache !== null) {
            return static::$cache;
        }

        $rows = RolePermission::query()->get(['role', 'module', 'abilities']);

        $out = [];
        foreach ($rows as $row) {
            $out[$row->role->value][$row->module->value] = $row->abilities;
        }

        return static::$cache = $out;
    }
}
