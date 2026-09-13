<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Models\CentralKitchen;
use App\Models\RawMaterial;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two decisions with real consequences — "Terima" posts to the stock ledger, "Batalkan"
 * abandons a draft/ordered PO — must be closed to a view-only grant, the same kind
 * StockOpnamePanelTest already asserts for its own two decision buttons.
 */
class PurchaseOrderPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CentralKitchen $kitchen;

    private Supplier $supplier;

    private RawMaterial $milk;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::forget();

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1', 'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);
        $this->supplier = Supplier::create(['code' => 'SUP-01', 'name' => 'Pemasok Susu', 'is_active' => true]);
        $this->milk = RawMaterial::create(['code' => 'SUSU', 'name' => 'Susu', 'unit' => 'ml', 'is_active' => true, 'sort_order' => 1]);
    }

    public function test_creating_a_purchase_order_from_the_panel_posts_through_the_service(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CreatePurchaseOrder::class)
            ->fillForm([
                'supplier_id' => $this->supplier->id,
                'kitchen_id' => $this->kitchen->id,
                'lines' => [
                    ['raw_material_id' => $this->milk->id, 'qty_ordered' => 1000, 'unit_cost_minor' => 50],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('purchase_orders', ['supplier_id' => $this->supplier->id, 'status' => 'DRAFT']);
    }

    public function test_view_only_can_read_but_not_receive_or_cancel(): void
    {
        $po = app(PurchaseOrderService::class)->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 1000, 'unit_cost_minor' => 50],
        ], $this->admin);
        $po = app(PurchaseOrderService::class)->markOrdered($po, $this->admin);

        $finance = User::factory()->role(Role::FINANCE)->create();
        PermissionMatrix::set(Role::FINANCE, PanelModule::PURCHASE_ORDERS, ['view']);
        PermissionMatrix::forget();

        Livewire::actingAs($finance)
            ->test(ListPurchaseOrders::class)
            ->assertTableActionHidden('receive', $po)
            ->assertTableActionHidden('cancel', $po)
            ->assertTableActionHidden('markOrdered', $po);

        $this->assertSame(PurchaseOrderStatus::ORDERED, $po->fresh()->status);
    }

    public function test_receiving_from_the_list_posts_the_correction(): void
    {
        $po = app(PurchaseOrderService::class)->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 1000, 'unit_cost_minor' => 50],
        ], $this->admin);
        $po = app(PurchaseOrderService::class)->markOrdered($po, $this->admin);

        Livewire::actingAs($this->admin)
            ->test(ListPurchaseOrders::class)
            ->callTableAction('receive', $po, data: [
                'lines' => [
                    ['raw_material_id' => $this->milk->id, 'qty_received' => 1000],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(PurchaseOrderStatus::RECEIVED, $po->fresh()->status);
    }

    public function test_cancelling_from_the_list_leaves_stock_untouched(): void
    {
        $po = app(PurchaseOrderService::class)->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 1000, 'unit_cost_minor' => 50],
        ], $this->admin);

        Livewire::actingAs($this->admin)
            ->test(ListPurchaseOrders::class)
            ->callTableAction('cancel', $po)
            ->assertHasNoTableActionErrors();

        $this->assertSame(PurchaseOrderStatus::CANCELLED, $po->fresh()->status);
    }
}
