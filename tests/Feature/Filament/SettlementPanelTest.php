<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Resources\Settlements\SettlementResource;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * "Setoran Harian" in the panel: the report side of the deposit taken on the phone.
 *
 * The assertion that matters is that the panel cannot write one. A deposit is money counted with
 * a person standing there and cups already moved through the append-only ledger; editing it here
 * afterwards, with nobody present to disagree, is not a correction but a rewrite.
 */
class SettlementPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Settlement $settlement;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::forget();

        $kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);

        $cart = Cart::create(['code' => '0018', 'status' => 'active', 'kitchen_id' => $kitchen->id]);

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $staff = User::factory()->role(Role::STAFF)->create(['name' => 'Mufit']);

        $product = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);

        $this->settlement = Settlement::query()->create([
            'operating_date' => Carbon::today()->toDateString(),
            'cart_id' => $cart->id,
            'staff_id' => $staff->id,
            'status' => 'RECONCILED',
            'cash_minor' => 300000,
            'qris_minor' => 200000,
            'transfer_minor' => 0,
            'declared_total_minor' => 500000,
            'expected_total_minor' => 520000,
            'variance_minor' => -20000,
            'variance_reason' => 'Uang kembalian kurang, staff ganti besok.',
            'reconciled_by' => $this->admin->id,
            'reconciled_at' => now(),
        ]);

        SettlementLine::query()->create([
            'settlement_id' => $this->settlement->id,
            'product_id' => $product->id,
            'qty_issued' => 40,
            'qty_sold' => 26,
            'qty_remaining' => 10,
            'qty_wasted' => 4,
            'variance_qty' => 0,
        ]);
    }

    public function test_an_administrator_reads_the_days_deposits(): void
    {
        $this->actingAs($this->admin)
            ->get(SettlementResource::getUrl())
            ->assertSuccessful()
            ->assertSee('0018')
            ->assertSee('Mufit')
            // The gap in words, and the reason somebody typed for it, side by side.
            ->assertSee('Kurang Rp 20.000')
            ->assertSee('Uang kembalian kurang');
    }

    public function test_the_panel_cannot_create_edit_or_delete_a_deposit(): void
    {
        $this->actingAs($this->admin);

        $this->assertFalse(SettlementResource::canCreate());
        $this->assertFalse(SettlementResource::canEdit($this->settlement));
        $this->assertFalse(SettlementResource::canDelete($this->settlement));

        $pages = SettlementResource::getPages();
        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }

    public function test_finance_reads_it_through_the_matrix_and_a_rider_does_not(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();

        $this->actingAs($finance)->get(SettlementResource::getUrl())->assertForbidden();

        PermissionMatrix::set(Role::FINANCE, PanelModule::SETTLEMENTS, ['view']);
        PermissionMatrix::forget();

        $this->actingAs($finance)->get(SettlementResource::getUrl())->assertSuccessful();

        $rider = User::factory()->role(Role::RIDER)->create();
        $this->actingAs($rider)->get(SettlementResource::getUrl())->assertForbidden();
    }

    /** Read-only by nature, so the matrix editor must not offer create/edit/delete for it. */
    public function test_the_module_is_marked_read_only(): void
    {
        $this->assertTrue(PanelModule::SETTLEMENTS->isReadOnly());
    }
}
