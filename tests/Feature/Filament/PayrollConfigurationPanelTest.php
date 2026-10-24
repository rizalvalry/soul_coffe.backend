<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\PayrollConfiguration;
use App\Filament\Resources\AuditLogs\Pages\ViewAuditLog;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Database\Seeders\AccountSeeder;
use Database\Seeders\PayrollConfigurationSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class PayrollConfigurationPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_can_create_versioned_settings_and_manual_entry(): void
    {
        $this->seed(PayrollConfigurationSeeder::class);
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->role(Role::MANAGEMENT)->create();
        $employee = User::factory()->role(Role::STAFF)->create();
        $employee->employeeProfile()->create(['base_salary_minor' => 1000000, 'payroll_active' => true]);
        $this->actingAs($manager);
        $this->get(PayrollConfiguration::getUrl())->assertSuccessful()->assertSee('Konfigurasi Payroll');

        $this->get(PayrollConfiguration::getUrl())->assertDontSee('Aturan Insentif Rider');
        $this->assertSame(1, DB::table('rider_incentive_rules')->count());

        Livewire::test(PayrollConfiguration::class)
            ->set('entry.user_id', $employee->id)
            ->set('entry.component_type_id', DB::table('payroll_component_types')->where('code', 'ADVANCE')->value('id'))
            ->set('entry.period_start', '2026-10-01')
            ->set('entry.amount_minor', 100000)
            ->call('saveEntry')->assertHasNoErrors();
        $this->assertSame(1, DB::table('payroll_component_entries')->count());
        $audit = DB::table('audit_log')->where('action', 'payroll_component_entry_created')->first();
        $this->assertSame(['payroll_detail' => '[redacted]'], json_decode($audit->after_json, true));
    }

    public function test_management_can_change_attendance_and_bonus_rules_but_field_role_gets_403(): void
    {
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());
        Livewire::test(PayrollConfiguration::class)
            ->set('attendanceRule.late_after', '09:30')
            ->set('attendanceRule.target_cups', 40)
            ->call('saveAttendanceRule')->assertHasNoErrors();
        Livewire::test(PayrollConfiguration::class)
            ->set('monthlyBonus.min_cups', 1000)
            ->set('monthlyBonus.amount_minor', 200000)
            ->call('saveMonthlyBonus')->assertHasNoErrors();
        $this->assertDatabaseHas('payroll_attendance_settings', ['late_after' => '09:30', 'target_cups' => 40]);
        $this->assertDatabaseHas('payroll_monthly_bonus_rules', ['min_cups' => 1000, 'amount_minor' => 200000]);
        $this->actingAs(User::factory()->role(Role::STAFF)->create());
        $this->get(PayrollConfiguration::getUrl())->assertForbidden();
    }

    public function test_bonus_audit_keeps_amount_private_from_audit_only_viewer(): void
    {
        $manager = User::factory()->role(Role::MANAGEMENT)->create();
        $this->actingAs($manager);
        Livewire::test(PayrollConfiguration::class)
            ->set('monthlyBonus.min_cups', 1000)
            ->set('monthlyBonus.amount_minor', 200000)
            ->call('saveMonthlyBonus')->assertHasNoErrors();
        $audit = AuditLog::query()->where('action', 'payroll_monthly_bonus_saved')->firstOrFail();
        $this->assertStringNotContainsString('200000', json_encode($audit->after_json));
        $this->assertSame(200000, $audit->displayChanges('after')['amount_minor']);

        PermissionMatrix::set(Role::FINANCE, PanelModule::PAYROLL, []);
        PermissionMatrix::set(Role::FINANCE, PanelModule::AUDIT_LOGS, ['view']);
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        $this->assertSame('[redacted]', $audit->displayChanges('after')['amount_minor']);
        $this->get(ViewAuditLog::getUrl(['record' => $audit]))->assertOk()->assertDontSee('200000');

        $createdAt = DB::table('payroll_monthly_bonus_rules')->where('min_cups', 1000)->value('created_at');
        $this->actingAs($manager);
        Livewire::test(PayrollConfiguration::class)
            ->set('monthlyBonus.min_cups', 1000)
            ->set('monthlyBonus.amount_minor', 300000)
            ->call('saveMonthlyBonus')->assertHasNoErrors();
        $updatedAudit = AuditLog::query()->where('action', 'payroll_monthly_bonus_saved')->latest('id')->firstOrFail();
        $this->assertSame(200000, (int) $updatedAudit->displayChanges('before')['amount_minor']);
        $this->assertSame(300000, $updatedAudit->displayChanges('after')['amount_minor']);
        $this->assertSame($createdAt, DB::table('payroll_monthly_bonus_rules')->where('min_cups', 1000)->value('created_at'));
    }

    public function test_field_role_gets_403_on_new_payroll_configuration_actions(): void
    {
        $manager = User::factory()->role(Role::MANAGEMENT)->create();
        foreach (['saveAttendanceRule', 'saveMonthlyBonus', 'removeMonthlyBonus'] as $action) {
            $this->actingAs($manager);
            $page = Livewire::test(PayrollConfiguration::class);
            $this->actingAs(User::factory()->role(Role::STAFF)->create());
            $action === 'removeMonthlyBonus'
                ? $page->call($action, 1000)->assertForbidden()
                : $page->call($action)->assertForbidden();
        }
    }

    public function test_configuration_history_is_bounded_to_latest_100_rows(): void
    {
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());
        foreach (range(0, 100) as $day) {
            DB::table('rider_incentive_rules')->insert([
                'effective_from' => date('Y-m-d', strtotime('2026-01-01 +'.$day.' days')),
                'amount_per_cup_minor' => $day, 'target_cups' => 0, 'bonus_minor' => 0,
                'source_note' => 'Synthetic history fixture',
            ]);
        }

        $rows = Livewire::test(PayrollConfiguration::class)->instance()->rows('rider_incentive_rules');
        $this->assertCount(100, $rows);
        $this->assertSame(100, (int) $rows[0]->amount_per_cup_minor);
        $this->assertSame(1, (int) $rows[99]->amount_per_cup_minor);
    }

    public function test_finance_and_other_roles_get_403_on_payroll_page_and_livewire_writes(): void
    {
        $this->seed(RolePermissionSeeder::class);
        PermissionMatrix::set(Role::FINANCE, PanelModule::PAYROLL, []);
        $manager = User::factory()->role(Role::MANAGEMENT)->create();
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create())
            ->get(PayrollConfiguration::getUrl())->assertOk();
        foreach ([Role::FINANCE, Role::BARISTA, Role::RIDER, Role::STAFF, Role::CONTENT_CREATOR] as $role) {
            $this->actingAs($manager);
            $page = Livewire::test(PayrollConfiguration::class);
            $this->actingAs(User::factory()->role($role)->create());
            $this->get(PayrollConfiguration::getUrl())->assertForbidden();
            $page->call('saveBpjs')->assertForbidden();
        }
    }

    public function test_management_can_add_each_regulatory_version_without_replacing_history(): void
    {
        $this->seed(AccountSeeder::class);
        $this->seed(PayrollConfigurationSeeder::class);
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());

        Livewire::test(PayrollConfiguration::class)
            ->set('bpjs.jp_wage_cap_minor', 10000000)
            ->set('bpjs.health_wage_cap_minor', 12000000)
            ->set('bpjs.effective_from', '2026-10-01')
            ->set('bpjs.source_note', 'Synthetic verified fixture')
            ->call('saveBpjs')->assertHasNoErrors();
        $this->assertSame(1, DB::table('payroll_settings')->count());

        Livewire::test(PayrollConfiguration::class)
            ->set('ptkp.status', 'TK/0')
            ->set('ptkp.ter_category', 'A')
            ->set('ptkp.annual_amount_minor', 54000000)
            ->set('ptkp.effective_from', '2026-10-01')
            ->set('ptkp.source_note', 'Synthetic verified fixture')
            ->call('savePtkp')->assertHasNoErrors();
        $this->assertSame(1, DB::table('ptkp_rates')->count());

        Livewire::test(PayrollConfiguration::class)
            ->set('ter.category', 'A')
            ->set('ter.lower_gross_minor', 0)
            ->set('ter.upper_gross_minor', 10000000)
            ->set('ter.rate_bps', 500)
            ->set('ter.effective_from', '2026-10-01')
            ->set('ter.source_note', 'Synthetic verified fixture')
            ->call('saveTer')->assertHasNoErrors();
        $this->assertSame(1, DB::table('pph21_ter_brackets')->count());
        $this->assertNull(DB::table('payroll_ter_versions')->value('published_at'));
        Livewire::test(PayrollConfiguration::class)
            ->set('terPublication.category', 'A')
            ->set('terPublication.effective_from', '2026-10-01')
            ->call('publishTer')->assertHasErrors(['terPublication.effective_from']);
        Livewire::test(PayrollConfiguration::class)
            ->set('ter.category', 'A')
            ->set('ter.lower_gross_minor', 10000000)
            ->set('ter.upper_gross_minor', 11000000)
            ->set('ter.rate_bps', 1000)
            ->set('ter.effective_from', '2026-10-01')
            ->set('ter.source_note', 'Synthetic verified fixture')
            ->call('saveTer')->assertHasErrors(['ter.lower_gross_minor']);
        Livewire::test(PayrollConfiguration::class)
            ->set('ter.category', 'A')
            ->set('ter.lower_gross_minor', 10000001)
            ->set('ter.upper_gross_minor', null)
            ->set('ter.rate_bps', 1000)
            ->set('ter.effective_from', '2026-10-01')
            ->set('ter.source_note', 'Synthetic verified fixture')
            ->call('saveTer')->assertHasNoErrors();
        Livewire::test(PayrollConfiguration::class)
            ->set('terPublication.category', 'A')
            ->set('terPublication.effective_from', '2026-10-01')
            ->call('publishTer')->assertHasNoErrors();
        $this->assertNotNull(DB::table('payroll_ter_versions')->value('published_at'));
        Livewire::test(PayrollConfiguration::class)
            ->set('ter.category', 'A')
            ->set('ter.lower_gross_minor', 10000001)
            ->set('ter.upper_gross_minor', null)
            ->set('ter.rate_bps', 1000)
            ->set('ter.effective_from', '2026-10-01')
            ->set('ter.source_note', 'Synthetic verified fixture')
            ->call('saveTer')->assertHasErrors(['ter.effective_from']);

        Livewire::test(PayrollConfiguration::class)
            ->set('component.code', 'ADVANCE')
            ->set('component.name', 'Kasbon Baru')
            ->set('component.kind', 'DEDUCTION')
            ->set('component.taxable', false)
            ->set('component.account_id', DB::table('accounts')->where('code', '1-1500')->value('id'))
            ->set('component.effective_from', '2026-10-01')
            ->set('component.source_note', 'Synthetic verified fixture')
            ->call('saveComponent')->assertHasNoErrors();
        $this->assertSame(2, DB::table('payroll_component_types')->where('code', 'ADVANCE')->count());
        $this->assertSame('Kasbon', DB::table('payroll_component_types')->where('code', 'ADVANCE')->where('effective_from', '2026-01-01')->value('name'));
    }

    public function test_manual_component_retry_is_idempotent_and_changed_replay_is_rejected(): void
    {
        $this->seed(PayrollConfigurationSeeder::class);
        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create());
        $employee = User::factory()->role(Role::STAFF)->create();
        $employee->employeeProfile()->create(['base_salary_minor' => 1000000, 'payroll_active' => true]);
        $typeId = DB::table('payroll_component_types')->where('code', 'ADVANCE')->value('id');
        $page = Livewire::test(PayrollConfiguration::class);
        $key = $page->get('entry.request_key');
        $page->set('entry.user_id', $employee->id)
            ->set('entry.component_type_id', $typeId)
            ->set('entry.period_start', '2026-10-01')
            ->set('entry.amount_minor', 100000)
            ->call('saveEntry')->assertHasNoErrors();
        $employee->employeeProfile->update(['payroll_active' => false]);
        $page->set('entry.request_key', $key)->set('entry.amount_minor', 100000)
            ->call('saveEntry')->assertHasNoErrors();
        $this->assertSame(1, DB::table('payroll_component_entries')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'payroll_component_entry_created')->count());
        $page->set('entry.request_key', $key)->set('entry.amount_minor', 200000)
            ->call('saveEntry')->assertHasErrors(['entry.request_key']);
        $this->assertSame(1, DB::table('payroll_component_entries')->count());
    }
}
