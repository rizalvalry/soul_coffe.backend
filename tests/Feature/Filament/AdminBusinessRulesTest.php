<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\PriceVersionsRelationManager;
use App\Filament\Resources\StaffAssignments\Pages\CreateStaffAssignment;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Cart;
use App\Models\Location;
use App\Models\Product;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The rules the panel must not let an administrator break. Each of these is already enforced by
 * the API or the schema; the panel is a second way in, so it has to hold the same line.
 */
class AdminBusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->admin = User::where('role', Role::ADMINISTRATOR)->firstOrFail();
        $this->actingAs($this->admin);
    }

    public function test_creating_a_user_normalises_the_phone_number(): void
    {
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Budi Staff',
                // Typed the way an Indonesian admin actually types it.
                'phone_e164' => '081298765432',
                'role' => Role::STAFF->value,
                'password' => 'rahasia123',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('users', ['phone_e164' => '081298765432']);
    }

    public function test_a_duplicate_phone_is_rejected_even_in_a_different_format(): void
    {
        // The seeded admin is 081100000001; +6281100000001 is the same human being.
        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Penyusup',
                'phone_e164' => '+6281100000001',
                'role' => Role::STAFF->value,
                'password' => 'rahasia123',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['phone_e164']);
    }

    public function test_user_password_is_hashed_and_a_blank_edit_keeps_the_old_one(): void
    {
        $staff = User::where('role', Role::STAFF)->firstOrFail();
        $originalHash = $staff->password;

        Livewire::test(EditUser::class, ['record' => $staff->getRouteKey()])
            ->fillForm(['name' => 'Nama Baru', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $staff->refresh();
        $this->assertSame('Nama Baru', $staff->name);
        $this->assertSame($originalHash, $staff->password, 'Kata sandi lama harus tetap saat field dikosongkan.');

        Livewire::test(EditUser::class, ['record' => $staff->getRouteKey()])
            ->fillForm(['password' => 'gantibaru9'])
            ->call('save')
            ->assertHasNoFormErrors();

        $staff->refresh();
        $this->assertTrue(Hash::check('gantibaru9', $staff->password));
    }

    public function test_staff_pin_is_stored_hashed_and_verifies(): void
    {
        $staff = User::where('role', Role::STAFF)->firstOrFail();

        Livewire::test(EditUser::class, ['record' => $staff->getRouteKey()])
            ->fillForm(['pin_hash' => '654321'])
            ->call('save')
            ->assertHasNoFormErrors();

        $staff->refresh();
        $this->assertNotSame('654321', $staff->pin_hash);
        $this->assertTrue(Hash::check('654321', $staff->pin_hash));
    }

    public function test_r11_one_staff_cannot_hold_two_carts_on_the_same_day(): void
    {
        $existing = StaffAssignment::firstOrFail();
        $otherCart = Cart::where('id', '!=', $existing->cart_id)->firstOrFail();

        Livewire::test(CreateStaffAssignment::class)
            ->fillForm([
                'user_id' => $existing->user_id,
                'cart_id' => $otherCart->id,
                'location_id' => Location::firstOrFail()->id,
                'operating_date' => $existing->operating_date->toDateString(),
            ])
            ->call('create')
            ->assertHasFormErrors(['operating_date']);
    }

    public function test_r11_one_cart_cannot_hold_two_staff_on_the_same_day(): void
    {
        $existing = StaffAssignment::firstOrFail();
        $otherStaff = User::create([
            'name' => 'Staff Kedua',
            'phone_e164' => '081255550001',
            'password' => 'rahasia123',
            'role' => Role::STAFF,
            'is_active' => true,
        ]);

        Livewire::test(CreateStaffAssignment::class)
            ->fillForm([
                'user_id' => $otherStaff->id,
                'cart_id' => $existing->cart_id,
                'location_id' => Location::firstOrFail()->id,
                'operating_date' => $existing->operating_date->toDateString(),
            ])
            ->call('create')
            ->assertHasFormErrors(['operating_date']);
    }

    public function test_assignment_records_who_assigned_it_and_follows_the_cart_kitchen(): void
    {
        $staff = User::create([
            'name' => 'Staff Baru',
            'phone_e164' => '081255550002',
            'password' => 'rahasia123',
            'role' => Role::STAFF,
            'is_active' => true,
        ]);
        $cart = Cart::whereNotNull('kitchen_id')->firstOrFail();

        Livewire::test(CreateStaffAssignment::class)
            ->fillForm([
                'user_id' => $staff->id,
                'cart_id' => $cart->id,
                'location_id' => Location::firstOrFail()->id,
                'operating_date' => now()->addDay()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = StaffAssignment::where('user_id', $staff->id)->firstOrFail();

        $this->assertSame($this->admin->id, $created->assigned_by, 'assigned_by harus diambil dari sesi, bukan dari form.');
        $this->assertSame($cart->kitchen_id, $created->kitchen_id, 'kitchen_id harus mengikuti gerobak.');
    }

    public function test_r10_a_price_change_appends_a_version_and_never_edits_one(): void
    {
        $product = Product::firstOrFail();
        $before = $product->priceVersions()->count();
        $currentSell = $product->currentPriceVersion()?->sell_price_minor;

        Livewire::test(PriceVersionsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->callTableAction('create', data: [
                'cost_price_minor' => 9000,
                'sell_price_minor' => 23000,
                'effective_from' => now()->toDateTimeString(),
            ])
            ->assertHasNoTableActionErrors();

        $product->refresh();

        $this->assertSame($before + 1, $product->priceVersions()->count(), 'Perubahan harga harus menambah baris, bukan menimpa.');
        $this->assertSame(23000, $product->currentPriceVersion()->sell_price_minor);
        $this->assertNotSame($currentSell, $product->currentPriceVersion()->sell_price_minor);
    }

    public function test_r10_a_past_price_version_cannot_be_edited_or_deleted(): void
    {
        $product = Product::firstOrFail();

        // Structural, not cosmetic: the relation manager offers no way to rewrite history, so an
        // old price cannot be restated after a settlement has already been calculated from it.
        Livewire::test(PriceVersionsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');
    }
}
