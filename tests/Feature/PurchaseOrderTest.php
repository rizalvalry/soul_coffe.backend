<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\StockLedger;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderService;
use App\Services\RecipeService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Supplier $supplier;

    private RawMaterial $milk;

    private User $admin;

    private User $barista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1', 'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);
        $this->supplier = Supplier::create(['code' => 'SUP-01', 'name' => 'Pemasok Susu', 'is_active' => true]);
        $this->milk = RawMaterial::create(['code' => 'SUSU', 'name' => 'Susu', 'unit' => 'ml', 'is_active' => true, 'sort_order' => 1]);

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->barista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
    }

    private function service(): PurchaseOrderService
    {
        return app(PurchaseOrderService::class);
    }

    private function rawMaterialStock(): int
    {
        return app(StockLedgerService::class)
            ->rawMaterialStockFor(StockLedgerService::RAW_MATERIAL_STORE, $this->kitchen->id, $this->milk->id);
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    public function test_full_lifecycle_draft_to_ordered_to_received_posts_correct_ledger_rows(): void
    {
        $po = $this->service()->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 10000, 'unit_cost_minor' => 50],
        ], $this->admin);

        $this->assertSame(PurchaseOrderStatus::DRAFT, $po->status);

        $ordered = $this->service()->markOrdered($po, $this->admin);
        $this->assertSame(PurchaseOrderStatus::ORDERED, $ordered->status);
        $this->assertNotNull($ordered->ordered_at);

        $received = $this->service()->receive($ordered, [$this->milk->id => 10000], $this->admin);

        $this->assertSame(PurchaseOrderStatus::RECEIVED, $received->status);
        $this->assertSame($this->admin->id, $received->received_by);
        $this->assertNotNull($received->received_at);

        $this->assertSame(10000, $this->rawMaterialStock());

        $ledgerRow = StockLedger::query()
            ->where('movement_type', MovementType::PURCHASE_IN)
            ->where('ref_type', 'purchase_order')
            ->where('ref_id', $po->id)
            ->first();

        $this->assertNotNull($ledgerRow);
        $this->assertSame($this->milk->id, $ledgerRow->raw_material_id);
        $this->assertNull($ledgerRow->product_id);
        $this->assertSame(10000, $ledgerRow->qty_delta);
        $this->assertSame(500000, $ledgerRow->cost_minor); // 10000 * 50
    }

    public function test_a_partial_receive_only_credits_what_arrived(): void
    {
        $po = $this->service()->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 1000, 'unit_cost_minor' => 100],
        ], $this->admin);
        $ordered = $this->service()->markOrdered($po, $this->admin);

        $received = $this->service()->receive($ordered, [$this->milk->id => 700], $this->admin);

        $this->assertSame(700, $this->rawMaterialStock());
        $this->assertSame(700, $received->lines->firstOrFail()->qty_received);

        $ledgerRow = StockLedger::query()
            ->where('movement_type', MovementType::PURCHASE_IN)
            ->where('ref_id', $po->id)
            ->firstOrFail();
        $this->assertSame(70000, $ledgerRow->cost_minor); // 700 * 100
    }

    public function test_a_line_left_unspecified_defaults_to_its_full_ordered_quantity(): void
    {
        $po = $this->service()->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 500, 'unit_cost_minor' => 10],
        ], $this->admin);
        $ordered = $this->service()->markOrdered($po, $this->admin);

        $received = $this->service()->receive($ordered, [], $this->admin);

        $this->assertSame(500, $received->lines->firstOrFail()->qty_received);
        $this->assertSame(500, $this->rawMaterialStock());
    }

    public function test_receiving_before_markordered_is_refused(): void
    {
        $po = $this->service()->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 500, 'unit_cost_minor' => 10],
        ], $this->admin);

        $this->expectException(RuntimeException::class);
        $this->service()->receive($po, [], $this->admin);
    }

    public function test_receiving_twice_is_refused(): void
    {
        $po = $this->service()->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 500, 'unit_cost_minor' => 10],
        ], $this->admin);
        $ordered = $this->service()->markOrdered($po, $this->admin);
        $this->service()->receive($ordered, [], $this->admin);

        $this->expectException(RuntimeException::class);
        $this->service()->receive($ordered->fresh(), [], $this->admin);
    }

    public function test_cancel_refuses_once_received(): void
    {
        $po = $this->service()->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 500, 'unit_cost_minor' => 10],
        ], $this->admin);
        $ordered = $this->service()->markOrdered($po, $this->admin);
        $received = $this->service()->receive($ordered, [], $this->admin);

        $this->expectException(RuntimeException::class);
        $this->service()->cancel($received, $this->admin);
    }

    public function test_cancel_is_allowed_from_draft_or_ordered(): void
    {
        $po = $this->service()->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 500, 'unit_cost_minor' => 10],
        ], $this->admin);

        $cancelled = $this->service()->cancel($po, $this->admin);
        $this->assertSame(PurchaseOrderStatus::CANCELLED, $cancelled->status);
        $this->assertSame(0, $this->rawMaterialStock());
    }

    // ── Roles ────────────────────────────────────────────────────────────────

    public function test_only_administrator_or_finance_may_create(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service()->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 500, 'unit_cost_minor' => 10],
        ], $this->barista);
    }

    public function test_only_administrator_or_finance_may_receive(): void
    {
        $po = $this->service()->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 500, 'unit_cost_minor' => 10],
        ], $this->admin);
        $ordered = $this->service()->markOrdered($po, $this->admin);

        $this->expectException(RuntimeException::class);
        $this->service()->receive($ordered, [], $this->barista);
    }

    // ── Recipe cost refresh ─────────────────────────────────────────────────

    public function test_receiving_refreshes_computed_cost_on_recipes_using_that_raw_material(): void
    {
        $product = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup', 'is_sellable' => true,
            'sort_order' => 1, 'is_active' => true,
        ]);
        $recipe = app(RecipeService::class)->create($product->id, [
            ['raw_material_id' => $this->milk->id, 'qty_per_unit' => 150],
        ], $this->admin);

        $this->assertNull($recipe->computed_cost_minor);

        $po = $this->service()->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 1000, 'unit_cost_minor' => 50],
        ], $this->admin);
        $ordered = $this->service()->markOrdered($po, $this->admin);
        $this->service()->receive($ordered, [], $this->admin);

        // 150 * 50 = 7500
        $this->assertSame(7500, $recipe->fresh()->computed_cost_minor);
    }
}
