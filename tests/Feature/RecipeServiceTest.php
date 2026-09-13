<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\User;
use App\Services\RecipeService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RecipeServiceTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private RawMaterial $milk;

    private RawMaterial $coffeeBean;

    private User $admin;

    private CentralKitchen $kitchen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup', 'is_sellable' => true,
            'sort_order' => 1, 'is_active' => true,
        ]);

        $this->milk = RawMaterial::create(['code' => 'SUSU', 'name' => 'Susu', 'unit' => 'ml', 'is_active' => true, 'sort_order' => 1]);
        $this->coffeeBean = RawMaterial::create(['code' => 'BIJI', 'name' => 'Biji Kopi', 'unit' => 'g', 'is_active' => true, 'sort_order' => 2]);

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1', 'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);
    }

    private function service(): RecipeService
    {
        return app(RecipeService::class);
    }

    public function test_creating_a_recipe_makes_it_the_current_one(): void
    {
        $recipe = $this->service()->create($this->product->id, [
            ['raw_material_id' => $this->milk->id, 'qty_per_unit' => 150],
            ['raw_material_id' => $this->coffeeBean->id, 'qty_per_unit' => 18],
        ], $this->admin);

        $this->assertSame(1, $recipe->version);
        $this->assertCount(2, $recipe->recipeLines);

        $current = $this->service()->currentFor($this->product->id);
        $this->assertSame($recipe->id, $current->id);
    }

    public function test_a_new_version_does_not_mutate_the_old_one(): void
    {
        $v1 = $this->service()->create($this->product->id, [
            ['raw_material_id' => $this->milk->id, 'qty_per_unit' => 150],
        ], $this->admin);

        $v2 = $this->service()->create($this->product->id, [
            ['raw_material_id' => $this->milk->id, 'qty_per_unit' => 200],
        ], $this->admin);

        $this->assertSame(1, $v1->version);
        $this->assertSame(2, $v2->version);

        // The old row is untouched.
        $this->assertSame(150, $v1->fresh()->recipeLines->firstOrFail()->qty_per_unit);

        $current = $this->service()->currentFor($this->product->id);
        $this->assertSame($v2->id, $current->id);
    }

    public function test_at_least_one_line_is_required(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service()->create($this->product->id, [], $this->admin);
    }

    public function test_a_zero_or_negative_quantity_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service()->create($this->product->id, [
            ['raw_material_id' => $this->milk->id, 'qty_per_unit' => 0],
        ], $this->admin);
    }

    public function test_the_same_raw_material_cannot_appear_twice_in_one_recipe(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service()->create($this->product->id, [
            ['raw_material_id' => $this->milk->id, 'qty_per_unit' => 100],
            ['raw_material_id' => $this->milk->id, 'qty_per_unit' => 50],
        ], $this->admin);
    }

    public function test_current_for_returns_null_when_no_recipe_exists(): void
    {
        $this->assertNull($this->service()->currentFor($this->product->id));
    }

    public function test_cost_is_computed_from_the_latest_purchase_in(): void
    {
        $recipe = $this->service()->create($this->product->id, [
            ['raw_material_id' => $this->milk->id, 'qty_per_unit' => 150],
            ['raw_material_id' => $this->coffeeBean->id, 'qty_per_unit' => 18],
        ], $this->admin);

        // Rp 50/ml of milk, Rp 200/g of coffee bean.
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::RAW_MATERIAL_STORE,
            locationId: $this->kitchen->id,
            productId: null,
            movementType: MovementType::PURCHASE_IN,
            qty: 1000,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
            rawMaterialId: $this->milk->id,
            costMinor: 50000,
        );

        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::RAW_MATERIAL_STORE,
            locationId: $this->kitchen->id,
            productId: null,
            movementType: MovementType::PURCHASE_IN,
            qty: 500,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
            rawMaterialId: $this->coffeeBean->id,
            costMinor: 100000,
        );

        $this->service()->refreshCostsForRawMaterials(
            [$this->milk->id, $this->coffeeBean->id],
            $this->kitchen->id,
        );

        // 150*50 + 18*200 = 7500 + 3600 = 11100
        $this->assertSame(11100, $recipe->fresh()->computed_cost_minor);
    }

    public function test_cost_stays_null_when_an_ingredient_has_no_purchase_yet(): void
    {
        $recipe = $this->service()->create($this->product->id, [
            ['raw_material_id' => $this->milk->id, 'qty_per_unit' => 150],
        ], $this->admin);

        $this->service()->refreshCostsForRawMaterials([$this->milk->id], $this->kitchen->id);

        $this->assertNull($recipe->fresh()->computed_cost_minor);
    }
}
