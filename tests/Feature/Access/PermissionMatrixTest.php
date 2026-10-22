<?php

namespace Tests\Feature\Access;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\RoleAccessMatrix;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\UserAccessForm;
use App\Filament\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\CentralKitchen;
use App\Models\OutboxEvent;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Access\DefaultPermissions;
use App\Services\Access\PermissionMatrix;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_templates_cover_every_module_without_role_inheritance(): void
    {
        foreach (PanelModule::cases() as $module) {
            $this->assertSame(['view', 'create', 'edit', 'delete'],
                PermissionMatrix::abilitiesFor(Role::ADMINISTRATOR, $module), $module->value);

            foreach ([Role::FINANCE, Role::MANAGEMENT] as $role) {
                $user = User::factory()->role($role)->create();
                $this->assertSame($module !== PanelModule::ROLE_MATRIX,
                    PermissionMatrix::can($user, $module, 'view'), "{$role->value}:{$module->value}");
                if ($module !== PanelModule::ROLE_MATRIX && ! $module->isReadOnly()) {
                    $this->assertTrue(PermissionMatrix::can($user, $module, 'edit'), "{$role->value}:{$module->value}");
                }
            }
        }

        PermissionMatrix::set(Role::FINANCE, PanelModule::SETTLEMENTS, []);
        $management = User::factory()->role(Role::MANAGEMENT)->create();
        $this->assertTrue(PermissionMatrix::can($management, PanelModule::SETTLEMENTS, 'view'));
    }

    public function test_golden_matrix_covers_seven_roles_every_module_and_every_ability(): void
    {
        foreach (Role::cases() as $role) {
            $user = User::factory()->role($role)->create();
            foreach (PanelModule::cases() as $module) {
                foreach (['view', 'create', 'edit', 'delete'] as $ability) {
                    $expected = match ($role) {
                        Role::ADMINISTRATOR => true,
                        Role::FINANCE, Role::MANAGEMENT => $module !== PanelModule::ROLE_MATRIX
                            && ($module !== PanelModule::CART_CLOSE_OUT || in_array($ability, ['view', 'edit'], true)),
                        Role::CONTENT_CREATOR => $module === PanelModule::NEWS_FEED
                            || ($module === PanelModule::PAYROLL && $ability === 'view'),
                        Role::BARISTA => $module === PanelModule::KITCHEN_OPERATIONS
                            || (($module === PanelModule::RIDER_LOCATION_SETUP || $module === PanelModule::PAYROLL)
                                && $ability === 'view'),
                        Role::STAFF => $module === PanelModule::PAYROLL && $ability === 'view',
                        Role::RIDER => ($module === PanelModule::PAYROLL && $ability === 'view')
                            || ($module === PanelModule::CART_CLOSE_OUT && in_array($ability, ['view', 'edit'], true)),
                    };
                    $this->assertSame($expected, PermissionMatrix::can($user, $module, $ability),
                        "{$role->value}:{$module->value}:{$ability}");
                }
            }
        }
    }

    public function test_ordinary_administrator_obeys_matrix_and_super_administrator_bypasses_it(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);

        PermissionMatrix::set(Role::ADMINISTRATOR, PanelModule::PAYROLL, []);

        $this->assertFalse(PermissionMatrix::can($admin, PanelModule::PAYROLL, 'view'));
        foreach (['view', 'create', 'edit', 'delete'] as $ability) {
            $this->assertTrue(PermissionMatrix::can($super, PanelModule::PAYROLL, $ability));
        }
    }

    public function test_override_can_add_or_remove_without_changing_role_template_or_other_accounts(): void
    {
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $barista = User::factory()->role(Role::BARISTA)->create();
        $otherBarista = User::factory()->role(Role::BARISTA)->create();
        $finance = User::factory()->role(Role::FINANCE)->create();
        $otherFinance = User::factory()->role(Role::FINANCE)->create();

        PermissionMatrix::setForUser($super, $barista, PanelModule::RIDER_LOCATION_SETUP, ['view', 'edit'], 'Bantu setup');
        PermissionMatrix::setForUser($super, $finance, PanelModule::CLEARING, [], 'Rotasi kas');

        $this->assertTrue(PermissionMatrix::can($barista, PanelModule::RIDER_LOCATION_SETUP, 'edit'));
        $this->assertFalse(PermissionMatrix::can($otherBarista, PanelModule::RIDER_LOCATION_SETUP, 'edit'));
        $this->assertFalse(PermissionMatrix::can($finance, PanelModule::CLEARING, 'view'));
        $this->assertTrue(PermissionMatrix::can($otherFinance, PanelModule::CLEARING, 'view'));
        $this->assertDatabaseHas('user_permissions', ['user_id' => $finance->id, 'module' => 'clearing', 'reason' => 'Rotasi kas']);
        $this->assertSame(2, AuditLog::query()->where('action', 'user_permission.changed')->count());

        PermissionMatrix::setForUser($super, $finance, PanelModule::CLEARING, null);
        $this->assertTrue(PermissionMatrix::can($finance, PanelModule::CLEARING, 'view'));
    }

    public function test_only_super_administrator_manages_permissions_after_bootstrap(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $target = User::factory()->role(Role::BARISTA)->create();
        $this->assertTrue(PermissionMatrix::canManagePermissions($admin));
        PermissionMatrix::set(Role::BARISTA, PanelModule::NEWS_FEED, ['view'], $admin);
        PermissionMatrix::setForUser($admin, $target, PanelModule::RIDER_LOCATION_SETUP, ['view']);

        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $this->assertFalse(PermissionMatrix::canManagePermissions($admin));
        $this->assertTrue(PermissionMatrix::canManagePermissions($super));
        try {
            PermissionMatrix::set(Role::BARISTA, PanelModule::NEWS_FEED, [], $admin);
            $this->fail('Ordinary Administrator changed a role template.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        try {
            PermissionMatrix::setForUser($admin, $target, PanelModule::RIDER_LOCATION_SETUP, []);
            $this->fail('Ordinary Administrator changed an account override.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_access_changes_emit_one_realtime_outbox_event_for_affected_accounts(): void
    {
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $target = User::factory()->role(Role::BARISTA)->create();
        $other = User::factory()->role(Role::BARISTA)->create();
        $finance = User::factory()->role(Role::FINANCE)->create();

        PermissionMatrix::setForUser($super, $target, PanelModule::RIDER_LOCATION_SETUP, ['view', 'edit']);
        $event = OutboxEvent::query()->where('name', 'AccessChanged')->sole();
        $this->assertSame(['user.'.$target->id], $event->payload_json['channels']);

        PermissionMatrix::set(Role::BARISTA, PanelModule::RIDER_LOCATION_SETUP, [], $super);
        $events = OutboxEvent::query()->where('name', 'AccessChanged')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing(['user.'.$target->id, 'user.'.$other->id],
            $events->last()->payload_json['channels']);
        $this->assertNotContains('user.'.$finance->id, $events->last()->payload_json['channels']);

        PermissionMatrix::set(Role::BARISTA, PanelModule::RIDER_LOCATION_SETUP, [], $super);
        $this->assertSame(2, OutboxEvent::query()->where('name', 'AccessChanged')->count());
    }

    public function test_account_form_aggregates_multiple_override_changes_into_one_refresh(): void
    {
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $target = User::factory()->role(Role::BARISTA)->create();

        UserAccessForm::apply($super, $target, [
            PanelModule::RIDER_LOCATION_SETUP->value => 'edit',
            PanelModule::CLEARING->value => 'view',
        ], []);

        $this->assertSame(2, AuditLog::query()->where('action', 'user_permission.changed')->count());
        $this->assertSame(['user.'.$target->id],
            OutboxEvent::query()->where('name', 'AccessChanged')->sole()->payload_json['channels']);

        UserAccessForm::apply($super, $target, [
            PanelModule::RIDER_LOCATION_SETUP->value => 'template',
            PanelModule::CLEARING->value => 'template',
        ], []);
        $this->assertSame(2, OutboxEvent::query()->where('name', 'AccessChanged')->count());
    }

    public function test_finance_can_create_staff_but_cannot_assign_administrator_or_account_grants(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $target = User::factory()->role(Role::BARISTA)->create();
        $administrator = User::factory()->role(Role::ADMINISTRATOR)->create();
        $kitchen = CentralKitchen::query()->create([
            'name' => 'Dapur Uji Akses', 'address' => 'Jakarta',
            'open_at' => '08:00:00', 'close_at' => '17:00:00',
        ]);
        $this->actingAs($finance);
        $this->assertTrue(UserResource::canCreate());
        $this->assertFalse(UserResource::canDelete($administrator));
        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Produksi Baru',
            'phone_e164' => '081298765057',
            'role' => Role::BARISTA->value,
            'kitchen_id' => $kitchen->id,
            'password' => 'rahasia123',
            'is_active' => true,
        ])->call('create')->assertHasNoFormErrors();
        $this->assertDatabaseHas('users', ['phone_e164' => '081298765057', 'role' => Role::BARISTA->value]);

        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Administrator Baru',
            'phone_e164' => '081298765058',
            'role' => Role::ADMINISTRATOR->value,
            'password' => 'rahasia123',
            'is_active' => true,
        ])->call('create')->assertForbidden();
        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Akses Tambahan',
            'phone_e164' => '081298765059',
            'role' => Role::BARISTA->value,
            'kitchen_id' => $kitchen->id,
            'password' => 'rahasia123',
            'is_active' => true,
        ])->set('data.access_grants.payroll', 'edit')->call('create')->assertForbidden();
        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Super Palsu',
            'phone_e164' => '081298765060',
            'role' => Role::BARISTA->value,
            'kitchen_id' => $kitchen->id,
            'password' => 'rahasia123',
            'is_active' => true,
        ])->set('data.is_super_admin', true)->call('create')->assertForbidden();
        $this->assertDatabaseMissing('users', ['phone_e164' => '081298765058']);
        $this->assertDatabaseMissing('users', ['phone_e164' => '081298765059']);
        $this->assertDatabaseMissing('users', ['phone_e164' => '081298765060']);
        Livewire::test(EditUser::class,
            ['record' => $target->id])
            ->set('data.role', Role::ADMINISTRATOR->value)
            ->call('save')->assertForbidden();

        try {
            $target->update(['role' => Role::ADMINISTRATOR]);
            $this->fail('Finance promoted an account to Administrator.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(Role::BARISTA, $target->fresh()->role);
    }

    public function test_finance_and_management_can_open_registered_cms_modules_except_access_settings(): void
    {
        $panel = Filament::getPanel('admin');
        foreach ([Role::FINANCE, Role::MANAGEMENT] as $role) {
            $this->actingAs(User::factory()->role($role)->create());
            foreach ($panel->getResources() as $resource) {
                $this->assertTrue($resource::canViewAny(), $role->value.':'.$resource);
            }
            foreach ($panel->getPages() as $page) {
                $this->assertSame($page !== RoleAccessMatrix::class,
                    $page::canAccess(), $role->value.':'.$page);
            }
        }
    }

    public function test_access_settings_cannot_be_overridden_per_account(): void
    {
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $target = User::factory()->role(Role::BARISTA)->create();
        $this->expectException(HttpException::class);
        PermissionMatrix::setForUser($super, $target, PanelModule::ROLE_MATRIX, ['view', 'edit']);
    }

    public function test_account_role_change_refreshes_its_connected_mobile_session(): void
    {
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $target = User::factory()->role(Role::BARISTA)->create();
        $this->actingAs($super);
        $target->update(['role' => Role::FINANCE]);
        $event = OutboxEvent::query()->where('name', 'AccessChanged')->sole();
        $this->assertSame(['user.'.$target->id], $event->payload_json['channels']);
        $this->assertTrue(PermissionMatrix::can($target->fresh(), PanelModule::CLEARING, 'edit'));
    }

    public function test_inactive_accounts_are_denied_even_when_marked_super_administrator(): void
    {
        $inactive = User::factory()->role(Role::ADMINISTRATOR)->create(['is_active' => false, 'is_super_admin' => true]);
        $this->assertFalse(PermissionMatrix::can($inactive, PanelModule::USERS, 'view'));
        $this->assertFalse(PermissionMatrix::canManagePermissions($inactive));
        $this->assertFalse(PermissionMatrix::can(null, PanelModule::USERS, 'view'));
    }

    public function test_seeding_is_idempotent_and_preserves_existing_role_rows(): void
    {
        $row = RolePermission::query()->where('role', Role::FINANCE)->where('module', PanelModule::SETTLEMENTS->value)->firstOrFail();
        $row->update(['abilities' => ['view']]);
        $count = RolePermission::query()->count();
        DefaultPermissions::seed();
        DefaultPermissions::seed();

        $this->assertSame($count, RolePermission::query()->count());
        $this->assertSame(['view'], $row->fresh()->abilities);
    }

    public function test_management_migration_merges_existing_finance_grants_and_snapshots_prior_rows(): void
    {
        $modules = [
            PanelModule::RAW_MATERIALS, PanelModule::SUPPLIERS, PanelModule::RECIPES,
            PanelModule::PURCHASE_ORDERS, PanelModule::PRODUCTION, PanelModule::SALES,
            PanelModule::DELIVERY_INCIDENTS, PanelModule::STAFF_ACTIVITY,
            PanelModule::REPORTS, PanelModule::AUDIT_LOGS,
        ];
        foreach ($modules as $module) {
            RolePermission::query()->where('role', Role::MANAGEMENT)
                ->where('module', $module->value)->firstOrFail()->update(['abilities' => ['view']]);
        }
        RolePermission::query()->where('role', Role::FINANCE)
            ->where('module', PanelModule::PURCHASE_ORDERS->value)
            ->firstOrFail()->update(['abilities' => ['view', 'create', 'edit']]);
        PermissionMatrix::forget();

        DefaultPermissions::mergeManagement();
        DefaultPermissions::mergeManagement();

        foreach ($modules as $module) {
            $expected = $module === PanelModule::PURCHASE_ORDERS
                ? ['view', 'create', 'edit'] : ['view', 'create', 'edit', 'delete'];
            $this->assertSame($expected,
                PermissionMatrix::abilitiesFor(Role::MANAGEMENT, $module), $module->value);
            $this->assertSame($expected,
                PermissionMatrix::abilitiesFor(Role::FINANCE, $module), $module->value);
            $this->assertSame(['view'], json_decode(DB::table('management_permission_merge_snapshots')
                ->where('module', $module->value)->value('prior_abilities'), true));
        }
        $this->assertSame(count($modules), DB::table('management_permission_merge_snapshots')->count());
        $this->assertSame(count($modules), AuditLog::query()
            ->where('action', 'role_permission.migration_merged')->count());
        $purchaseOrderAudit = AuditLog::query()->where('action', 'role_permission.migration_merged')
            ->where('subject_type', RolePermission::class)
            ->where('before_json->module', PanelModule::PURCHASE_ORDERS->value)->firstOrFail();
        $this->assertSame('SYSTEM', $purchaseOrderAudit->actor_role);
        $this->assertSame(['view'], $purchaseOrderAudit->before_json['abilities']);
        $this->assertSame(['view', 'create', 'edit'], $purchaseOrderAudit->after_json['abilities']);

        DefaultPermissions::restoreManagement();
        $this->assertSame(count($modules), AuditLog::query()
            ->where('action', 'role_permission.migration_restored')->count());
        foreach ($modules as $module) {
            $this->assertSame(['view'], PermissionMatrix::abilitiesFor(Role::MANAGEMENT, $module), $module->value);
        }
        $this->assertSame(['view', 'create', 'edit'],
            PermissionMatrix::abilitiesFor(Role::FINANCE, PanelModule::PURCHASE_ORDERS));
    }

    public function test_management_migration_rollback_refuses_later_edits_and_keeps_snapshot(): void
    {
        RolePermission::query()->where('role', Role::MANAGEMENT)
            ->where('module', PanelModule::PURCHASE_ORDERS->value)
            ->firstOrFail()->update(['abilities' => ['view']]);
        RolePermission::query()->where('role', Role::MANAGEMENT)
            ->where('module', PanelModule::SUPPLIERS->value)->delete();

        DefaultPermissions::mergeManagement();
        PermissionMatrix::set(Role::MANAGEMENT, PanelModule::PURCHASE_ORDERS, []);
        PermissionMatrix::set(Role::MANAGEMENT, PanelModule::SUPPLIERS, ['view']);
        try {
            DefaultPermissions::restoreManagement();
            $this->fail('Rollback should refuse to discard post-migration edits.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Rollback dibatalkan', $exception->getMessage());
        }

        $this->assertSame([], PermissionMatrix::abilitiesFor(Role::MANAGEMENT, PanelModule::PURCHASE_ORDERS));
        $this->assertSame(['view'], PermissionMatrix::abilitiesFor(Role::MANAGEMENT, PanelModule::SUPPLIERS));
        $this->assertDatabaseHas('management_permission_merge_snapshots', ['module' => PanelModule::PURCHASE_ORDERS->value]);
        $this->assertDatabaseHas('management_permission_merge_snapshots', ['module' => PanelModule::SUPPLIERS->value]);
    }

    public function test_management_migration_restores_a_missing_management_row(): void
    {
        RolePermission::query()->where('role', Role::MANAGEMENT)
            ->where('module', PanelModule::SUPPLIERS->value)->delete();
        PermissionMatrix::forget();

        DefaultPermissions::mergeManagement();
        $this->assertSame(['view', 'create', 'edit', 'delete'],
            PermissionMatrix::abilitiesFor(Role::MANAGEMENT, PanelModule::SUPPLIERS));
        $this->assertNull(DB::table('management_permission_merge_snapshots')
            ->where('module', PanelModule::SUPPLIERS->value)->value('prior_abilities'));

        DefaultPermissions::restoreManagement();
        $this->assertDatabaseMissing('role_permissions', [
            'role' => Role::MANAGEMENT->value, 'module' => PanelModule::SUPPLIERS->value,
        ]);
    }

    public function test_management_migration_restore_can_retry_after_restore_commits(): void
    {
        RolePermission::query()->where('role', Role::MANAGEMENT)
            ->where('module', PanelModule::PURCHASE_ORDERS->value)
            ->firstOrFail()->update(['abilities' => ['view']]);

        DefaultPermissions::mergeManagement();
        DefaultPermissions::restoreManagement();
        DefaultPermissions::restoreManagement();

        $this->assertSame(['view'], PermissionMatrix::abilitiesFor(Role::MANAGEMENT, PanelModule::PURCHASE_ORDERS));
        $this->assertSame(1, AuditLog::query()->where('action', 'role_permission.migration_restored')->count());
    }

    public function test_payroll_access_explanation_appears_in_matrix_and_account_form(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->actingAs($admin);
        $hint = 'Lihat = slip gaji sendiri di aplikasi; Kelola = halaman Payroll CMS';
        $this->get(RoleAccessMatrix::getUrl())->assertOk()->assertSee($hint);
        $this->get(CreateUser::getUrl())->assertOk()->assertSee($hint);
    }

    public function test_retired_rows_remain_stored_but_grant_no_known_module(): void
    {
        RolePermission::query()->create(['role' => Role::CONTENT_CREATOR, 'module' => 'staff_assignments', 'abilities' => ['view']]);
        $this->assertNull(PanelModule::tryFrom('staff_assignments'));
        $this->assertDatabaseHas('role_permissions', ['module' => 'staff_assignments']);
    }

    public function test_backoffice_code_has_no_legacy_role_grants_or_inheritance(): void
    {
        $this->assertFalse(method_exists(Role::class, 'grants'));
        $this->assertFalse(method_exists(Role::class, 'inheritedRoles'));
        foreach ([app_path(), base_path('routes')] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $source = file_get_contents($file->getPathname());
                $this->assertStringNotContainsString('->grants(', $source, $file->getPathname());
                $this->assertStringNotContainsString('inheritedRoles(', $source, $file->getPathname());
            }
        }
    }
}
