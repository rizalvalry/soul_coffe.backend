<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());
    }

    public function test_a_supplier_can_be_created(): void
    {
        Livewire::test(CreateSupplier::class)
            ->fillForm([
                'code' => 'SUP-01',
                'name' => 'Toko Bahan Segar',
                'contact_name' => 'Budi',
                'phone' => '081200000000',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('suppliers', ['code' => 'SUP-01', 'name' => 'Toko Bahan Segar']);
    }

    public function test_the_code_must_be_unique(): void
    {
        Supplier::create(['code' => 'SUP-02', 'name' => 'Pemasok A', 'is_active' => true]);

        Livewire::test(CreateSupplier::class)
            ->fillForm(['code' => 'SUP-02', 'name' => 'Pemasok B'])
            ->call('create')
            ->assertHasFormErrors(['code']);
    }

    public function test_a_supplier_can_be_edited(): void
    {
        $supplier = Supplier::create(['code' => 'SUP-03', 'name' => 'Pemasok C', 'is_active' => true]);

        Livewire::test(EditSupplier::class, ['record' => $supplier->getRouteKey()])
            ->fillForm(['name' => 'Pemasok C Baru'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Pemasok C Baru', $supplier->fresh()->name);
    }
}
