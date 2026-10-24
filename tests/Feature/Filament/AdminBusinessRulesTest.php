<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\RelationManagers\LocationPriceVersionsRelationManager;
use App\Filament\Resources\Products\RelationManagers\PriceVersionsRelationManager;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\LocationPriceVersion;
use App\Models\Product;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use RuntimeException;
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
                'name' => 'Rider Uji Empat Staff',
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

    public function test_location_price_history_is_append_only_and_audited(): void
    {
        $product = Product::firstOrFail();
        $location = Location::firstOrFail();
        Livewire::test(LocationPriceVersionsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])->callTableAction('create', data: [
            'location_id' => $location->id,
            'sell_price_minor' => 15000,
            'effective_from' => now()->addDay()->toDateString(),
        ])->assertHasNoTableActionErrors()
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete');
        $this->assertSame(15000, $product->locationPriceVersions()->firstOrFail()->sell_price_minor);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'location_price.created',
            'subject_type' => LocationPriceVersion::class,
        ]);
    }

    public function test_role_without_product_price_rights_gets_403_for_location_price_page(): void
    {
        $product = Product::firstOrFail();
        $finance = User::factory()->role(Role::FINANCE)->create();
        PermissionMatrix::set(Role::FINANCE, PanelModule::PRODUCTS, ['view', 'edit']);
        PermissionMatrix::set(Role::FINANCE, PanelModule::INVENTORY_COSTS, ['view']);
        PermissionMatrix::forget();
        $this->actingAs($finance)->get(ProductResource::getUrl('edit', ['record' => $product]))
            ->assertOk();
        Livewire::actingAs($finance)->test(LocationPriceVersionsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])->call('mountTableAction', 'create')->assertForbidden();
        $this->assertDatabaseCount('location_price_versions', 0);
    }

    public function test_location_price_cannot_be_added_before_product_has_a_base_price(): void
    {
        $product = Product::create([
            'code' => 'NEW-P', 'name' => 'Produk Baru', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 99, 'is_active' => true,
        ]);
        Livewire::test(LocationPriceVersionsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])->callTableAction('create', data: [
            'location_id' => Location::firstOrFail()->id,
            'sell_price_minor' => 15000,
            'effective_from' => now()->addDay()->toDateString(),
        ])->assertHasTableActionErrors(['sell_price_minor']);
        $this->assertDatabaseCount('location_price_versions', 0);
    }

    public function test_location_price_rejects_today_and_past_dates(): void
    {
        $product = Product::firstOrFail();
        foreach ([today()->toDateString(), today()->subDay()->toDateString()] as $date) {
            Livewire::test(LocationPriceVersionsRelationManager::class, [
                'ownerRecord' => $product,
                'pageClass' => EditProduct::class,
            ])->callTableAction('create', data: [
                'location_id' => Location::firstOrFail()->id,
                'sell_price_minor' => 15000,
                'effective_from' => $date,
            ])->assertHasTableActionErrors(['effective_from']);
        }
        $this->assertDatabaseCount('location_price_versions', 0);
    }

    public function test_location_price_and_audit_roll_back_together_when_audit_fails(): void
    {
        AuditLog::creating(function (AuditLog $log): void {
            if ($log->action === 'location_price.created') {
                throw new RuntimeException('Audit gagal');
            }
        });

        try {
            Livewire::test(LocationPriceVersionsRelationManager::class, [
                'ownerRecord' => Product::firstOrFail(),
                'pageClass' => EditProduct::class,
            ])->callTableAction('create', data: [
                'location_id' => Location::firstOrFail()->id,
                'sell_price_minor' => 15000,
                'effective_from' => now()->addDay()->toDateString(),
            ]);
            $this->fail('Audit failure should roll back the price version.');
        } catch (RuntimeException $e) {
            $this->assertSame('Audit gagal', $e->getMessage());
        }

        $this->assertDatabaseCount('location_price_versions', 0);
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
                'effective_from' => now()->addDay()->toDateString(),
            ])
            ->assertHasNoTableActionErrors();

        $product->refresh();

        $this->assertSame($before + 1, $product->priceVersions()->count(), 'Perubahan harga harus menambah baris, bukan menimpa.');
        $this->assertSame($currentSell, $product->currentPriceVersion()->sell_price_minor);
        $this->assertSame(23000, $product->priceVersions()->latest('id')->firstOrFail()->sell_price_minor);
    }

    public function test_base_price_change_cannot_start_today(): void
    {
        Livewire::test(PriceVersionsRelationManager::class, [
            'ownerRecord' => Product::firstOrFail(),
            'pageClass' => EditProduct::class,
        ])->callTableAction('create', data: [
            'cost_price_minor' => 9000,
            'sell_price_minor' => 23000,
            'effective_from' => now()->toDateString(),
        ])->assertHasTableActionErrors(['effective_from']);
    }

    public function test_first_base_price_can_start_today_for_new_product(): void
    {
        $product = Product::create([
            'code' => 'NEW-PRICE', 'name' => 'Minuman Baru', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 99, 'is_active' => true,
        ]);
        Livewire::test(PriceVersionsRelationManager::class, [
            'ownerRecord' => $product,
            'pageClass' => EditProduct::class,
        ])->callTableAction('create', data: [
            'cost_price_minor' => 8000,
            'sell_price_minor' => 12000,
            'effective_from' => today()->toDateString(),
        ])->assertHasNoTableActionErrors();

        $this->assertSame(12000, $product->priceVersionAt(today()->startOfDay())->sell_price_minor);
        $this->assertSame(8000, $product->priceVersionAt(today()->startOfDay())->cost_price_minor);
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
