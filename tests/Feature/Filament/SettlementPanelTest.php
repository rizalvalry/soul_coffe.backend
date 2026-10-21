<?php

namespace Tests\Feature\Filament;

use App\Enums\MovementType;
use App\Enums\PanelModule;
use App\Enums\Role;
use App\Exports\SettlementQueueExport;
use App\Filament\Resources\Settlements\Pages\ListSettlements;
use App\Filament\Resources\Settlements\SettlementResource;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Models\Product;
use App\Models\Settlement;
use App\Models\SettlementLine;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\StockLedgerService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
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
        config(['soul.closing_started_on' => today()->addDay()->toDateString()]);

        PermissionMatrix::forget();

        $kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);

        $cart = Cart::create(['code' => '0018', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $location = Location::create(['name' => 'Area Uji', 'lat' => -6.18, 'lng' => 106.88]);

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $staff = User::factory()->role(Role::STAFF)->create(['name' => 'Rider Uji Satu']);
        StaffAssignment::create([
            'source' => 'hand_to_cart',
            'user_id' => $staff->id, 'cart_id' => $cart->id,
            'location_id' => $location->id,
            'operating_date' => Carbon::today()->toDateString(),
            'assigned_by' => $this->admin->id, 'kitchen_id' => $kitchen->id,
        ]);

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
            ->assertSee('Coffee Bike')
            ->assertSee('0018')
            ->assertSee('Rider Uji Satu')
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
        PermissionMatrix::set(Role::FINANCE, PanelModule::SETTLEMENTS, []);

        $this->actingAs($finance)->get(SettlementResource::getUrl())->assertForbidden();

        PermissionMatrix::set(Role::FINANCE, PanelModule::SETTLEMENTS, ['view']);
        PermissionMatrix::forget();

        $this->actingAs($finance)->get(SettlementResource::getUrl())->assertSuccessful();

        $rider = User::factory()->role(Role::RIDER)->create();
        $this->actingAs($rider)->get(SettlementResource::getUrl())->assertForbidden();
    }

    public function test_management_access_is_independent_of_finance_template(): void
    {
        $management = User::factory()->role(Role::MANAGEMENT)->create();
        PermissionMatrix::set(Role::MANAGEMENT, PanelModule::SETTLEMENTS, []);

        $this->actingAs($management)->get(SettlementResource::getUrl())->assertForbidden();

        PermissionMatrix::set(Role::MANAGEMENT, PanelModule::SETTLEMENTS, ['view']);

        $this->actingAs($management)
            ->get(SettlementResource::getUrl())
            ->assertSuccessful()
            ->assertSee('0018');

        PermissionMatrix::set(Role::FINANCE, PanelModule::SETTLEMENTS, []);

        $this->actingAs($management)->get(SettlementResource::getUrl())->assertSuccessful();
    }

    public function test_the_module_exposes_record_and_approve_permissions(): void
    {
        $this->assertFalse(PanelModule::SETTLEMENTS->isReadOnly());
    }

    public function test_authorized_finance_and_management_can_use_the_cms_actions_and_export(): void
    {
        $this->seed(RolePermissionSeeder::class);
        foreach ([Role::FINANCE, Role::MANAGEMENT] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->get(SettlementResource::getUrl())->assertOk()->assertSee('0018');
            Livewire::test(ListSettlements::class)->assertActionVisible('record')
                ->callAction('exportExcel')
                ->assertFileDownloaded('setoran_'.today()->toDateString().'.xlsx');
        }
    }

    public function test_field_roles_cannot_open_or_write_the_cms_settlement_page(): void
    {
        foreach ([Role::STAFF, Role::RIDER, Role::BARISTA] as $role) {
            $user = User::factory()->role($role)->create();
            $this->actingAs($user)->get(SettlementResource::getUrl())->assertForbidden();
        }
    }

    public function test_cms_records_and_approves_through_service(): void
    {
        $this->actingAs($this->admin);
        $this->settlement->delete();
        Livewire::test(ListSettlements::class)
            ->callAction('record', [
                'cart_id' => $this->settlement->cart_id,
                'cash' => 0, 'qris' => 0, 'transfer' => 0,
            ])->assertHasNoErrors();
        $record = Settlement::query()->sole();
        $this->assertSame('SUBMITTED', $record->status);
        Livewire::test(ListSettlements::class)->callTableAction('approve', $record)->assertHasNoErrors();
        $this->assertSame('RECONCILED', $record->fresh()->status);
    }

    public function test_cms_approval_requires_disposition_of_live_stock(): void
    {
        $this->settlement->update(['status' => 'SUBMITTED', 'reconciled_at' => null, 'reconciled_by' => null, 'expected_cogs_minor' => 0]);
        $line = $this->settlement->lines()->firstOrFail();
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::CART, locationId: $this->settlement->cart_id,
            productId: $line->product_id, movementType: MovementType::ALLOCATION_IN,
            qty: 2, actorId: $this->admin->id, kitchenId: $this->settlement->cart->kitchen_id,
        );
        $this->actingAs($this->admin);
        Livewire::test(ListSettlements::class)->callTableAction('approve', $this->settlement, ['lines' => []]);
        $this->assertSame('SUBMITTED', $this->settlement->fresh()->status);
        Livewire::test(ListSettlements::class)->callTableAction('approve', $this->settlement, [
            'lines' => [['product_id' => $line->product_id, 'qty_returned' => 2, 'qty_rejected' => 0]],
        ])->assertHasNoErrors();
        $this->assertSame('RECONCILED', $this->settlement->fresh()->status);
        $this->assertSame(0, app(StockLedgerService::class)->stockFor(StockLedgerService::CART, $this->settlement->cart_id, $line->product_id));
    }

    public function test_legacy_expectations_are_unknown_and_export_escapes_formulas(): void
    {
        $this->settlement->update(['expected_stale' => true, 'stale_delta_minor' => -520000]);
        $this->actingAs($this->admin)->get(SettlementResource::getUrl())
            ->assertOk()->assertSee('Tidak diketahui')->assertSee('Terhadap snapshot Rp 520.000')
            ->assertSee('perubahan live Rp -520.000');
        $this->settlement->staff->update(['name' => '=HYPERLINK("https://example.invalid")']);
        $row = (new SettlementQueueExport(today()))->collection()->first();
        $this->assertStringStartsWith("'=HYPERLINK", $row[2]);
    }

    public function test_existing_finance_view_grant_is_upgraded_for_cms_actions(): void
    {
        PermissionMatrix::set(Role::FINANCE, PanelModule::SETTLEMENTS, ['view']);
        $migration = require database_path('migrations/2026_10_16_000300_grant_finance_settlement_actions.php');
        $migration->upgradeExistingGrant();
        PermissionMatrix::forget();
        $this->assertTrue(PermissionMatrix::can(User::factory()->role(Role::FINANCE)->create(), PanelModule::SETTLEMENTS, 'create'));
        $this->assertTrue(PermissionMatrix::can(User::factory()->role(Role::MANAGEMENT)->create(), PanelModule::SETTLEMENTS, 'edit'));
    }

    public function test_cms_can_dispose_a_product_added_after_deposit_recording(): void
    {
        $this->settlement->update(['status' => 'SUBMITTED', 'reconciled_at' => null, 'reconciled_by' => null, 'expected_cogs_minor' => 0]);
        $newProduct = Product::create([
            'code' => 'LATE', 'name' => 'Produk Baru', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 2, 'is_active' => true,
        ]);
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::CART, locationId: $this->settlement->cart_id,
            productId: $newProduct->id, movementType: MovementType::ALLOCATION_IN,
            qty: 1, actorId: $this->admin->id, kitchenId: $this->settlement->cart->kitchen_id,
        );
        $this->actingAs($this->admin);
        Livewire::test(ListSettlements::class)->callTableAction('approve', $this->settlement, [
            'lines' => [['product_id' => $newProduct->id, 'qty_returned' => 1, 'qty_rejected' => 0]],
        ])->assertHasNoErrors();
        $this->assertSame('RECONCILED', $this->settlement->fresh()->status);
        $this->assertDatabaseHas('settlement_lines', [
            'settlement_id' => $this->settlement->id,
            'product_id' => $newProduct->id,
            'qty_issued' => 1,
            'qty_remaining' => 1,
        ]);
    }
}
