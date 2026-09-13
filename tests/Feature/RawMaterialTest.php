<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Filament\Resources\RawMaterials\Pages\CreateRawMaterial;
use App\Filament\Resources\RawMaterials\Pages\EditRawMaterial;
use App\Models\RawMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RawMaterialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());
    }

    public function test_a_raw_material_can_be_created(): void
    {
        Livewire::test(CreateRawMaterial::class)
            ->fillForm([
                'code' => 'SUSU-UHT',
                'name' => 'Susu UHT',
                'unit' => 'ml',
                'reorder_point' => 5000,
                'sort_order' => 1,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('raw_materials', ['code' => 'SUSU-UHT', 'unit' => 'ml']);
    }

    public function test_the_code_must_be_unique(): void
    {
        RawMaterial::create([
            'code' => 'GULA', 'name' => 'Gula Aren', 'unit' => 'g', 'is_active' => true, 'sort_order' => 1,
        ]);

        Livewire::test(CreateRawMaterial::class)
            ->fillForm([
                'code' => 'GULA',
                'name' => 'Gula Aren Lain',
                'unit' => 'g',
                'sort_order' => 2,
            ])
            ->call('create')
            ->assertHasFormErrors(['code']);
    }

    public function test_a_raw_material_can_be_edited(): void
    {
        $material = RawMaterial::create([
            'code' => 'ES-BATU', 'name' => 'Es Batu', 'unit' => 'pcs', 'is_active' => true, 'sort_order' => 1,
        ]);

        Livewire::test(EditRawMaterial::class, ['record' => $material->getRouteKey()])
            ->fillForm(['name' => 'Es Batu Kristal'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Es Batu Kristal', $material->fresh()->name);
    }
}
