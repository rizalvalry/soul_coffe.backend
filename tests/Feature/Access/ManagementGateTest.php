<?php

namespace Tests\Feature\Access;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ManagementGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_management_is_the_only_role_allowed_by_the_reporting_and_payroll_gates(): void
    {
        foreach (['view-management-reports', 'manage-payroll'] as $ability) {
            foreach (Role::cases() as $role) {
                $user = User::factory()->role($role)->create();

                $this->assertSame(
                    $role === Role::MANAGEMENT,
                    Gate::forUser($user)->allows($ability),
                    "{$role->value} got the wrong result for {$ability}.",
                );
            }
        }
    }

    public function test_management_has_a_client_facing_label(): void
    {
        $this->assertSame('Management', Role::MANAGEMENT->label());
    }
}
