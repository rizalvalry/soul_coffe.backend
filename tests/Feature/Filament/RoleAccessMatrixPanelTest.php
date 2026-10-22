<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\Reports;
use App\Filament\Pages\RoleAccessMatrix;
use App\Models\OutboxEvent;
use App\Models\RolePermission;
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

    public function test_administrator_is_offered_as_an_editable_role(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        $roles = Livewire::test(RoleAccessMatrix::class)->instance()->editableRoles();

        $this->assertContains(Role::ADMINISTRATOR, $roles);
        $this->assertContains(Role::FINANCE, $roles);
        $this->assertNotContains(Role::MANAGEMENT, $roles);
    }

    public function test_one_matrix_save_emits_one_access_refresh_for_many_changed_modules(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());
        $barista = User::factory()->role(Role::BARISTA)->create();
        Livewire::test(RoleAccessMatrix::class)
            ->set('role', Role::BARISTA->value)
            ->set('grants.'.PanelModule::CARTS->value.'.view', true)
            ->set('grants.'.PanelModule::SETTLEMENTS->value.'.view', true)
            ->call('save')->assertHasNoErrors();

        $events = OutboxEvent::query()->where('name', 'AccessChanged')->get();
        $this->assertCount(1, $events);
        $this->assertSame(['user.'.$barista->id], $events->first()->payload_json['channels']);
        $this->assertTrue(PermissionMatrix::can($barista, PanelModule::CARTS, 'view'));
        $this->assertTrue(PermissionMatrix::can($barista, PanelModule::SETTLEMENTS, 'view'));
    }

    public function test_legacy_management_template_is_hidden_and_preserved(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());
        $before = PermissionMatrix::ownAbilitiesFor(Role::MANAGEMENT, PanelModule::SETTLEMENTS);
        $this->assertNotContains(Role::MANAGEMENT, Livewire::test(RoleAccessMatrix::class)->instance()->editableRoles());
        Livewire::test(RoleAccessMatrix::class)->set('role', Role::MANAGEMENT->value)->assertStatus(422);
        $this->assertSame($before, PermissionMatrix::ownAbilitiesFor(Role::MANAGEMENT, PanelModule::SETTLEMENTS));
    }

    public function test_editing_finance_template_keeps_legacy_management_row(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());
        $before = PermissionMatrix::ownAbilitiesFor(Role::MANAGEMENT, PanelModule::CARTS);

        Livewire::test(RoleAccessMatrix::class)
            ->set('role', Role::FINANCE->value)
            ->set('grants.'.PanelModule::CARTS->value.'.view', false)
            ->set('grants.'.PanelModule::CARTS->value.'.edit', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($before, PermissionMatrix::ownAbilitiesFor(Role::MANAGEMENT, PanelModule::CARTS));
    }

    public function test_management_status_does_not_inherit_legacy_template(): void
    {
        $management = User::factory()->role(Role::BARISTA)->create(['is_management' => true]);
        PermissionMatrix::set(Role::MANAGEMENT, PanelModule::MANAGEMENT_REPORTS, ['view']);

        $this->assertFalse(PermissionMatrix::can($management, PanelModule::MANAGEMENT_REPORTS, 'view'));
        $this->assertSame(['view'], PermissionMatrix::ownAbilitiesFor(Role::MANAGEMENT, PanelModule::MANAGEMENT_REPORTS));
    }

    public function test_saving_writes_the_ticked_abilities_for_the_selected_role_only(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->actingAs($admin);

        Livewire::test(RoleAccessMatrix::class)
            ->set('role', Role::BARISTA->value)
            ->set('grants.'.PanelModule::CARTS->value.'.view', true)
            ->set('grants.'.PanelModule::CARTS->value.'.edit', true)
            ->call('save')
            ->assertHasNoErrors();

        PermissionMatrix::forget();

        $this->assertSame(['view', 'edit'], PermissionMatrix::abilitiesFor(Role::BARISTA, PanelModule::CARTS));
        // The role that was on screen is the only one touched.
        $this->assertSame([], PermissionMatrix::abilitiesFor(Role::RIDER, PanelModule::CARTS));
        $this->assertSame($admin->id, RolePermission::query()
            ->where('role', Role::BARISTA->value)->where('module', PanelModule::CARTS->value)->firstOrFail()->updated_by);
    }

    /** "Ubah tanpa Lihat" is a menu you can act on but never open — always a slip, so view is implied. */
    public function test_ticking_an_action_without_view_still_grants_view(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        Livewire::test(RoleAccessMatrix::class)
            ->set('role', Role::RIDER->value)
            ->set('grants.'.PanelModule::ATTENDANCE->value.'.edit', true)
            ->call('save');

        PermissionMatrix::forget();

        $this->assertEqualsCanonicalizing(
            ['edit', 'view'],
            PermissionMatrix::abilitiesFor(Role::RIDER, PanelModule::ATTENDANCE),
        );
    }

    public function test_unticking_everything_revokes_the_module(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        PermissionMatrix::set(Role::FINANCE, PanelModule::CARTS, ['view', 'edit']);
        PermissionMatrix::forget();

        Livewire::test(RoleAccessMatrix::class)
            ->set('role', Role::FINANCE->value)
            ->set('grants.'.PanelModule::CARTS->value.'.view', false)
            ->set('grants.'.PanelModule::CARTS->value.'.create', false)
            ->set('grants.'.PanelModule::CARTS->value.'.edit', false)
            ->set('grants.'.PanelModule::CARTS->value.'.delete', false)
            ->call('save');

        PermissionMatrix::forget();

        $this->assertSame([], PermissionMatrix::abilitiesFor(Role::FINANCE, PanelModule::CARTS));
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

        PermissionMatrix::set(Role::FINANCE, PanelModule::REPORTS, []);
        // A revoked Finance report grant closes the page.
        $this->assertFalse(Reports::canAccess());

        PermissionMatrix::set(Role::FINANCE, PanelModule::REPORTS, ['view']);
        PermissionMatrix::forget();

        $this->assertTrue(Reports::canAccess());
        $this->get(Reports::getUrl())->assertSuccessful();
    }
}
