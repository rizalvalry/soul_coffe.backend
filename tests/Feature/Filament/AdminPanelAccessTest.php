<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Auth\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The panel is a second front door onto data the API already guards. These tests pin the two
 * things that make it safe: only an active ADMINISTRATOR gets in, and the phone number it
 * authenticates by is normalised the same way the API normalises it.
 */
class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(Role $role, bool $active = true): User
    {
        return User::create([
            'name' => 'Panel '.$role->value,
            'phone_e164' => '08110000'.rand(1000, 9999),
            'password' => 'rahasia123',
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    public function test_administrator_can_open_the_panel(): void
    {
        $admin = $this->makeUser(Role::ADMINISTRATOR);

        $this->actingAs($admin)->get('/admin')->assertSuccessful();
    }

    public function test_every_non_administrator_role_is_refused(): void
    {
        foreach ([Role::FINANCE, Role::BARISTA, Role::RIDER, Role::STAFF] as $role) {
            $user = $this->makeUser($role);

            $this->actingAs($user)->get('/admin')->assertForbidden();
        }
    }

    public function test_a_deactivated_administrator_is_refused(): void
    {
        $admin = $this->makeUser(Role::ADMINISTRATOR, active: false);

        $this->actingAs($admin)->get('/admin')->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_login_accepts_a_local_format_number_and_normalises_it(): void
    {
        $admin = User::create([
            'name' => 'Rizal Admin',
            'phone_e164' => '081100000001',
            'password' => 'admin123',
            'role' => Role::ADMINISTRATOR,
            'is_active' => true,
        ]);

        // Typed as 08…, stored as +62… — the panel must bridge that, as the API does.
        Livewire::test(Login::class)
            ->fillForm([
                'phone' => '081100000001',
                'password' => 'admin123',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        User::create([
            'name' => 'Rizal Admin',
            'phone_e164' => '081100000001',
            'password' => 'admin123',
            'role' => Role::ADMINISTRATOR,
            'is_active' => true,
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'phone' => '081100000001',
                'password' => 'salah',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['phone']);

        $this->assertGuest();
    }

    public function test_password_is_stored_hashed_not_plain(): void
    {
        $admin = $this->makeUser(Role::ADMINISTRATOR);

        $this->assertNotSame('rahasia123', $admin->fresh()->password);
        $this->assertTrue(Hash::check('rahasia123', $admin->fresh()->password));
    }
}
