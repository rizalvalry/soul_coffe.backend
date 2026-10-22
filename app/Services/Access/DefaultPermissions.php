<?php

namespace App\Services\Access;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Seeds initial grants and safely merges existing Management grants with Finance. */
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

        // Add Stock reads the Rider location through showcase/staff under this mobile permission.
        self::insert(Role::BARISTA, PanelModule::KITCHEN_OPERATIONS, self::FULL);

        PermissionMatrix::forget();
    }

    /** Merge the Finance template into existing Management rows, snapshotting only changes. */
    public static function mergeManagement(): void
    {
        // Matrix saves lock these same rows first, so the migration cannot race an access edit.
        User::query()->where('role', Role::ADMINISTRATOR->value)
            ->orderBy('id')->lockForUpdate()->get(['id']);
        foreach (PanelModule::cases() as $module) {
            if ($module === PanelModule::ROLE_MATRIX) {
                continue;
            }
            if (DB::table('management_permission_merge_snapshots')->where('module', $module->value)->exists()) {
                continue;
            }

            $finance = DB::table('role_permissions')->where('role', Role::FINANCE->value)
                ->where('module', $module->value)->lockForUpdate()->first();
            $management = DB::table('role_permissions')->where('role', Role::MANAGEMENT->value)
                ->where('module', $module->value)->lockForUpdate()->first();
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
            self::auditManagementChange('role_permission.migration_merged', $module,
                (int) DB::table('role_permissions')->where('role', Role::MANAGEMENT->value)
                    ->where('module', $module->value)->value('id'),
                $management ? $current : null, $merged);
        }

        PermissionMatrix::forget();
    }

    /** Restore migration-created grants only if nobody changed them afterward. */
    public static function restoreManagement(): void
    {
        User::query()->where('role', Role::ADMINISTRATOR->value)
            ->orderBy('id')->lockForUpdate()->get(['id']);
        $snapshots = DB::table('management_permission_merge_snapshots')->orderBy('module')->get();
        $toRestore = [];
        foreach ($snapshots as $snapshot) {
            $current = DB::table('role_permissions')->where('role', Role::MANAGEMENT->value)
                ->where('module', $snapshot->module)->lockForUpdate()->first();
            if ($snapshot->role_permission_id === null && $current === null) {
                continue;
            }
            if ($snapshot->role_permission_id !== null && $current?->id === $snapshot->role_permission_id
                && json_decode($current->abilities, true, 512, JSON_THROW_ON_ERROR)
                    === json_decode($snapshot->prior_abilities, true, 512, JSON_THROW_ON_ERROR)
                && $current->updated_at === $snapshot->prior_updated_at) {
                continue;
            }
            if ($current === null || $current->id !== $snapshot->applied_role_permission_id
                || json_decode($current->abilities, true, 512, JSON_THROW_ON_ERROR)
                    !== json_decode($snapshot->applied_abilities, true, 512, JSON_THROW_ON_ERROR)) {
                throw new \RuntimeException('Hak Management berubah setelah migrasi untuk modul '.$snapshot->module.'. Rollback dibatalkan agar snapshot tetap tersimpan.');
            }
            $toRestore[] = $snapshot;
        }

        foreach ($toRestore as $snapshot) {
            $module = PanelModule::from($snapshot->module);
            $applied = json_decode($snapshot->applied_abilities, true, 512, JSON_THROW_ON_ERROR);
            $prior = $snapshot->prior_abilities === null ? null
                : json_decode($snapshot->prior_abilities, true, 512, JSON_THROW_ON_ERROR);
            if ($snapshot->role_permission_id === null) {
                DB::table('role_permissions')->where('id', $snapshot->applied_role_permission_id)->delete();
            } else {
                DB::table('role_permissions')->where('id', $snapshot->applied_role_permission_id)->update([
                    'abilities' => $snapshot->prior_abilities,
                    'updated_at' => $snapshot->prior_updated_at,
                ]);
            }
            self::auditManagementChange('role_permission.migration_restored', $module,
                (int) $snapshot->applied_role_permission_id, $applied, $prior);
        }
        PermissionMatrix::forget();
    }

    private static function auditManagementChange(string $action, PanelModule $module, int $rowId, ?array $before, ?array $after): void
    {
        AuditLog::query()->create([
            'actor_id' => null,
            'actor_role' => 'SYSTEM',
            'action' => $action,
            'subject_type' => RolePermission::class,
            'subject_id' => $rowId,
            'before_json' => ['role' => Role::MANAGEMENT->value, 'module' => $module->value,
                'abilities' => $before, 'reason' => 'Sinkronisasi hak Management dan Finance (T-057)'],
            'after_json' => ['role' => Role::MANAGEMENT->value, 'module' => $module->value,
                'abilities' => $after, 'reason' => 'Sinkronisasi hak Management dan Finance (T-057)'],
        ]);
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
