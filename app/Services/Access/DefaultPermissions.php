<?php

namespace App\Services\Access;

use App\Enums\PanelModule;
use App\Enums\Role;
use Illuminate\Support\Facades\DB;

/** Initial grants from main@271ec22. Existing role rows always win. */
final class DefaultPermissions
{
    private const FULL = ['view', 'create', 'edit', 'delete'];

    /** Modules introduced by T-057 had no earlier role template and must start fully open. */
    private const NEW_MODULES = [
        PanelModule::RIDER_LOCATION_SETUP, PanelModule::MANAGEMENT_REPORTS, PanelModule::PAYROLL,
        PanelModule::PAYROLL_PRIOR_WITHHOLDING, PanelModule::TAX_SETTINGS, PanelModule::ACCOUNTING_PERIODS,
        PanelModule::OPENING_BALANCES, PanelModule::CHART_OF_ACCOUNTS, PanelModule::JOURNALS,
        PanelModule::JOURNAL_REVERSALS, PanelModule::GENERAL_LEDGER, PanelModule::CASH,
        PanelModule::CLEARING, PanelModule::FIXED_ASSETS, PanelModule::FIXED_ASSET_DEPRECIATION,
        PanelModule::INVENTORY_COSTS, PanelModule::REFILL_APPROVAL, PanelModule::EMPLOYEES,
        PanelModule::MENU_NAMING, PanelModule::NEWS_FEED, PanelModule::DELIVERY_INCIDENT_DECISIONS,
        PanelModule::SALE_CORRECTIONS, PanelModule::ALLOCATIONS, PanelModule::CLOSING_LOCATION_EVIDENCE,
        PanelModule::DIRECT_SALE_ACTIONS, PanelModule::PURCHASE_ORDER_ACTIONS,
        PanelModule::STOCK_OPNAME_ACTIONS, PanelModule::STOCK_ADJUSTMENTS,
    ];

    public static function seed(): void
    {
        foreach (PanelModule::cases() as $module) {
            self::insert(Role::ADMINISTRATOR, $module, self::FULL);
        }
        self::insert(Role::CONTENT_CREATOR, PanelModule::NEWS_FEED, self::FULL);

        // Owner's UAT template opens every back-office feature to Finance and Management.
        // Existing rows are kept, including deliberate grants already edited in production.
        foreach ([Role::FINANCE, Role::MANAGEMENT] as $role) {
            foreach (PanelModule::cases() as $module) {
                if ($module !== PanelModule::ROLE_MATRIX) {
                    self::insert($role, $module, self::FULL);
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

    /** Merge the Finance template into existing Management rows, snapshotting only changes. */
    public static function mergeManagement(): void
    {
        foreach (PanelModule::cases() as $module) {
            if ($module === PanelModule::ROLE_MATRIX) {
                continue;
            }
            if (DB::table('management_permission_merge_snapshots')->where('module', $module->value)->exists()) {
                continue;
            }

            $finance = DB::table('role_permissions')->where('role', Role::FINANCE->value)
                ->where('module', $module->value)->first();
            $management = DB::table('role_permissions')->where('role', Role::MANAGEMENT->value)
                ->where('module', $module->value)->first();
            $current = $management ? json_decode($management->abilities, true, 512, JSON_THROW_ON_ERROR) : [];
            $financeAbilities = $finance ? json_decode($finance->abilities, true, 512, JSON_THROW_ON_ERROR) : [];
            $required = in_array($module, self::NEW_MODULES, true) ? self::FULL : [];
            $merged = array_values(array_filter(self::FULL,
                fn (string $ability): bool => in_array($ability, $current, true)
                    || in_array($ability, $financeAbilities, true)
                    || in_array($ability, $required, true)));

            if ($current === $merged || ($management === null && $merged === [])) {
                continue;
            }

            DB::table('management_permission_merge_snapshots')->insertOrIgnore([
                'module' => $module->value,
                'role_permission_id' => $management?->id,
                'prior_abilities' => $management?->abilities,
                'prior_updated_at' => $management?->updated_at,
                'applied_abilities' => json_encode($merged, JSON_THROW_ON_ERROR),
            ]);

            if ($management) {
                DB::table('role_permissions')->where('id', $management->id)->update([
                    'abilities' => json_encode($merged, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            } else {
                self::insert(Role::MANAGEMENT, $module, $merged);
            }
            DB::table('management_permission_merge_snapshots')->where('module', $module->value)->update([
                'applied_role_permission_id' => DB::table('role_permissions')
                    ->where('role', Role::MANAGEMENT->value)->where('module', $module->value)->value('id'),
            ]);
        }

        PermissionMatrix::forget();
    }

    /** Restore migration-created grants only if nobody changed them afterward. */
    public static function restoreManagement(): void
    {
        foreach (DB::table('management_permission_merge_snapshots')->get() as $snapshot) {
            $current = DB::table('role_permissions')->where('role', Role::MANAGEMENT->value)
                ->where('module', $snapshot->module)->first();
            if ($current === null || $current->id !== $snapshot->applied_role_permission_id
                || json_decode($current->abilities, true, 512, JSON_THROW_ON_ERROR)
                    !== json_decode($snapshot->applied_abilities, true, 512, JSON_THROW_ON_ERROR)) {
                continue;
            }

            if ($snapshot->role_permission_id === null) {
                DB::table('role_permissions')->where('id', $current->id)->delete();
            } else {
                DB::table('role_permissions')->where('id', $current->id)->update([
                    'abilities' => $snapshot->prior_abilities,
                    'updated_at' => $snapshot->prior_updated_at,
                ]);
            }
        }
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
