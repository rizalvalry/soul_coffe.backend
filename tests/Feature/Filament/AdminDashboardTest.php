<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Models\User;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dashboard renders every widget's getStats()/getData() for real against the database. A
 * widget that throws only when a human opens the panel is exactly the failure mode
 * `AdminResourcesTest`'s docblock warns about for resources — this is that same guarantee for
 * the dashboard.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_with_no_operational_data_yet(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();

        $this->actingAs($admin)->get(Dashboard::getUrl())->assertSuccessful();
    }

    public function test_a_non_administrator_cannot_reach_the_dashboard(): void
    {
        $staff = User::factory()->role(Role::STAFF)->create();

        $this->actingAs($staff)->get('/admin')->assertForbidden();
    }
}
