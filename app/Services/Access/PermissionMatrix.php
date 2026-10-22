<?php

namespace App\Services\Access;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\EventPublisher;
use Illuminate\Support\Facades\DB;

/** Resolves role templates, account overrides, and the Super Administrator bypass. */
class PermissionMatrix
{
    /** @var array<string, array<string, array<string>>>|null role => module => abilities */
    private static ?array $cache = null;

    /** @var array<int, array<string, array<string>>> */
    private static array $userCache = [];

    public static function can(?User $user, PanelModule $module, string $ability): bool
    {
        if ($user === null || ! $user->is_active) {
            return false;
        }

        if ($user->is_super_admin && $user->role === Role::ADMINISTRATOR) {
            return true;
        }

        return in_array($ability, static::effectiveAbilitiesFor($user, $module), true);
    }

    public static function effectiveAbilitiesFor(User $user, PanelModule $module): array
    {
        if (! $user->is_active) {
            return [];
        }
        if ($module === PanelModule::ROLE_MATRIX) {
            return static::canManagePermissions($user) ? ['view', 'create', 'edit', 'delete'] : [];
        }
        if ($user->is_super_admin && $user->role === Role::ADMINISTRATOR) {
            return ['view', 'create', 'edit', 'delete'];
        }

        if (! array_key_exists($user->id, static::$userCache)) {
            static::$userCache[$user->id] = UserPermission::query()->where('user_id', $user->id)
                ->get(['module', 'abilities'])->pluck('abilities', 'module')->all();
        }

        return static::$userCache[$user->id][$module->value]
            ?? static::abilitiesFor($user->role, $module);
    }

    public static function allFor(User $user): array
    {
        $abilities = [];
        foreach (PanelModule::cases() as $module) {
            $abilities[$module->value] = static::effectiveAbilitiesFor($user, $module);
        }

        return $abilities;
    }

    public static function usersWith(PanelModule $module, string $ability = 'view'): array
    {
        if ($module === PanelModule::ROLE_MATRIX) {
            return User::query()->where('role', Role::ADMINISTRATOR->value)
                ->where('is_active', true)->get(['id', 'role', 'is_super_admin', 'is_active'])
                ->filter(fn (User $user): bool => static::canManagePermissions($user))->pluck('id')->all();
        }
        $roleCodes = RolePermission::query()->where('module', $module->value)
            ->whereJsonContains('abilities', $ability)->pluck('role')
            ->map(fn ($role): string => $role instanceof Role ? $role->value : (string) $role)->all();

        return User::query()->where('is_active', true)
            ->where(function ($query) use ($module, $ability, $roleCodes): void {
                $query->where(function ($super): void {
                    $super->where('role', Role::ADMINISTRATOR->value)->where('is_super_admin', true);
                })->orWhereIn('id', UserPermission::query()->select('user_id')
                    ->where('module', $module->value)->whereJsonContains('abilities', $ability))
                    ->orWhere(function ($inherited) use ($module, $roleCodes): void {
                        $inherited->whereIn('role', $roleCodes)
                            ->whereNotIn('id', UserPermission::query()->select('user_id')
                                ->where('module', $module->value));
                    });
            })->pluck('id')->all();
    }

    public static function canManagePermissions(?User $user): bool
    {
        return $user !== null && $user->is_active && $user->role === Role::ADMINISTRATOR
            && ($user->is_super_admin || ! User::query()
                ->where('role', Role::ADMINISTRATOR->value)
                ->where('is_super_admin', true)->where('is_active', true)->exists());
    }

    /** @return array<string> the abilities a role currently holds on a module — for the editor UI. */
    public static function abilitiesFor(Role $role, PanelModule $module): array
    {
        return static::ownAbilitiesFor($role, $module);
    }

    /** @return array<string> explicitly granted abilities, excluding Management inheritance */
    public static function ownAbilitiesFor(Role $role, PanelModule $module): array
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
        foreach (static::load()[$role->value] ?? [] as $module => $abilities) {
            if (PanelModule::tryFrom($module) !== null && $abilities !== []) {
                return true;
            }
        }

        return false;
    }

    public static function hasAnyAccess(User $user): bool
    {
        foreach (PanelModule::cases() as $module) {
            $entryAbility = $module->panelEntryAbility();
            if ($entryAbility !== null && in_array($entryAbility, static::effectiveAbilitiesFor($user, $module), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string>  $abilities  subset of ['view','create','edit','delete']
     */
    public static function set(Role $role, PanelModule $module, array $abilities, ?User $updatedBy = null, bool $notify = true): bool
    {
        $abilities = static::normalize($abilities);
        $changed = DB::transaction(function () use ($role, $module, $abilities, $updatedBy, $notify): bool {
            if ($updatedBy !== null) {
                User::query()->where('role', Role::ADMINISTRATOR->value)
                    ->orderBy('id')->lockForUpdate()->get(['id']);
                $updatedBy->refresh();
                abort_unless(static::canManagePermissions($updatedBy), 403);
            }
            $row = RolePermission::query()->where('role', $role->value)->where('module', $module->value)->first();
            $before = $row?->abilities;
            if ($before === $abilities || ($before === null && $abilities === [])) {
                return false;
            }
            if ($abilities === []) {
                $row?->delete();
            } elseif ($row === null) {
                $row = RolePermission::query()->create([
                    'role' => $role, 'module' => $module->value,
                    'abilities' => $abilities, 'updated_by' => $updatedBy?->id,
                ]);
            } else {
                $row->update(['abilities' => $abilities, 'updated_by' => $updatedBy?->id]);
            }
            if ($updatedBy !== null) {
                AuditLog::recordAction($updatedBy, 'role_permission.changed', $row,
                    ['role' => $role->value, 'module' => $module->value, 'abilities' => $before],
                    ['role' => $role->value, 'module' => $module->value, 'abilities' => $abilities]);
                if ($notify) {
                    static::notifyRoleChange($role);
                }
            }

            return true;
        });

        static::$cache = null;

        return $changed;
    }

    /** Publish once per committed role edit; callers aggregate multi-module form saves. */
    public static function notifyRoleChange(Role $role): void
    {
        $channels = User::query()->where('role', $role->value)->where('is_active', true)
            ->pluck('id')->map(fn ($id): string => 'user.'.$id)->all();
        app(EventPublisher::class)->publish('AccessChanged', 'Hak akses diperbarui',
            'Menu kamu sudah diperbarui ya.', $channels);
    }

    public static function setForUser(User $actor, User $target, PanelModule $module, ?array $abilities, ?string $reason = null, bool $notify = true): bool
    {
        abort_if($module === PanelModule::ROLE_MATRIX, 422);
        $changed = DB::transaction(function () use ($actor, $target, $module, $abilities, $reason, $notify): bool {
            User::query()->where('role', Role::ADMINISTRATOR->value)
                ->orderBy('id')->lockForUpdate()->get(['id']);
            $actor->refresh();
            abort_unless(static::canManagePermissions($actor) && ! $target->fresh()->is_super_admin, 403);
            $row = UserPermission::query()->where('user_id', $target->id)->where('module', $module->value)->first();
            $before = $row === null ? null : ['abilities' => $row->abilities, 'reason' => $row->reason];
            $after = $abilities === null ? null : ['abilities' => static::normalize($abilities), 'reason' => $reason];
            if ($before === $after) {
                return false;
            }
            if ($after === null) {
                $row?->delete();
            } elseif ($row === null) {
                $row = UserPermission::query()->create([
                    'user_id' => $target->id, 'module' => $module->value,
                    ...$after, 'updated_by' => $actor->id,
                ]);
            } else {
                $row->update([...$after, 'updated_by' => $actor->id]);
            }
            AuditLog::recordAction($actor, 'user_permission.changed', $row,
                ['user_id' => $target->id, 'module' => $module->value, 'override' => $before],
                ['user_id' => $target->id, 'module' => $module->value, 'override' => $after]);
            if ($notify) {
                static::notifyUserChange($target);
            }

            return true;
        });
        unset(static::$userCache[$target->id]);

        return $changed;
    }

    public static function notifyUserChange(User $target): void
    {
        app(EventPublisher::class)->publish('AccessChanged', 'Hak akses diperbarui',
            'Menu kamu sudah diperbarui ya.', ['user.'.$target->id]);
    }

    private static function normalize(array $abilities): array
    {
        abort_unless(array_diff($abilities, ['view', 'create', 'edit', 'delete']) === [], 422);
        $abilities = array_values(array_unique($abilities));
        if ($abilities !== [] && ! in_array('view', $abilities, true)) {
            array_unshift($abilities, 'view');
        }

        return $abilities;
    }

    /** Forces the next read to hit the database again — tests rely on this between assertions. */
    public static function forget(): void
    {
        static::$cache = null;
        static::$userCache = [];
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
            // Retired module rows stay in the database for rollback, but grant no panel access.
            if (PanelModule::tryFrom($row->module) === null) {
                continue;
            }

            $out[$row->role->value][$row->module] = $row->abilities;
        }

        return static::$cache = $out;
    }
}
