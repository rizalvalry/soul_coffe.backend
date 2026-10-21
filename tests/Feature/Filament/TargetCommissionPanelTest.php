<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\TargetCommission;
use App\Filament\Resources\DailyTargets\DailyTargetResource;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TargetCommissionPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionMatrix::forget();
        $this->seed(RolePermissionSeeder::class);
    }

    public static function writers(): array
    {
        return ['Finance' => [Role::FINANCE], 'Management' => [Role::MANAGEMENT], 'Administrator' => [Role::ADMINISTRATOR]];
    }

    #[DataProvider('writers')]
    public function test_allowed_roles_can_write_tiers_from_cms(Role $role): void
    {
        $rider = User::factory()->role(Role::STAFF)->create();
        $this->actingAs(User::factory()->role($role)->create());
        $this->get(TargetCommission::getUrl())->assertOk()->assertSeeText($rider->name);
        Livewire::test(TargetCommission::class)
            ->set('effectiveFrom', '2026-10-19')
            ->set('targetRiderIds', [(string) $rider->id])
            ->set('tiers', [
                ['min_cups' => '100', 'amount_per_cup_minor' => '1000'],
                ['min_cups' => '200', 'amount_per_cup_minor' => '1500'],
            ])->call('saveTiers')->assertHasNoErrors();
        $this->assertDatabaseCount('rider_commission_versions', 1);
        $this->assertSame(2, DB::table('rider_commission_tiers')->count());
    }

    public function test_bulk_apply_copy_and_immutable_history(): void
    {
        $source = User::factory()->role(Role::STAFF)->create();
        $first = User::factory()->role(Role::STAFF)->create();
        $second = User::factory()->role(Role::STAFF)->create();
        $this->actingAs(User::factory()->role(Role::FINANCE)->create());
        $page = Livewire::test(TargetCommission::class)
            ->set('effectiveFrom', '2026-10-19')
            ->set('targetRiderIds', [(string) $source->id])
            ->set('tiers', [['min_cups' => '100', 'amount_per_cup_minor' => '1000']])
            ->call('saveTiers')->assertHasNoErrors();
        $page->set('targetRiderIds', [(string) $first->id, (string) $second->id])
            ->set('sourceRiderId', (string) $source->id)
            ->call('copyTiers')->assertHasNoErrors();
        $this->assertDatabaseCount('rider_commission_versions', 3);
        $page->set('tiers', [['min_cups' => '100', 'amount_per_cup_minor' => '2000']])
            ->call('saveTiers')->assertHasNoErrors();
        $this->assertDatabaseCount('rider_commission_versions', 5);
        $this->assertSame(1000, (int) DB::table('rider_commission_tiers')->orderBy('id')->value('amount_per_cup_minor'));
    }

    public function test_other_roles_can_open_target_commission_when_matrix_grants_it(): void
    {
        foreach ([Role::STAFF, Role::RIDER, Role::BARISTA, Role::CONTENT_CREATOR] as $role) {
            PermissionMatrix::set($role, PanelModule::RIDER_COMMISSIONS, ['view', 'edit']);
            $this->actingAs(User::factory()->role($role)->create());
            $this->get(TargetCommission::getUrl())->assertOk();
            $this->assertTrue(TargetCommission::canAccess());
        }
    }

    public function test_finance_template_opens_hidden_legacy_target_editor_by_url(): void
    {
        foreach ([Role::FINANCE, Role::MANAGEMENT] as $role) {
            $this->actingAs(User::factory()->role($role)->create());
            $this->get(DailyTargetResource::getUrl('index'))->assertOk();
            $this->get(DailyTargetResource::getUrl('create'))->assertOk();
            $this->assertTrue(DailyTargetResource::canCreate());
        }
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());
        $this->get(DailyTargetResource::getUrl('index'))->assertOk();
    }

    public function test_existing_database_without_finance_grant_is_upgraded_without_reseeding(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        DB::table('role_permissions')->where('role', Role::FINANCE->value)->delete();
        PermissionMatrix::forget();
        $this->actingAs($finance)->get(TargetCommission::getUrl())->assertForbidden();
        $migration = require database_path('migrations/2026_10_18_150000_create_rider_commission_tiers.php');
        $migration->upgradeExistingGrant();
        PermissionMatrix::forget();
        $this->get(TargetCommission::getUrl())->assertOk();
        $this->assertDatabaseHas('role_permissions', [
            'role' => Role::FINANCE->value, 'module' => PanelModule::RIDER_COMMISSIONS->value,
        ]);
    }
}
