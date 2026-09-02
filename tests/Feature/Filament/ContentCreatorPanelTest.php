<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Resources\NewsPosts\NewsPostResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CONTENT_CREATOR was the first non-administrator ever given a way into the Filament panel, and
 * panel access is a door, not an authorisation.
 *
 * Filament allows a resource by default when no policy exists, so before this role existed every
 * resource was protected only by the fact that nobody but an ADMINISTRATOR could get in. The
 * `AdministratorOnly` trait closed that; the enumerating test below is what keeps it closed, by
 * failing the moment a new resource is added without it.
 */
class ContentCreatorPanelTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(Role $role, bool $active = true): User
    {
        return User::create([
            'name' => 'Panel '.$role->value,
            'phone_e164' => '+6281100'.rand(100000, 999999),
            'password' => 'rahasia123',
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    public function test_a_content_creator_can_open_the_panel(): void
    {
        $creator = $this->makeUser(Role::CONTENT_CREATOR);

        $this->actingAs($creator)->get('/admin')->assertSuccessful();
    }

    public function test_a_deactivated_content_creator_is_refused(): void
    {
        $creator = $this->makeUser(Role::CONTENT_CREATOR, active: false);

        $this->actingAs($creator)->get('/admin')->assertForbidden();
    }

    public function test_a_content_creator_may_manage_news(): void
    {
        $this->actingAs($this->makeUser(Role::CONTENT_CREATOR));

        $this->assertTrue(NewsPostResource::canViewAny());
        $this->assertTrue(NewsPostResource::canCreate());
    }

    /**
     * Renders the actual pages, not just the permission booleans.
     *
     * A misnamed Filament component or a bad column definition passes every authorisation check
     * and then throws the moment someone opens the screen — which is a defect only a render
     * catches.
     */
    public function test_the_news_pages_render_for_a_content_creator(): void
    {
        $this->actingAs($this->makeUser(Role::CONTENT_CREATOR));

        $this->get(NewsPostResource::getUrl('index'))->assertSuccessful();
        $this->get(NewsPostResource::getUrl('create'))->assertSuccessful();
    }

    /**
     * The guard that matters, and the one that must not rot.
     *
     * Enumerated from Filament's own registry rather than a hand-written list, so a resource added
     * next month is covered the day it is registered instead of the day someone remembers to add
     * it here.
     */
    public function test_a_content_creator_cannot_reach_any_other_resource(): void
    {
        $creator = $this->makeUser(Role::CONTENT_CREATOR);
        $this->actingAs($creator);

        $resources = Filament::getPanel('admin')->getResources();
        $this->assertNotEmpty($resources, 'No Filament resources registered — the guard would pass vacuously.');

        $checked = 0;
        foreach ($resources as $resource) {
            if ($resource === NewsPostResource::class) {
                continue;
            }

            $this->assertFalse(
                $resource::canViewAny(),
                "{$resource} is visible to CONTENT_CREATOR. Add the AdministratorOnly trait to it."
            );
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, 'Only the news resource is registered — nothing was actually verified.');
    }

    public function test_an_administrator_still_reaches_everything(): void
    {
        $this->actingAs($this->makeUser(Role::ADMINISTRATOR));

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $this->assertTrue($resource::canViewAny(), "{$resource} is hidden from ADMINISTRATOR.");
        }
    }

    /** The operational roles gained nothing from this change. */
    public function test_operational_roles_are_still_locked_out_of_the_panel(): void
    {
        foreach ([Role::FINANCE, Role::BARISTA, Role::RIDER, Role::STAFF] as $role) {
            $this->actingAs($this->makeUser($role))->get('/admin')->assertForbidden();
        }
    }
}
