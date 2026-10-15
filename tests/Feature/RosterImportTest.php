<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Cart;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Services\RosterImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class RosterImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): string
    {
        return base_path('tests/Fixtures/'.$name);
    }

    public function test_dry_run_previews_normalized_carts_without_writing(): void
    {
        Cart::query()->create(['code' => '0008']);
        Cart::query()->create(['code' => 'BLOK M 2']);

        $result = app(RosterImportService::class)->import($this->fixture('roster_synthetic.csv'), true);

        $this->assertSame(['created' => 4, 'updated' => 0, 'skipped' => 0, 'issues' => 0],
            array_intersect_key($result, array_flip(['created', 'updated', 'skipped', 'issues'])));
        $this->assertFalse($result['aborted']);
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, EmployeeProfile::query()->count());
    }

    public function test_import_is_idempotent_and_preserves_existing_credentials(): void
    {
        $cartOne = Cart::query()->create(['code' => '0008']);
        $cartTwo = Cart::query()->create(['code' => 'BLOK M 2']);
        $existing = User::factory()->role(Role::STAFF)->create([
            'name' => 'Ari Uji',
            'phone_e164' => '081234560008',
            'pin_hash' => Hash::make('123456'),
        ]);
        $password = $existing->password;
        $pin = $existing->pin_hash;

        $service = app(RosterImportService::class);
        $first = $service->import($this->fixture('roster_synthetic.csv'));
        $second = $service->import($this->fixture('roster_synthetic.csv'));

        $this->assertSame(3, $first['created']);
        $this->assertSame(1, $first['updated']);
        $this->assertSame(0, $first['issues']);
        $this->assertSame(4, $second['skipped']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(4, User::query()->count());
        $this->assertSame(4, EmployeeProfile::query()->count());
        $this->assertSame($password, $existing->fresh()->password);
        $this->assertSame($pin, $existing->fresh()->pin_hash);
        $this->assertSame('081234560008', $existing->fresh()->phone_e164);
        $this->assertSame($cartOne->id, $existing->fresh()->employeeProfile->default_cart_id);
        $this->assertSame($cartTwo->id, User::query()->where('name', 'Bima Uji')->firstOrFail()->employeeProfile->default_cart_id);

        $newRider = User::query()->where('name', 'Bima Uji')->firstOrFail();
        $this->assertFalse($newRider->is_active);
        $this->assertTrue($newRider->roster_onboarding);
        $this->assertNull($newRider->phone_e164);
        $this->assertSame(Role::MANAGEMENT, User::query()->where('name', 'Dina Uji')->firstOrFail()->role);
    }

    public function test_unknown_cart_aborts_without_creating_any_accounts(): void
    {
        $result = app(RosterImportService::class)->import($this->fixture('roster_unknown_cart.csv'));

        $this->assertTrue($result['aborted']);
        $this->assertSame(1, $result['issues']);
        $this->assertSame('kode gerobak tidak ditemukan', $result['rows'][0]['reason']);
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, DB::table('employee_profiles')->count());
    }

    public function test_imported_rider_needs_real_credentials_before_activation(): void
    {
        Cart::query()->create(['code' => '0008']);
        Cart::query()->create(['code' => 'BLOK M 2']);
        app(RosterImportService::class)->import($this->fixture('roster_synthetic.csv'));
        $rider = User::query()->where('name', 'Bima Uji')->firstOrFail();
        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());

        Livewire::test(EditUser::class, ['record' => $rider->id])
            ->fillForm(['is_active' => true])
            ->call('save')->assertHasFormErrors(['phone_e164']);
        $this->assertFalse($rider->fresh()->is_active);

        Livewire::test(EditUser::class, ['record' => $rider->id])
            ->fillForm(['phone_e164' => '081234560010', 'is_active' => true])
            ->call('save')->assertHasFormErrors(['password']);
        $this->assertFalse($rider->fresh()->is_active);

        Livewire::test(EditUser::class, ['record' => $rider->id])
            ->fillForm(['phone_e164' => '081234560010', 'password' => 'newsecret', 'is_active' => true])
            ->call('save')->assertHasFormErrors(['pin_hash']);
        $this->assertFalse($rider->fresh()->is_active);

        Livewire::test(EditUser::class, ['record' => $rider->id])
            ->fillForm(['phone_e164' => '081234560010', 'password' => 'newsecret', 'pin_hash' => '654321', 'is_active' => true])
            ->call('save')->assertHasNoFormErrors();
        $this->assertTrue($rider->fresh()->is_active);
        $this->assertFalse($rider->fresh()->roster_onboarding);
        $this->assertTrue(Hash::check('newsecret', $rider->fresh()->password));
        $this->assertTrue(Hash::check('654321', $rider->fresh()->pin_hash));
    }
}
