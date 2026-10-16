<?php

namespace Tests\Feature\Filament;

use App\Enums\AttendanceCode;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\AttendanceSheet;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\CashAccounts\CashAccountResource;
use App\Filament\Resources\CashCategories\CashCategoryResource;
use App\Filament\Resources\CashTransactions\CashTransactionResource;
use App\Filament\Resources\EmployeeProfiles\EmployeeProfileResource;
use App\Filament\Resources\Journals\JournalResource;
use App\Filament\Resources\OfficeItems\OfficeItemResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Attendance;
use App\Models\AttendanceMark;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The absensi sheet as an operator meets it: who gets in, who may change a cell, and whether the
 * grid actually renders.
 *
 * The read-only case is the one worth having. A grid whose cells look editable but whose writes
 * are dropped is worse than no grid at all, so "granted Lihat only" is asserted to both render
 * and refuse.
 */
class AttendanceSheetPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::forget();

        $this->rider = User::factory()->role(Role::RIDER)->create([
            'name' => 'Runner Uji Satu',
            'nik' => '240001',
            'uniform_size' => 'M',
            'monthly_libur_quota' => 4,
        ]);
    }

    private function financeWithAttendanceGrant(array $abilities = ['view', 'create', 'edit', 'delete']): User
    {
        $finance = User::factory()->role(Role::FINANCE)->create();

        PermissionMatrix::set(Role::FINANCE, PanelModule::ATTENDANCE, $abilities);
        PermissionMatrix::forget();

        return $finance;
    }

    // ── Access ───────────────────────────────────────────────────────────

    public function test_an_administrator_can_open_the_sheet(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();

        $this->actingAs($admin)->get(AttendanceSheet::getUrl())->assertSuccessful();
    }

    public function test_finance_reaches_the_panel_and_the_sheet_through_its_grant(): void
    {
        $finance = $this->financeWithAttendanceGrant();

        // The panel door itself, which was administrator-only before the matrix existed.
        $this->actingAs($finance)->get('/admin')->assertSuccessful();
        $this->actingAs($finance)->get(AttendanceSheet::getUrl())->assertSuccessful();
    }

    /**
     * Finance records days and can view HR profiles. It cannot open Users, where roles and
     * passwords are changed.
     */
    public function test_finance_sees_employee_profiles_without_users_or_other_resources(): void
    {
        $finance = $this->financeWithAttendanceGrant();
        $this->actingAs($finance);

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $this->assertSame(
                in_array($resource, [EmployeeProfileResource::class,
                    AccountResource::class,
                    JournalResource::class,
                    CashAccountResource::class,
                    CashCategoryResource::class,
                    CashTransactionResource::class,
                    OfficeItemResource::class], true),
                $resource::canViewAny(),
                "{$resource} has the wrong visibility for FINANCE with only an attendance grant.",
            );
        }
        $this->assertFalse(UserResource::canViewAny());
    }

    public function test_a_role_with_no_grant_cannot_reach_the_sheet_or_the_panel(): void
    {
        $staff = User::factory()->role(Role::STAFF)->create();

        $this->actingAs($staff)->get('/admin')->assertForbidden();
        $this->assertFalse(AttendanceSheet::canAccess());
    }

    // ── Rendering ────────────────────────────────────────────────────────

    public function test_the_sheet_renders_the_employee_and_the_summary_headings(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        Livewire::test(AttendanceSheet::class)
            ->assertSuccessful()
            ->assertSee('Runner Uji Satu')
            ->assertSee('240001')
            ->assertSee('Jatah')
            ->assertSee('Presentase');
    }

    /** Employees come from `users`, so nobody has to be entered into a second roster. */
    public function test_the_sheet_lists_users_with_no_separate_roster(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());
        User::factory()->role(Role::RIDER)->create(['name' => 'Rider Uji Empat', 'nik' => '240002']);

        Livewire::test(AttendanceSheet::class)
            ->assertSee('Runner Uji Satu')
            ->assertSee('Rider Uji Empat');
    }

    public function test_switching_month_moves_the_sheet(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        Livewire::test(AttendanceSheet::class)
            ->set('month', '2026-08')
            ->call('shiftMonth', -1)
            ->assertSet('month', '2026-07');
    }

    /** A hand-edited month must not 500 a reporting screen. */
    public function test_a_malformed_month_falls_back_to_today_instead_of_throwing(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        Livewire::test(AttendanceSheet::class)
            ->set('month', 'bukan-bulan')
            ->assertSuccessful();
    }

    /** A clock-in shows on the screen, with the time it happened. */
    public function test_a_clock_in_appears_on_the_rendered_sheet(): void
    {
        $staff = User::factory()->role(Role::STAFF)->create(['name' => 'Rider Uji Satu']);

        Attendance::create([
            'operating_date' => '2026-08-05',
            'user_id' => $staff->id,
            'role' => Role::STAFF,
            'clocked_in_at' => Carbon::parse('2026-08-05 07:12'),
        ]);

        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        Livewire::test(AttendanceSheet::class)
            ->set('month', '2026-08')
            ->set('role', Role::STAFF->value)
            ->assertSee('Rider Uji Satu')
            ->assertSee('Absen dari aplikasi pukul 07:12');
    }

    // ── Editing ──────────────────────────────────────────────────────────

    public function test_an_administrator_can_fill_and_clear_a_cell(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->actingAs($admin);

        Livewire::test(AttendanceSheet::class)
            ->set('month', '2026-08')
            ->call('setCell', $this->rider->id, 7, AttendanceCode::PRESENT->value);

        $mark = AttendanceMark::query()->firstOrFail();
        $this->assertSame(AttendanceCode::PRESENT, $mark->code);
        $this->assertSame('2026-08-07', $mark->entry_date->toDateString());
        // Who filled the cell in is recorded, because a payroll number needs an author.
        $this->assertSame($admin->id, $mark->recorded_by);

        Livewire::test(AttendanceSheet::class)
            ->set('month', '2026-08')
            ->call('setCell', $this->rider->id, 7, '');

        $this->assertSame(0, AttendanceMark::query()->count());
    }

    public function test_finance_can_fill_a_cell_with_its_granted_abilities(): void
    {
        $this->actingAs($this->financeWithAttendanceGrant());

        Livewire::test(AttendanceSheet::class)
            ->set('month', '2026-08')
            ->call('setCell', $this->rider->id, 3, AttendanceCode::SICK->value);

        $this->assertSame(AttendanceCode::SICK, AttendanceMark::query()->firstOrFail()->code);
    }

    public function test_a_view_only_grant_renders_the_sheet_but_refuses_writes(): void
    {
        $this->actingAs($this->financeWithAttendanceGrant(['view']));

        $component = Livewire::test(AttendanceSheet::class)
            ->assertSuccessful()
            ->assertSee('Runner Uji Satu');

        $this->assertFalse($component->instance()->canEditCells());

        $component
            ->set('month', '2026-08')
            ->call('setCell', $this->rider->id, 3, AttendanceCode::PRESENT->value);

        $this->assertSame(0, AttendanceMark::query()->count());
    }

    public function test_writing_to_a_user_that_does_not_exist_is_a_no_op(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        Livewire::test(AttendanceSheet::class)
            ->call('setCell', 99999, 3, AttendanceCode::PRESENT->value)
            ->assertSuccessful();

        $this->assertSame(0, AttendanceMark::query()->count());
    }

    // ── Export ───────────────────────────────────────────────────────────

    public function test_the_sheet_exports_an_xlsx_for_the_selected_month(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        Livewire::test(AttendanceSheet::class)
            ->set('month', '2026-08')
            ->callAction('exportExcel')
            ->assertFileDownloaded('absensi_2026-08.xlsx');
    }
}
