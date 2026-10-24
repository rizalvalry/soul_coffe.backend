<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Filament\Resources\EmployeeProfiles\EmployeeProfileResource;
use App\Filament\Resources\EmployeeProfiles\Pages\EditEmployeeProfile;
use App\Filament\Resources\EmployeeProfiles\Pages\ListEmployeeProfiles;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\AuditLog;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeProfilePanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionMatrix::forget();
        PermissionMatrix::set(Role::FINANCE, PanelModule::USERS, ['view']);
    }

    private function profile(Role $role = Role::STAFF): EmployeeProfile
    {
        $user = User::factory()->role($role)->create(['name' => 'Karyawan Uji']);

        return $user->employeeProfile()->create([
            'division' => 'Operasional',
            'position_title' => 'Rider',
            'base_salary_minor' => 7800000,
            'npwp' => '123456789012345',
            'ptkp_status' => 'TK/0',
            'payroll_active' => true,
        ]);
    }

    public function test_payroll_is_encrypted_and_hidden_from_finance(): void
    {
        PermissionMatrix::set(Role::FINANCE, PanelModule::PAYROLL, []);
        $profile = $this->profile();
        $raw = DB::table('employee_profiles')->where('id', $profile->id)->first();
        $this->assertNotSame('7800000', $raw->base_salary_minor);
        $this->assertNotSame('123456789012345', $raw->npwp);
        $this->assertSame(7800000, $profile->fresh()->base_salary_minor);
        $this->assertSame('123456789012345', $profile->fresh()->npwp);
        $this->assertArrayNotHasKey('base_salary_minor', $profile->fresh()->toArray());
        $this->assertArrayNotHasKey('npwp', $profile->fresh()->toArray());

        foreach ([Role::FINANCE] as $role) {
            $this->actingAs(User::factory()->role($role)->create());
            $this->get(ListEmployeeProfiles::getUrl())->assertSuccessful()
                ->assertSee('Karyawan Uji')->assertDontSee('7800000')->assertDontSee('123456789012345');
            $this->get(EditEmployeeProfile::getUrl(['record' => $profile]))->assertSuccessful()
                ->assertDontSee('7800000')->assertDontSee('123456789012345');

            Livewire::test(EditEmployeeProfile::class, ['record' => $profile->id])
                ->fillForm(['division' => 'Lapangan', 'base_salary_minor' => 1, 'npwp' => '000000000000000'])
                ->call('save')->assertHasNoFormErrors();
            $this->assertSame('Lapangan', $profile->fresh()->division);
            $this->assertSame(7800000, $profile->fresh()->base_salary_minor);
            $this->assertSame('123456789012345', $profile->fresh()->npwp);
        }

        $this->actingAs(User::factory()->role(Role::BARISTA)->create());
        $this->get(ListEmployeeProfiles::getUrl())->assertForbidden();
        $this->get(EditEmployeeProfile::getUrl(['record' => $profile]))->assertForbidden();
        $this->assertFalse(EmployeeProfileResource::canViewAny());
    }

    public function test_management_can_edit_payroll_and_only_management_sees_audit_values(): void
    {
        $profile = $this->profile();
        $manager = User::factory()->role(Role::MANAGEMENT)->create();
        $this->actingAs($manager);

        $this->get(EditEmployeeProfile::getUrl(['record' => $profile]))->assertSuccessful()
            ->assertSee('7800000')->assertSee('123456789012345');
        Livewire::test(EditEmployeeProfile::class, ['record' => $profile->id])
            ->fillForm(['base_salary_minor' => 8100000, 'npwp' => '987654321098765'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame(8100000, $profile->fresh()->base_salary_minor);
        $this->assertSame('987654321098765', $profile->fresh()->npwp);
        $audit = AuditLog::query()->where('action', 'employee_salary_version_saved')->latest('id')->firstOrFail();
        $this->assertSame($manager->id, $audit->actor_id);
        $this->assertSame('[redacted]', $audit->before_json['base_salary_minor']);
        $this->assertSame('[redacted]', $audit->after_json['base_salary_minor']);
        $this->assertStringNotContainsString('8100000', json_encode($audit->after_json));
        $this->assertStringNotContainsString('987654321098765', json_encode($audit->after_json));
        $this->assertSame(7800000, $audit->displayChanges('before')['base_salary_minor']);
        $this->assertSame(8100000, $audit->displayChanges('after')['base_salary_minor']);
        PermissionMatrix::set(Role::MANAGEMENT, PanelModule::AUDIT_LOGS, ['view']);
        $this->get(ViewAuditLog::getUrl(['record' => $audit]))->assertSuccessful()
            ->assertSee('7800000')->assertSee('8100000');

        PermissionMatrix::set(Role::FINANCE, PanelModule::PAYROLL, []);
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        $this->assertSame('[redacted]', $audit->displayChanges('before')['base_salary_minor']);
        $this->assertSame('[redacted]', $audit->displayChanges('after')['base_salary_minor']);
        PermissionMatrix::set(Role::FINANCE, PanelModule::AUDIT_LOGS, ['view']);
        $this->get(ViewAuditLog::getUrl(['record' => $audit]))->assertSuccessful()
            ->assertDontSee('7800000')->assertDontSee('8100000')->assertDontSee('_payroll_ciphertext');
    }

    public function test_daily_basis_defaults_to_rounded_rate_and_keeps_it_encrypted(): void
    {
        $profile = $this->profile();
        $profile->update(['base_salary_minor' => 1000000]);
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());
        Livewire::test(EditEmployeeProfile::class, ['record' => $profile->id])
            ->fillForm(['salary_basis' => 'harian'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('harian', $profile->fresh()->salary_basis);
        $this->assertSame(38462, $profile->fresh()->daily_rate_minor);
        $this->assertNotSame('38462', DB::table('employee_profiles')->where('id', $profile->id)->value('daily_rate_minor'));
    }

    public function test_daily_basis_waits_for_salary_before_defaulting_the_rate(): void
    {
        $profile = $this->profile();
        $profile->update(['base_salary_minor' => null]);
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());

        Livewire::test(EditEmployeeProfile::class, ['record' => $profile->id])
            ->fillForm(['salary_basis' => 'harian'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertNull($profile->fresh()->daily_rate_minor);

        Livewire::test(EditEmployeeProfile::class, ['record' => $profile->id])
            ->fillForm(['base_salary_minor' => 1000000])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(38462, $profile->fresh()->daily_rate_minor);
    }

    public function test_salary_change_defaults_to_next_month_and_versions_are_encrypted(): void
    {
        $this->travelTo(Carbon::parse('2026-10-24 12:00:00'));
        $profile = $this->profile();
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());

        Livewire::test(EditEmployeeProfile::class, ['record' => $profile->id])
            ->fillForm(['base_salary_minor' => 1000000, 'salary_basis' => 'harian'])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('bulanan', $profile->salaryVersionFor('2026-10-01')->salary_basis);
        $november = $profile->salaryVersionFor('2026-11-01');
        $this->assertSame('harian', $november->salary_basis);
        $this->assertSame(1000000, $november->base_salary_minor);
        $this->assertSame(38462, $november->daily_rate_minor);
        $raw = DB::table('employee_salary_versions')->where('id', $november->id)->first();
        $this->assertNotSame('1000000', $raw->base_salary_minor);
        $this->assertNotSame('38462', $raw->daily_rate_minor);
    }

    public function test_salary_change_rejects_past_month_and_approved_current_month(): void
    {
        $this->travelTo(Carbon::parse('2026-10-24 12:00:00'));
        $profile = $this->profile();
        $manager = User::factory()->role(Role::MANAGEMENT)->create();
        $this->actingAs($manager);

        Livewire::test(EditEmployeeProfile::class, ['record' => $profile->id])
            ->fillForm(['salary_effective_month' => '2026-09'])
            ->fillForm(['base_salary_minor' => 1000000])
            ->call('save')->assertHasFormErrors(['salary_effective_month']);
        $this->assertSame(7800000, $profile->fresh()->base_salary_minor);

        PayrollRun::query()->create([
            'period_start' => '2026-10-01', 'status' => 'APPROVED', 'created_by' => $manager->id,
        ]);
        Livewire::test(EditEmployeeProfile::class, ['record' => $profile->id])
            ->fillForm(['salary_effective_month' => '2026-10'])
            ->fillForm(['base_salary_minor' => 1000000])
            ->call('save')->assertHasFormErrors(['salary_effective_month']);
        $this->assertSame(7800000, $profile->fresh()->base_salary_minor);
    }

    public function test_salary_change_can_start_current_month_before_approval(): void
    {
        $this->travelTo(Carbon::parse('2026-10-24 12:00:00'));
        $profile = $this->profile();
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());

        Livewire::test(EditEmployeeProfile::class, ['record' => $profile->id])
            ->fillForm(['salary_effective_month' => '2026-10'])
            ->fillForm(['base_salary_minor' => 1000000])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame(1000000, $profile->salaryVersionFor('2026-10-01')->base_salary_minor);
        $this->assertSame(1, $profile->salaryVersions()->count());
    }

    public function test_editing_october_after_scheduling_november_uses_october_values(): void
    {
        $this->travelTo(Carbon::parse('2026-10-24 12:00:00'));
        $profile = $this->profile();
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());
        Livewire::test(EditEmployeeProfile::class, ['record' => $profile->id])
            ->fillForm(['salary_effective_month' => '2026-10', 'base_salary_minor' => 1000000,
                'salary_basis' => 'harian', 'daily_rate_minor' => 38462])
            ->call('save')->assertHasNoFormErrors();
        Livewire::test(EditEmployeeProfile::class, ['record' => $profile->id])
            ->fillForm(['salary_effective_month' => '2026-11', 'base_salary_minor' => 500000,
                'salary_basis' => 'bulanan'])
            ->call('save')->assertHasNoFormErrors();

        Livewire::test(EditEmployeeProfile::class, ['record' => $profile->id])
            ->fillForm(['salary_effective_month' => '2026-10'])
            ->fillForm(['daily_rate_minor' => 40000])
            ->call('save')->assertHasNoFormErrors();

        $october = $profile->salaryVersionFor('2026-10-01');
        $this->assertSame('harian', $october->salary_basis);
        $this->assertSame(1000000, $october->base_salary_minor);
        $this->assertSame(40000, $october->daily_rate_minor);
        $this->assertSame(500000, $profile->salaryVersionFor('2026-11-01')->base_salary_minor);
        $this->assertSame(500000, $profile->fresh()->base_salary_minor);
    }

    public function test_effective_month_save_writes_version_even_when_values_match_latest_profile(): void
    {
        $this->travelTo(Carbon::parse('2026-10-24 12:00:00'));
        $profile = $this->profile();
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());
        Livewire::test(EditEmployeeProfile::class, ['record' => $profile->id])
            ->fillForm(['salary_effective_month' => '2026-11', 'base_salary_minor' => 500000])
            ->call('save')->assertHasNoFormErrors();

        Livewire::test(EditEmployeeProfile::class, ['record' => $profile->id])
            ->fillForm(['salary_effective_month' => '2026-10'])
            ->fillForm(['base_salary_minor' => 500000])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame(500000, $profile->salaryVersionFor('2026-10-01')->base_salary_minor);
        $this->assertSame(500000, $profile->salaryVersionFor('2026-11-01')->base_salary_minor);
        $audit = AuditLog::query()->where('action', 'employee_salary_version_saved')->latest('id')->firstOrFail();
        $this->assertSame('[redacted]', $audit->before_json['base_salary_minor']);
        $this->assertSame(7800000, $audit->displayChanges('before')['base_salary_minor']);
        $this->assertSame(500000, $audit->displayChanges('after')['base_salary_minor']);
    }

    public function test_administrator_can_view_and_edit_payroll_with_its_own_audit_role(): void
    {
        $profile = $this->profile();
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->actingAs($admin);

        $this->get(EditEmployeeProfile::getUrl(['record' => $profile]))->assertSuccessful()
            ->assertSee('7800000')->assertSee('123456789012345');
        Livewire::test(EditEmployeeProfile::class, ['record' => $profile->id])
            ->fillForm(['base_salary_minor' => 8100000])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(8100000, $profile->fresh()->base_salary_minor);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'employee_payroll_updated', 'actor_role' => Role::ADMINISTRATOR->value,
        ]);
    }

    public function test_management_and_administrator_can_edit_management_profile(): void
    {
        $profile = $this->profile(Role::MANAGEMENT);
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());
        $this->get(EditEmployeeProfile::getUrl(['record' => $profile]))->assertSuccessful();

        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());
        $this->get(EditEmployeeProfile::getUrl(['record' => $profile]))->assertSuccessful();
    }

    public function test_new_user_created_in_cms_gets_an_editable_employee_profile(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Karyawan Rekrut Baru',
                'phone_e164' => '081234560030',
                'role' => Role::STAFF->value,
                'password' => 'newsecret',
            ])
            ->call('create')->assertHasNoFormErrors();

        $employee = User::query()->where('phone_e164', '081234560030')->firstOrFail();
        $profile = $employee->employeeProfile()->firstOrFail();
        $this->get(EditEmployeeProfile::getUrl(['record' => $profile]))->assertSuccessful();
    }
}
