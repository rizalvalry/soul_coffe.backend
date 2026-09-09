<?php

namespace Tests\Feature\Access;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The access matrix. Two properties matter more than any single grant:
 *
 *  - ADMINISTRATOR can never be locked out, whatever the table says.
 *  - Every other role starts with nothing, so an empty table is a safe table.
 */
class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionMatrix::forget();
    }

    public function test_an_administrator_passes_every_module_with_no_rows_at_all(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();

        $this->assertSame(0, \App\Models\RolePermission::query()->count());

        foreach (PanelModule::cases() as $module) {
            foreach (['view', 'create', 'edit', 'delete'] as $ability) {
                $this->assertTrue(
                    PermissionMatrix::can($admin, $module, $ability),
                    "Administrator was refused {$ability} on {$module->value}.",
                );
            }
        }
    }

    /** Even a row that explicitly grants an administrator nothing must not take anything away. */
    public function test_an_administrator_is_not_governed_by_its_own_rows(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();

        PermissionMatrix::set(Role::ADMINISTRATOR, PanelModule::USERS, []);
        PermissionMatrix::forget();

        $this->assertTrue(PermissionMatrix::can($admin, PanelModule::USERS, 'delete'));
    }

    public function test_every_other_role_starts_with_nothing(): void
    {
        foreach ([Role::FINANCE, Role::BARISTA, Role::RIDER, Role::STAFF, Role::CONTENT_CREATOR] as $role) {
            $user = User::factory()->role($role)->create();

            foreach (PanelModule::cases() as $module) {
                $this->assertFalse(
                    PermissionMatrix::can($user, $module, 'view'),
                    "{$role->value} could already see {$module->value} with no grant.",
                );
            }
        }
    }

    public function test_a_granted_ability_passes_and_an_ungranted_one_does_not(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();

        PermissionMatrix::set(Role::FINANCE, PanelModule::STAFF_ASSIGNMENTS, ['view', 'edit']);
        PermissionMatrix::forget();

        $this->assertTrue(PermissionMatrix::can($finance, PanelModule::STAFF_ASSIGNMENTS, 'view'));
        $this->assertTrue(PermissionMatrix::can($finance, PanelModule::STAFF_ASSIGNMENTS, 'edit'));
        $this->assertFalse(PermissionMatrix::can($finance, PanelModule::STAFF_ASSIGNMENTS, 'delete'));
        // A grant on one module says nothing about another.
        $this->assertFalse(PermissionMatrix::can($finance, PanelModule::USERS, 'view'));
    }

    public function test_setting_an_empty_ability_list_removes_the_row_entirely(): void
    {
        PermissionMatrix::set(Role::BARISTA, PanelModule::PRODUCTS, ['view']);
        $this->assertDatabaseHas('role_permissions', ['role' => Role::BARISTA->value, 'module' => PanelModule::PRODUCTS->value]);

        PermissionMatrix::set(Role::BARISTA, PanelModule::PRODUCTS, []);

        $this->assertDatabaseMissing('role_permissions', ['role' => Role::BARISTA->value, 'module' => PanelModule::PRODUCTS->value]);
    }

    public function test_a_guest_is_refused(): void
    {
        $this->assertFalse(PermissionMatrix::can(null, PanelModule::STAFF_ASSIGNMENTS, 'view'));
    }

    public function test_has_any_module_reflects_whether_a_role_was_given_anything(): void
    {
        $this->assertFalse(PermissionMatrix::hasAnyModule(Role::RIDER));

        PermissionMatrix::set(Role::RIDER, PanelModule::ATTENDANCE, ['view']);
        PermissionMatrix::forget();

        $this->assertTrue(PermissionMatrix::hasAnyModule(Role::RIDER));
    }

    public function test_the_seeded_default_gives_finance_the_absensi_module_and_nothing_else(): void
    {
        $this->seed();
        PermissionMatrix::forget();

        $finance = User::query()->where('role', Role::FINANCE)->firstOrFail();

        $this->assertTrue(PermissionMatrix::can($finance, PanelModule::ATTENDANCE, 'view'));
        $this->assertTrue(PermissionMatrix::can($finance, PanelModule::ATTENDANCE, 'edit'));

        // The rest of the panel is untouched by that default — `users` above all, since that is
        // where roles and passwords are changed.
        $this->assertFalse(PermissionMatrix::can($finance, PanelModule::USERS, 'view'));
        $this->assertFalse(PermissionMatrix::can($finance, PanelModule::STAFF_ASSIGNMENTS, 'view'));
        $this->assertFalse(PermissionMatrix::can($finance, PanelModule::PRODUCTS, 'view'));
        $this->assertFalse(PermissionMatrix::can($finance, PanelModule::AUDIT_LOGS, 'view'));
    }
}
