<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Pages\PayrollConfiguration;
use App\Models\User;
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

        Livewire::test(PayrollConfiguration::class)
            ->set('incentive.effective_from', '2026-10-01')
            ->set('incentive.amount_per_cup_minor', 2000)
            ->set('incentive.target_cups', 10)
            ->set('incentive.bonus_minor', 5000)
            ->set('incentive.source_note', 'Approved test policy')
            ->call('saveIncentive')->assertHasNoErrors();
        $this->assertSame(2, DB::table('rider_incentive_rules')->count());

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

    public function test_administrator_and_finance_get_403_on_payroll_page_and_livewire_writes(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $manager = User::factory()->role(Role::MANAGEMENT)->create();
        foreach ([Role::ADMINISTRATOR, Role::FINANCE] as $role) {
            $this->actingAs($manager);
            $page = Livewire::test(PayrollConfiguration::class);
            $this->actingAs(User::factory()->role($role)->create());
            $this->get(PayrollConfiguration::getUrl())->assertForbidden();
            $page->call('saveBpjs')->assertForbidden();
        }
    }

    public function test_management_can_add_each_regulatory_version_without_replacing_history(): void
    {
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
