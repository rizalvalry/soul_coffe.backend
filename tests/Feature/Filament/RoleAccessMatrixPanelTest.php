<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\RoleAccessMatrix;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The matrix editor. Its own access rules are the reason it exists as a hardcoded page rather
 * than a module in the matrix it edits — a screen that grants permissions must never be
 * grantable through them.
 */
class RoleAccessMatrixPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionMatrix::forget();
    }

    public function test_only_an_administrator_can_open_it(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->actingAs($admin)->get(RoleAccessMatrix::getUrl())->assertSuccessful();

        // Even a Finance user who has been granted every module in the matrix cannot reach the
        // page that hands out those grants.
        $finance = User::factory()->role(Role::FINANCE)->create();
        foreach (PanelModule::cases() as $module) {
            PermissionMatrix::set(Role::FINANCE, $module, ['view', 'create', 'edit', 'delete']);
        }
        PermissionMatrix::forget();

        $this->actingAs($finance);
        $this->assertFalse(RoleAccessMatrix::canAccess());
    }

    public function test_administrator_is_not_offered_as_an_editable_role(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        $roles = Livewire::test(RoleAccessMatrix::class)->instance()->editableRoles();

        $this->assertNotContains(Role::ADMINISTRATOR, $roles);
        $this->assertContains(Role::FINANCE, $roles);
    }

    public function test_saving_writes_the_ticked_abilities_for_the_selected_role_only(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->actingAs($admin);

        Livewire::test(RoleAccessMatrix::class)
            ->set('role', Role::BARISTA->value)
            ->set('grants.'.PanelModule::PARTNERS->value.'.view', true)
            ->set('grants.'.PanelModule::PARTNERS->value.'.edit', true)
            ->call('save')
            ->assertHasNoErrors();

        PermissionMatrix::forget();

        $this->assertSame(['view', 'edit'], PermissionMatrix::abilitiesFor(Role::BARISTA, PanelModule::PARTNERS));
        // The role that was on screen is the only one touched.
        $this->assertSame([], PermissionMatrix::abilitiesFor(Role::RIDER, PanelModule::PARTNERS));
        $this->assertSame($admin->id, \App\Models\RolePermission::query()->first()->updated_by);
    }

    /** "Ubah tanpa Lihat" is a menu you can act on but never open — always a slip, so view is implied. */
    public function test_ticking_an_action_without_view_still_grants_view(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        Livewire::test(RoleAccessMatrix::class)
            ->set('role', Role::RIDER->value)
            ->set('grants.'.PanelModule::PARTNER_ATTENDANCE->value.'.edit', true)
            ->call('save');

        PermissionMatrix::forget();

        $this->assertEqualsCanonicalizing(
            ['edit', 'view'],
            PermissionMatrix::abilitiesFor(Role::RIDER, PanelModule::PARTNER_ATTENDANCE),
        );
    }

    public function test_unticking_everything_revokes_the_module(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        PermissionMatrix::set(Role::FINANCE, PanelModule::PARTNERS, ['view', 'edit']);
        PermissionMatrix::forget();

        Livewire::test(RoleAccessMatrix::class)
            ->set('role', Role::FINANCE->value)
            ->set('grants.'.PanelModule::PARTNERS->value.'.view', false)
            ->set('grants.'.PanelModule::PARTNERS->value.'.edit', false)
            ->call('save');

        PermissionMatrix::forget();

        $this->assertSame([], PermissionMatrix::abilitiesFor(Role::FINANCE, PanelModule::PARTNERS));
    }

    /**
     * A read-only module has nothing to create, edit or delete, so those boxes are never offered
     * — and a payload that tries anyway cannot invent the ability.
     */
    public function test_a_read_only_module_only_ever_grants_view(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        Livewire::test(RoleAccessMatrix::class)
            ->set('role', Role::FINANCE->value)
            ->set('grants.'.PanelModule::REPORTS->value.'.view', true)
            ->set('grants.'.PanelModule::REPORTS->value.'.delete', true)
            ->call('save');

        PermissionMatrix::forget();

        $this->assertSame(['view'], PermissionMatrix::abilitiesFor(Role::FINANCE, PanelModule::REPORTS));
    }

    /** Granting Reports opens the export page for that role — the grant has to actually do something. */
    public function test_a_reports_grant_opens_the_reports_page_for_that_role(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $this->actingAs($finance);

        // Signed in as Finance, with no grant yet: the page is closed.
        $this->assertFalse(\App\Filament\Pages\Reports::canAccess());

        PermissionMatrix::set(Role::FINANCE, PanelModule::REPORTS, ['view']);
        PermissionMatrix::forget();

        $this->assertTrue(\App\Filament\Pages\Reports::canAccess());
        $this->get(\App\Filament\Pages\Reports::getUrl())->assertSuccessful();
    }
}
