<?php

namespace Tests\Feature\Access;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Models\RefillRequest;
use App\Models\User;
use App\Policies\DailyAllocationPolicy;
use App\Policies\RefillRequestPolicy;
use App\Services\Access\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ManagementGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_gate_uses_the_matrix_and_rejects_inactive_accounts(): void
    {
        $gates = [
            'view-management-reports' => [PanelModule::MANAGEMENT_REPORTS, 'view'],
            'manage-payroll' => [PanelModule::PAYROLL, 'edit'],
            'manage-tax-settings' => [PanelModule::TAX_SETTINGS, 'edit'],
            'manage-accounting-periods' => [PanelModule::ACCOUNTING_PERIODS, 'edit'],
            'view-fixed-assets' => [PanelModule::FIXED_ASSETS, 'view'],
            'record-fixed-assets' => [PanelModule::FIXED_ASSETS, 'edit'],
            'post-fixed-asset-depreciation' => [PanelModule::FIXED_ASSET_DEPRECIATION, 'edit'],
            'void-production' => [PanelModule::INVENTORY_COSTS, 'edit'],
            'revalue-inventory' => [PanelModule::INVENTORY_COSTS, 'edit'],
            'post-manual-journal' => [PanelModule::JOURNALS, 'edit'],
        ];

        foreach ($gates as $ability => [$module, $operation]) {
            foreach (Role::cases() as $role) {
                $user = User::factory()->role($role)->create();

                $this->assertSame(
                    PermissionMatrix::can($user, $module, $operation),
                    Gate::forUser($user)->allows($ability),
                    "{$role->value} got the wrong result for {$ability}.",
                );
                $user->update(['is_active' => false]);
                $this->assertFalse(Gate::forUser($user)->allows($ability), "Inactive {$role->value} passed {$ability}.");
            }
        }
    }

    public function test_management_has_a_client_facing_label(): void
    {
        $this->assertSame('Management', Role::MANAGEMENT->label());
    }

    public function test_inactive_administrator_token_cannot_read_protected_api_routes(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create(['is_active' => false]);
        foreach (['/api/v1/products', '/api/v1/refills', '/api/v1/settlements/queue'] as $url) {
            $this->actingAs($admin, 'sanctum')->getJson($url)->assertForbidden();
        }
    }

    public function test_inactive_administrator_has_no_refill_or_allocation_policy_rights(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create(['is_active' => false]);
        $refillPolicy = app(RefillRequestPolicy::class);
        $this->assertFalse($refillPolicy->viewAny($admin));
        $this->assertFalse($refillPolicy->view($admin, new RefillRequest));
        $this->assertFalse($refillPolicy->approve($admin, new RefillRequest));
        $this->assertFalse($refillPolicy->reject($admin, new RefillRequest));
        $this->assertFalse(app(DailyAllocationPolicy::class)->viewAny($admin));
    }
}
