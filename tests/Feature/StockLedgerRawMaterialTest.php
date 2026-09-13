<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\CentralKitchen;
use App\Models\RawMaterial;
use App\Models\User;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Raw-material stock lives in the same ledger as finished products (Phase 2), under
 * `location_type = 'raw_material_store'`. What matters here is the invariant a check constraint
 * cannot enforce on every driver: exactly one of product_id/raw_material_id per row, and that a
 * raw-material post carries a cost when one is known.
 */
class StockLedgerRawMaterialTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private RawMaterial $milk;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);

        $this->milk = RawMaterial::create([
            'code' => 'SUSU', 'name' => 'Susu UHT', 'unit' => 'ml', 'is_active' => true, 'sort_order' => 1,
        ]);

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
    }

    private function ledger(): StockLedgerService
    {
        return app(StockLedgerService::class);
    }

    public function test_a_raw_material_post_lands_under_the_raw_material_store_with_its_cost(): void
    {
        $row = $this->ledger()->post(
            locationType: StockLedgerService::RAW_MATERIAL_STORE,
            locationId: $this->kitchen->id,
            productId: null,
            movementType: MovementType::PURCHASE_IN,
            qty: 5000,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
            refType: 'purchase_order',
            refId: 1,
            rawMaterialId: $this->milk->id,
            costMinor: 250000,
        );

        $this->assertSame(StockLedgerService::RAW_MATERIAL_STORE, $row->location_type);
        $this->assertNull($row->product_id);
        $this->assertSame($this->milk->id, $row->raw_material_id);
        $this->assertSame(5000, $row->qty_delta);
        $this->assertSame(250000, $row->cost_minor);

        $this->assertSame(
            5000,
            $this->ledger()->rawMaterialStockFor(StockLedgerService::RAW_MATERIAL_STORE, $this->kitchen->id, $this->milk->id),
        );
    }

    public function test_a_row_cannot_reference_both_a_product_and_a_raw_material(): void
    {
        $product = \App\Models\Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup', 'is_sellable' => true,
            'sort_order' => 1, 'is_active' => true,
        ]);

        $this->expectException(RuntimeException::class);

        $this->ledger()->post(
            locationType: StockLedgerService::KITCHEN,
            locationId: $this->kitchen->id,
            productId: $product->id,
            movementType: MovementType::PRODUCTION_IN,
            qty: 10,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
            rawMaterialId: $this->milk->id,
        );
    }

    public function test_a_row_cannot_reference_neither_a_product_nor_a_raw_material(): void
    {
        $this->expectException(RuntimeException::class);

        $this->ledger()->post(
            locationType: StockLedgerService::RAW_MATERIAL_STORE,
            locationId: $this->kitchen->id,
            productId: null,
            movementType: MovementType::PURCHASE_IN,
            qty: 10,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
        );
    }

    public function test_recipe_consume_out_is_a_negative_movement(): void
    {
        $this->ledger()->post(
            locationType: StockLedgerService::RAW_MATERIAL_STORE,
            locationId: $this->kitchen->id,
            productId: null,
            movementType: MovementType::PURCHASE_IN,
            qty: 1000,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
            rawMaterialId: $this->milk->id,
        );

        $this->ledger()->post(
            locationType: StockLedgerService::RAW_MATERIAL_STORE,
            locationId: $this->kitchen->id,
            productId: null,
            movementType: MovementType::RECIPE_CONSUME_OUT,
            qty: 200,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
            rawMaterialId: $this->milk->id,
        );

        $this->assertSame(
            800,
            $this->ledger()->rawMaterialStockFor(StockLedgerService::RAW_MATERIAL_STORE, $this->kitchen->id, $this->milk->id),
        );
    }
}
