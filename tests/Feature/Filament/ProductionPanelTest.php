<?php

namespace Tests\Feature\Filament;

use App\Enums\Role;
use App\Filament\Resources\Productions\ProductionResource;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Pure reporting: no create, edit or delete route exists at all — see ProductionResource. */
class ProductionPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_resource_offers_no_create_edit_or_delete_route(): void
    {
        $pages = ProductionResource::getPages();

        $this->assertArrayHasKey('index', $pages);
        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }

    public function test_an_administrator_sees_the_production_list(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1', 'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);
        $barista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $kitchen->id]);
        $product = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup', 'is_sellable' => true,
            'sort_order' => 1, 'is_active' => true,
        ]);

        app(ProductionService::class)->brew($kitchen->id, [$product->id => 5], $barista);

        $this->actingAs($admin)
            ->get(ProductionResource::getUrl())
            ->assertSuccessful()
            ->assertSee('Kopi Susu');
    }
}
