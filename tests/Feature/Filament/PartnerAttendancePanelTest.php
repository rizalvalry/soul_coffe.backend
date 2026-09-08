<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\PartnerAttendanceCode;
use App\Enums\Role;
use App\Filament\Pages\PartnerAttendance;
use App\Filament\Resources\Partners\PartnerResource;
use App\Models\Partner;
use App\Models\PartnerAttendanceEntry;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The absensi module as an operator meets it: who gets in, who may change a cell, and whether the
 * grid actually renders.
 *
 * The read-only case is the one worth having. A grid whose cells look editable but whose writes
 * are dropped is worse than no grid at all, so "granted Lihat only" is asserted to both render
 * and refuse.
 */
class PartnerAttendancePanelTest extends TestCase
{
    use RefreshDatabase;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::forget();

        $this->partner = Partner::query()->create([
            'nik' => '240001',
            'name' => 'AGUNG',
            'role' => Role::RIDER,
            'size' => 'M',
            'monthly_libur_quota' => 4,
            'is_active' => true,
        ]);
    }

    private function financeWithAbsensiGrant(array $abilities = ['view', 'create', 'edit', 'delete']): User
    {
        $finance = User::factory()->role(Role::FINANCE)->create();

        PermissionMatrix::set(Role::FINANCE, PanelModule::PARTNERS, $abilities);
        PermissionMatrix::set(Role::FINANCE, PanelModule::PARTNER_ATTENDANCE, $abilities);
        PermissionMatrix::forget();

        return $finance;
    }

    // ── Access ───────────────────────────────────────────────────────────

    public function test_an_administrator_can_open_the_absensi_grid(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();

        $this->actingAs($admin)->get(PartnerAttendance::getUrl())->assertSuccessful();
    }

    public function test_finance_reaches_the_panel_and_the_absensi_grid_through_its_grant(): void
    {
        $finance = $this->financeWithAbsensiGrant();

        // The panel door itself, which was administrator-only before the matrix existed.
        $this->actingAs($finance)->get('/admin')->assertSuccessful();
        $this->actingAs($finance)->get(PartnerAttendance::getUrl())->assertSuccessful();
        $this->actingAs($finance)->get(PartnerResource::getUrl('index'))->assertSuccessful();
    }

    public function test_finance_sees_nothing_else_in_the_panel(): void
    {
        $finance = $this->financeWithAbsensiGrant();
        $this->actingAs($finance);

        foreach (\Filament\Facades\Filament::getPanel('admin')->getResources() as $resource) {
            if ($resource === PartnerResource::class) {
                continue;
            }

            $this->assertFalse(
                $resource::canViewAny(),
                "{$resource} is visible to FINANCE, which was only granted the absensi module.",
            );
        }
    }

    public function test_a_role_with_no_grant_cannot_reach_the_grid_or_the_panel(): void
    {
        $staff = User::factory()->role(Role::STAFF)->create();

        $this->actingAs($staff)->get('/admin')->assertForbidden();
        $this->assertFalse(PartnerAttendance::canAccess());
    }

    // ── Rendering ────────────────────────────────────────────────────────

    public function test_the_grid_renders_the_partner_and_the_summary_headings(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        Livewire::test(PartnerAttendance::class)
            ->assertSuccessful()
            ->assertSee('AGUNG')
            ->assertSee('240001')
            ->assertSee('Jatah')
            ->assertSee('Presentase');
    }

    public function test_switching_month_moves_the_grid(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        Livewire::test(PartnerAttendance::class)
            ->set('month', '2026-08')
            ->call('shiftMonth', -1)
            ->assertSet('month', '2026-07');
    }

    /** A hand-edited month must not 500 a reporting screen. */
    public function test_a_malformed_month_falls_back_to_today_instead_of_throwing(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        Livewire::test(PartnerAttendance::class)
            ->set('month', 'bukan-bulan')
            ->assertSuccessful();
    }

    // ── Editing ──────────────────────────────────────────────────────────

    public function test_an_administrator_can_fill_and_clear_a_cell(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->actingAs($admin);

        Livewire::test(PartnerAttendance::class)
            ->set('month', '2026-08')
            ->call('setCell', $this->partner->id, 7, PartnerAttendanceCode::PRESENT->value);

        $entry = PartnerAttendanceEntry::query()->firstOrFail();
        $this->assertSame(PartnerAttendanceCode::PRESENT, $entry->code);
        $this->assertSame('2026-08-07', $entry->entry_date->toDateString());
        // Who filled the cell in is recorded, because a payroll number needs an author.
        $this->assertSame($admin->id, $entry->recorded_by);

        Livewire::test(PartnerAttendance::class)
            ->set('month', '2026-08')
            ->call('setCell', $this->partner->id, 7, '');

        $this->assertSame(0, PartnerAttendanceEntry::query()->count());
    }

    public function test_finance_can_fill_a_cell_with_its_granted_abilities(): void
    {
        $this->actingAs($this->financeWithAbsensiGrant());

        Livewire::test(PartnerAttendance::class)
            ->set('month', '2026-08')
            ->call('setCell', $this->partner->id, 3, PartnerAttendanceCode::SICK->value);

        $this->assertSame(PartnerAttendanceCode::SICK, PartnerAttendanceEntry::query()->firstOrFail()->code);
    }

    public function test_a_view_only_grant_renders_the_grid_but_refuses_writes(): void
    {
        $this->actingAs($this->financeWithAbsensiGrant(['view']));

        $component = Livewire::test(PartnerAttendance::class)
            ->assertSuccessful()
            ->assertSee('AGUNG');

        $this->assertFalse($component->instance()->canEditCells());

        $component
            ->set('month', '2026-08')
            ->call('setCell', $this->partner->id, 3, PartnerAttendanceCode::PRESENT->value);

        $this->assertSame(0, PartnerAttendanceEntry::query()->count());
    }

    public function test_writing_to_a_partner_that_does_not_exist_is_a_no_op(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        Livewire::test(PartnerAttendance::class)
            ->call('setCell', 99999, 3, PartnerAttendanceCode::PRESENT->value)
            ->assertSuccessful();

        $this->assertSame(0, PartnerAttendanceEntry::query()->count());
    }

    // ── Export ───────────────────────────────────────────────────────────

    public function test_the_grid_exports_an_xlsx_for_the_selected_month(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        Livewire::test(PartnerAttendance::class)
            ->set('month', '2026-08')
            ->callAction('exportExcel')
            ->assertFileDownloaded('absensi-partner_2026-08.xlsx');
    }
}
