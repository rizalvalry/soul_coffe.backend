<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\ProductionService;
use App\Services\RecipeService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * ProductionService — the brewing act, now raw-material-aware.
 *
 * The test that matters most is
 * `test_two_products_sharing_one_ingredient_are_checked_together_not_separately`: it is the whole
 * reason demand is aggregated across every product in one brew request BEFORE any lock is taken,
 * rather than checked product-by-product.
 */
class ProductionServiceTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private User $barista;

    private Product $latte;

    private Product $cappuccino;

    private RawMaterial $milk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1', 'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);
        $this->barista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);

        $this->latte = Product::create([
            'code' => 'LATTE', 'name' => 'Latte', 'unit' => 'cup', 'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);
        $this->cappuccino = Product::create([
            'code' => 'CAPPU', 'name' => 'Cappuccino', 'unit' => 'cup', 'is_sellable' => true, 'sort_order' => 2, 'is_active' => true,
        ]);

        $this->milk = RawMaterial::create(['code' => 'SUSU', 'name' => 'Susu', 'unit' => 'ml', 'is_active' => true, 'sort_order' => 1]);
    }

    private function service(): ProductionService
    {
        return app(ProductionService::class);
    }

    private function seedMilk(int $qty): void
    {
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::RAW_MATERIAL_STORE,
            locationId: $this->kitchen->id,
            productId: null,
            movementType: MovementType::PURCHASE_IN,
            qty: $qty,
            actorId: $this->barista->id,
            kitchenId: $this->kitchen->id,
            rawMaterialId: $this->milk->id,
            costMinor: $qty * 10,
        );
    }

    private function milkStock(): int
    {
        return app(StockLedgerService::class)
            ->rawMaterialStockFor(StockLedgerService::RAW_MATERIAL_STORE, $this->kitchen->id, $this->milk->id);
    }

    // ── (a) No-recipe products still brew, exactly like today ──────────────

    public function test_a_product_with_no_recipe_still_brews_raw_material_blind(): void
    {
        $production = $this->service()->brew($this->kitchen->id, [$this->latte->id => 10], $this->barista);

        $this->assertNotNull($production->id);
        $this->assertSame(10, $production->lines->firstOrFail()->qty_brewed);
        $this->assertNull($production->lines->firstOrFail()->recipe_id);

        $this->assertSame(
            10,
            app(StockLedgerService::class)->stockFor(StockLedgerService::KITCHEN, $this->kitchen->id, $this->latte->id),
        );

        // Nothing was ever posted against a raw material, since there is no recipe.
        $this->assertFalse(
            StockLedger::query()->where('movement_type', MovementType::RECIPE_CONSUME_OUT)->exists(),
        );
    }

    // ── (b) A recipe'd product consumes the correct raw materials ───────────

    public function test_a_product_with_a_recipe_consumes_raw_materials_and_posts_recipe_consume_out(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        app(RecipeService::class)->create($this->latte->id, [
            ['raw_material_id' => $this->milk->id, 'qty_per_unit' => 150],
        ], $admin);

        $this->seedMilk(2000);

        $production = $this->service()->brew($this->kitchen->id, [$this->latte->id => 10], $this->barista);

        $this->assertNotNull($production->lines->firstOrFail()->recipe_id);
        $this->assertSame(500, $this->milkStock()); // 2000 - 150*10

        $row = StockLedger::query()
            ->where('movement_type', MovementType::RECIPE_CONSUME_OUT)
            ->where('ref_type', 'production')
            ->where('ref_id', $production->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($this->milk->id, $row->raw_material_id);
        $this->assertSame(-1500, $row->qty_delta);
    }

    // ── (c) Refused when short — full rollback ──────────────────────────────

    public function test_brewing_more_than_available_raw_material_stock_is_refused_and_posts_nothing(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        app(RecipeService::class)->create($this->latte->id, [
            ['raw_material_id' => $this->milk->id, 'qty_per_unit' => 150],
        ], $admin);

        $this->seedMilk(1000); // enough for 6 cups, not 10

        try {
            $this->service()->brew($this->kitchen->id, [$this->latte->id => 10], $this->barista);
            $this->fail('Expected a RuntimeException for insufficient raw material stock.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Susu', $e->getMessage());
        }

        $this->assertSame(0, \App\Models\Production::query()->count());
        $this->assertFalse(StockLedger::query()->where('movement_type', MovementType::PRODUCTION_IN)->exists());
        $this->assertFalse(StockLedger::query()->where('movement_type', MovementType::RECIPE_CONSUME_OUT)->exists());
        // The purchase-in seed is the only ledger row left standing.
        $this->assertSame(1000, $this->milkStock());
    }

    // ── (d) THE CRITICAL ONE: aggregate demand across products in one brew ──

    public function test_two_products_sharing_one_ingredient_are_checked_together_not_separately(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();

        // Both the latte and the cappuccino use milk.
        app(RecipeService::class)->create($this->latte->id, [
            ['raw_material_id' => $this->milk->id, 'qty_per_unit' => 100],
        ], $admin);
        app(RecipeService::class)->create($this->cappuccino->id, [
            ['raw_material_id' => $this->milk->id, 'qty_per_unit' => 100],
        ], $admin);

        // Exactly enough for one of them alone (10 cups * 100ml = 1000ml), but not both at once.
        $this->seedMilk(1000);

        try {
            $this->service()->brew($this->kitchen->id, [
                $this->latte->id => 10,
                $this->cappuccino->id => 10,
            ], $this->barista);
            $this->fail('Expected the combined demand to exceed stock.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Susu', $e->getMessage());
        }

        // Nothing posted — proving the check ran against the SUM, not each product in isolation.
        $this->assertSame(0, \App\Models\Production::query()->count());
        $this->assertSame(1000, $this->milkStock());
    }

    // ── (e) The existing brew endpoint still works ───────────────────────────

    public function test_the_showcase_brew_endpoint_still_works_end_to_end(): void
    {
        $this->seed();

        $barista = User::query()->where('phone_e164', '081100000003')->firstOrFail();
        $product = Product::query()->where('name', 'Soul Coffee')->firstOrFail();

        $response = $this->actingAs($barista, 'sanctum')
            ->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson('/api/v1/showcase/brew', [
                'lines' => [['product_id' => $product->id, 'qty' => 12]],
            ])
            ->assertSuccessful();

        $row = collect($response->json('data'))->firstWhere('product_id', $product->id);
        $this->assertNotNull($row);
        $this->assertSame(212, $row['qty']); // seeded 200 + 12, no recipe seeded for this product

        $this->assertSame(1, \App\Models\Production::query()->count());
    }
}
