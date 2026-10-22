<?php

namespace Tests\Feature\Access;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\PayrollRuns;
use App\Filament\Pages\RoleAccessMatrix;
use App\Models\AuditLog;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SuperAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_bootstraps_audits_and_protects_last_active_super_administrator(): void
    {
        $first = User::factory()->role(Role::ADMINISTRATOR)->create(['phone_e164' => '081234567890']);
        $second = User::factory()->role(Role::ADMINISTRATOR)->create(['phone_e164' => '081234567891']);

        $this->assertSame(0, Artisan::call('soul:super-admin', ['phone' => '+6281234567890']));
        $this->assertTrue($first->fresh()->is_super_admin);
        $this->assertSame(1, Artisan::call('soul:super-admin', ['phone' => '081234567890', '--revoke' => true]));
        $this->assertTrue($first->fresh()->is_super_admin);

        $this->assertSame(0, Artisan::call('soul:super-admin', ['phone' => '081234567891']));
        $this->assertSame(0, Artisan::call('soul:super-admin', ['phone' => '081234567890', '--revoke' => true]));
        $this->assertFalse($first->fresh()->is_super_admin);
        $this->assertTrue($second->fresh()->is_super_admin);

        $logs = AuditLog::query()->where('action', 'super_admin.status_changed')->orderBy('id')->get();
        $this->assertCount(3, $logs);
        $this->assertSame('SYSTEM', $logs->first()->actor_role);
        $this->assertSame(false, $logs->first()->before_json['is_super_admin']);
        $this->assertSame(true, $logs->first()->after_json['is_super_admin']);
        $this->assertSame(3, OutboxEvent::query()->where('name', 'AccessChanged')->count());
    }

    public function test_cli_rejects_nonadministrator_and_inactive_account(): void
    {
        $barista = User::factory()->role(Role::BARISTA)->create(['phone_e164' => '081234567892']);
        $inactive = User::factory()->role(Role::ADMINISTRATOR)->create(['phone_e164' => '081234567893', 'is_active' => false]);

        $this->assertSame(1, Artisan::call('soul:super-admin', ['phone' => $barista->phone_e164]));
        $this->assertSame(1, Artisan::call('soul:super-admin', ['phone' => $inactive->phone_e164]));
        $this->assertFalse($barista->fresh()->is_super_admin);
        $this->assertFalse($inactive->fresh()->is_super_admin);
    }

    public function test_payroll_revocation_blocks_ordinary_administrator_in_cms_and_api(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        PermissionMatrix::set(Role::ADMINISTRATOR, PanelModule::PAYROLL, []);

        $this->actingAs($admin)->get(PayrollRuns::getUrl())->assertForbidden();
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/payroll/my-slips')->assertForbidden();
        $this->actingAs($super, 'web')->get(PayrollRuns::getUrl())->assertOk();
        $this->actingAs($super, 'sanctum')->getJson('/api/v1/payroll/my-slips')->assertOk();
    }

    public function test_role_matrix_page_moves_from_transitional_admin_to_super_administrator(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->actingAs($admin)->get(RoleAccessMatrix::getUrl())->assertOk()->assertSee('Belum ada Super Administrator');

        $super = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $this->actingAs($admin)->get(RoleAccessMatrix::getUrl())->assertForbidden();
        $this->actingAs(User::factory()->role(Role::FINANCE)->create())->get(RoleAccessMatrix::getUrl())->assertForbidden();
        $this->actingAs($super)->get(RoleAccessMatrix::getUrl())->assertOk();
    }

    public function test_me_returns_effective_abilities_and_super_administrator_label(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.is_super_admin', true)
            ->assertJsonPath('data.role_label', 'Super Administrator')
            ->assertJsonPath('data.abilities.payroll.0', 'view');
    }

    public function test_me_hides_access_settings_from_ordinary_administrator_after_bootstrap(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        User::factory()->role(Role::ADMINISTRATOR)->create(['is_super_admin' => true]);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/me')->assertOk()
            ->assertJsonPath('data.abilities.role_matrix', []);
    }

    public function test_user_seeder_skips_nonlocal_environment(): void
    {
        $previous = app()->environment();
        app()->detectEnvironment(fn (): string => 'production');
        try {
            (new UserSeeder)->run();
            $this->assertDatabaseCount('users', 0);
        } finally {
            app()->detectEnvironment(fn (): string => $previous);
        }
    }
}
