<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Resources\DirectSales\Pages\ListDirectSales;
use App\Filament\Resources\DirectSales\Schemas\DirectSaleForm;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\DirectSale;
use App\Models\DirectSaleLine;
use App\Models\Location;
use App\Models\Product;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The panel screen for Penjualan Langsung Kantor, mirroring
 * ActivityAndSalesPanelTest's void-related coverage for the gerobak Sale panel.
 */
class DirectSalePanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Cart $cart;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::forget();

        $kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);

        $this->cart = Cart::create(['code' => '0018', 'status' => 'active', 'kitchen_id' => $kitchen->id]);

        $this->product = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
    }

    private function directSale(int $qty = 3): DirectSale
    {
        $sale = DirectSale::query()->create([
            'uuid' => (string) Str::uuid(),
            'cart_id' => $this->cart->id,
            'recorded_by' => $this->admin->id,
            'occurred_at' => now(),
            'total_qty' => $qty,
            'total_amount_minor' => $qty * 20000,
            'payment_method' => 'cash',
        ]);

        DirectSaleLine::query()->create([
            'direct_sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'qty' => $qty,
            'unit_price_minor' => 20000,
            'subtotal_minor' => $qty * 20000,
        ]);

        return $sale;
    }

    public function test_a_view_only_role_cannot_see_or_use_the_void_action(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $sale = $this->directSale();

        PermissionMatrix::set(Role::FINANCE, PanelModule::DIRECT_SALES, ['view']);
        PermissionMatrix::forget();

        Livewire::actingAs($finance)
            ->test(ListDirectSales::class)
            ->assertTableActionHidden('void', $sale);
    }

    public function test_a_role_with_the_edit_ability_can_void(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();
        $sale = $this->directSale();

        PermissionMatrix::set(Role::FINANCE, PanelModule::DIRECT_SALES, ['view', 'edit']);
        PermissionMatrix::forget();

        Livewire::actingAs($finance)
            ->test(ListDirectSales::class)
            ->assertTableActionVisible('void', $sale)
            ->callTableAction('void', $sale, ['reason' => 'Salah input'])
            ->assertHasNoTableActionErrors();

        $this->assertTrue($sale->fresh()->isVoided());
    }

    public function test_voiding_requires_a_reason(): void
    {
        $sale = $this->directSale();

        Livewire::actingAs($this->admin)
            ->test(ListDirectSales::class)
            ->callTableAction('void', $sale, ['reason' => ''])
            ->assertHasTableActionErrors(['reason']);

        $this->assertFalse($sale->fresh()->isVoided());
    }

    public function test_an_already_voided_direct_sale_has_no_void_action(): void
    {
        $sale = $this->directSale();

        Livewire::actingAs($this->admin)
            ->test(ListDirectSales::class)
            ->callTableAction('void', $sale, ['reason' => 'Salah input'])
            ->assertHasNoTableActionErrors();

        Livewire::actingAs($this->admin)
            ->test(ListDirectSales::class)
            ->assertTableActionHidden('void', $sale->fresh());
    }

    public function test_the_create_forms_cart_dropdown_labels_an_assigned_cart(): void
    {
        $location = Location::create(['name' => 'Pulomas', 'lat' => -6.18, 'lng' => 106.88]);
        $rider = User::factory()->role(Role::RIDER)->create(['name' => 'Mufit']);

        StaffAssignment::create([
            'user_id' => $rider->id,
            'cart_id' => $this->cart->id,
            'location_id' => $location->id,
            'operating_date' => Carbon::today()->toDateString(),
            'assigned_by' => $this->admin->id,
            'kitchen_id' => $this->cart->kitchen_id,
        ]);

        $options = DirectSaleForm::cartOptions();

        $this->assertSame('0018 — bertugas hari ini (Mufit)', $options[$this->cart->id]);
    }

    public function test_the_create_forms_cart_dropdown_labels_an_unassigned_cart(): void
    {
        $options = DirectSaleForm::cartOptions();

        $this->assertSame('0018 — tidak ditugaskan hari ini', $options[$this->cart->id]);
    }

    public function test_a_role_without_the_module_cannot_open_the_list(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();

        $this->actingAs($finance)
            ->get(\App\Filament\Resources\DirectSales\DirectSaleResource::getUrl())
            ->assertForbidden();

        PermissionMatrix::set(Role::FINANCE, PanelModule::DIRECT_SALES, ['view']);
        PermissionMatrix::forget();

        $this->actingAs($finance)
            ->get(\App\Filament\Resources\DirectSales\DirectSaleResource::getUrl())
            ->assertSuccessful();
    }
}
