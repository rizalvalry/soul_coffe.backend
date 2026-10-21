<?php

namespace App\Filament\Resources\Users;

use App\Enums\PanelModule;
use App\Models\User;
use App\Services\Access\PermissionMatrix;

final class UserAccessForm
{
    /** @return array{0: array, 1: array, 2: ?bool} */
    public static function extract(array &$data, ?User $actor): array
    {
        $choices = $data['access_grants'] ?? [];
        $reasons = $data['access_reasons'] ?? [];
        $superAdmin = array_key_exists('is_super_admin', $data) ? (bool) $data['is_super_admin'] : null;
        unset($data['access_grants'], $data['access_reasons'], $data['is_super_admin']);

        if (($choices !== [] || $reasons !== []) && ! PermissionMatrix::canManagePermissions($actor)) {
            abort(403);
        }
        if ($superAdmin === true && ! $actor?->is_super_admin) {
            abort(403);
        }
        if (! $actor?->is_super_admin) {
            $superAdmin = null;
        }

        return [$choices, $reasons, $superAdmin];
    }

    public static function apply(User $actor, User $target, array $choices, array $reasons): void
    {
        $changed = false;
        foreach (PanelModule::cases() as $module) {
            if ($module === PanelModule::ROLE_MATRIX) {
                abort_unless(($choices[$module->value] ?? 'template') === 'template', 422);

                continue;
            }
            $choice = $choices[$module->value] ?? 'template';
            abort_unless(in_array($choice, ['template', 'none', 'view', 'edit'], true), 422);
            $row = $target->permissions()->where('module', $module->value)->first();
            if ($choice === 'template') {
                if ($row !== null) {
                    $changed = PermissionMatrix::setForUser($actor, $target, $module, null, notify: false) || $changed;
                }

                continue;
            }

            $abilities = match ($choice) {
                'none' => [],
                'view' => ['view'],
                'edit' => $module->isReadOnly() ? ['view'] : ['view', 'create', 'edit', 'delete'],
            };
            $reason = trim((string) ($reasons[$module->value] ?? ''));
            $reason = $reason === '' ? null : $reason;
            abort_unless($reason === null || mb_strlen($reason) <= 255, 422);
            if ($row === null || $row->abilities !== $abilities || $row->reason !== $reason) {
                $changed = PermissionMatrix::setForUser($actor, $target, $module, $abilities, $reason, notify: false) || $changed;
            }
        }
        if ($changed) {
            PermissionMatrix::notifyUserChange($target);
        }
    }
}
