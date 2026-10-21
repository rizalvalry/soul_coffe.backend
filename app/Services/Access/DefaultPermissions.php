<?php

namespace App\Services\Access;

use App\Enums\PanelModule;
use App\Enums\Role;
use Illuminate\Support\Facades\DB;

/** Initial grants from main@271ec22. Existing role rows always win. */
final class DefaultPermissions
{
    public static function seed(): void
    {
        $full = ['view', 'create', 'edit', 'delete'];

        foreach (PanelModule::cases() as $module) {
            self::insert(Role::ADMINISTRATOR, $module, $full);
        }
        self::insert(Role::CONTENT_CREATOR, PanelModule::NEWS_FEED, $full);

        // Owner's UAT template opens every back-office feature to Finance and Management.
        // Existing rows are kept, including deliberate grants already edited in production.
        foreach ([Role::FINANCE, Role::MANAGEMENT] as $role) {
            foreach (PanelModule::cases() as $module) {
                if ($module !== PanelModule::ROLE_MATRIX) {
                    self::insert($role, $module, $full);
                }
            }
        }

        // Staff still need to read their own approved payslip through the API.
        foreach ([Role::BARISTA, Role::STAFF, Role::RIDER, Role::CONTENT_CREATOR] as $role) {
            self::insert($role, PanelModule::PAYROLL, ['view']);
        }

        self::insert(Role::BARISTA, PanelModule::RIDER_LOCATION_SETUP, ['view']);

        PermissionMatrix::forget();
    }

    private static function insert(Role $role, PanelModule $module, array $abilities): void
    {
        DB::table('role_permissions')->insertOrIgnore([
            'role' => $role->value,
            'module' => $module->value,
            'abilities' => json_encode($abilities, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
