<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Filament\Resources\Recipes\RecipeResource;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RecipePanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->role(Role::ADMINISTRATOR)->create());
    }

    public function test_creating_a_recipe_from_the_panel_posts_through_the_service(): void
    {
        $product = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup', 'is_sellable' => true,
            'sort_order' => 1, 'is_active' => true,
        ]);
        $milk = RawMaterial::create(['code' => 'SUSU', 'name' => 'Susu', 'unit' => 'ml', 'is_active' => true, 'sort_order' => 1]);

        Livewire::test(CreateRecipe::class)
            ->fillForm([
                'product_id' => $product->id,
                'lines' => [
                    ['raw_material_id' => $milk->id, 'qty_per_unit' => 150],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('recipes', ['product_id' => $product->id, 'version' => 1]);
        $this->assertDatabaseHas('recipe_lines', ['raw_material_id' => $milk->id, 'qty_per_unit' => 150]);
    }

    /** No Eloquent edit route — a correction is a new version, not an edit. */
    public function test_the_resource_offers_no_edit_route(): void
    {
        $this->assertArrayNotHasKey('edit', RecipeResource::getPages());
    }
}
