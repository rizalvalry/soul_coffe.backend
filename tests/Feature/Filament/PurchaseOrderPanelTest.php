<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\CashAccount;
use App\Models\CentralKitchen;
use App\Models\JournalEntry;
use App\Models\PurchaseOrderPayment;
use App\Models\RawMaterial;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
                'payment_method' => 'CREDIT',
                'lines' => [
                    ['raw_material_id' => $this->milk->id, 'qty_received' => 1000],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(PurchaseOrderStatus::RECEIVED, $po->fresh()->status);
    }

    public function test_wrong_role_gets_403_on_purchase_order_page(): void
    {
        $po = app(PurchaseOrderService::class)->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 10, 'unit_cost_minor' => 50],
        ], $this->admin);
        $po = app(PurchaseOrderService::class)->markOrdered($po, $this->admin);
        $po = app(PurchaseOrderService::class)->receive($po, [], $this->admin);

        $this->actingAs(User::factory()->role(Role::BARISTA)->create())
            ->get(PurchaseOrderResource::getUrl())->assertForbidden();

    }

    public function test_view_only_finance_cannot_forge_debt_payment_action(): void
    {
        $service = app(PurchaseOrderService::class);
        $po = $service->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 10, 'unit_cost_minor' => 50],
        ], $this->admin);
        $po = $service->receive($service->markOrdered($po, $this->admin), [], $this->admin);
        $cash = CashAccount::query()->whereHas('account', fn ($query) => $query->where('code', '1-1100'))->firstOrFail();
        $finance = User::factory()->role(Role::FINANCE)->create();
        PermissionMatrix::set(Role::FINANCE, PanelModule::PURCHASE_ORDERS, ['view']);

        Livewire::actingAs($finance)->test(ListPurchaseOrders::class)
            ->mountTableAction('payDebt', $po)
            ->assertSet('mountedActions', []);
        $this->assertSame(0, PurchaseOrderPayment::count());

        try {
            $service->payDebt($po, $finance, [
                'uuid' => '32954515-8dca-41e2-b7c6-41c5df3b97ba',
                'paid_on' => today()->toDateString(), 'cash_account_id' => $cash->id, 'amount_minor' => 100,
            ]);
            $this->fail('View-only Finance payment accepted by service');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(0, PurchaseOrderPayment::count());
        }
    }

    public function test_finance_can_pay_partial_debt_from_cms(): void
    {
        $po = app(PurchaseOrderService::class)->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 10, 'unit_cost_minor' => 50],
        ], $this->admin);
        $po = app(PurchaseOrderService::class)->markOrdered($po, $this->admin);
        $po = app(PurchaseOrderService::class)->receive($po, [], $this->admin);
        $cash = CashAccount::query()->whereHas('account', fn ($query) => $query->where('code', '1-1100'))->firstOrFail();

        Livewire::actingAs($this->admin)->test(ListPurchaseOrders::class)
            ->filterTable('open', false)
            ->callTableAction('payDebt', $po, data: [
                'uuid' => 'a5f23983-e541-4090-8941-95f3587d3a9a',
                'paid_on' => today()->toDateString(), 'cash_account_id' => $cash->id, 'amount_minor' => 200,
            ])->assertHasNoTableActionErrors();

        $this->assertSame(1, PurchaseOrderPayment::query()->where('purchase_order_id', $po->id)->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'purchase_order_payment')->count());
        $this->assertSame(300, $po->unpaidMinor());
    }

    public function test_default_filter_shows_received_orders_with_debt_but_hides_paid_orders(): void
    {
        $service = app(PurchaseOrderService::class);
        $makeReceived = function () use ($service) {
            $po = $service->create($this->supplier->id, $this->kitchen->id, [
                ['raw_material_id' => $this->milk->id, 'qty_ordered' => 10, 'unit_cost_minor' => 50],
            ], $this->admin);

            return $service->receive($service->markOrdered($po, $this->admin), [], $this->admin);
        };
        $owing = $makeReceived();
        $paid = $makeReceived();
        $cash = CashAccount::query()->whereHas('account', fn ($query) => $query->where('code', '1-1100'))->firstOrFail();
        $service->payDebt($paid, $this->admin, [
            'uuid' => 'c41cf0a2-0d50-4a12-8a8c-6b6746fa978a', 'paid_on' => today()->toDateString(),
            'cash_account_id' => $cash->id, 'amount_minor' => 500,
        ]);

        Livewire::actingAs($this->admin)->test(ListPurchaseOrders::class)
            ->assertCanSeeTableRecords([$owing])
            ->assertCanNotSeeTableRecords([$paid]);
    }

    public function test_legacy_received_order_has_cutover_badge_and_no_debt_payment_action(): void
    {
        $service = app(PurchaseOrderService::class);
        $po = $service->create($this->supplier->id, $this->kitchen->id, [
            ['raw_material_id' => $this->milk->id, 'qty_ordered' => 10, 'unit_cost_minor' => 50],
        ], $this->admin);
        $po = $service->markOrdered($po, $this->admin);
        $po->update(['status' => 'RECEIVED', 'received_at' => '2026-09-01 10:00:00', 'received_by' => $this->admin->id]);
        $po->lines()->firstOrFail()->update(['qty_received' => 10]);

        Livewire::actingAs($this->admin)->test(ListPurchaseOrders::class)
            ->filterTable('open', false)
            ->assertCanSeeTableRecords([$po])
            ->assertSee('Sebelum cutover — tanpa jurnal')
            ->assertTableActionHidden('payDebt', $po);
        $this->assertSame(0, $po->unpaidMinor());
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
