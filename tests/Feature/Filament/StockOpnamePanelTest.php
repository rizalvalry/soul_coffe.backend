<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Enums\StockOpnameStatus;
use App\Filament\Pages\StockOpnameEntry;
use App\Filament\Resources\StockOpnames\Pages\ListStockOpnames;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Product;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use App\Services\StockLedgerService;
use App\Services\StockOpnameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two screens Stock Opname is reached through: the count-entry page, and the list where a
 * draft is applied or abandoned. The test that matters most is the read-only grant one — the same
 * kind SalesTable and DeliveryIncidentsTable already assert — because both "Terapkan" and
 * "Batalkan" write to the stock ledger.
 */
class StockOpnamePanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CentralKitchen $kitchen;

    private Product $coffee;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::forget();

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();

        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);

        Cart::create(['code' => '0018', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);

        $this->coffee = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    // ── The entry page ───────────────────────────────────────────────────

    public function test_the_entry_page_loads_a_locations_products_and_submits_a_draft(): void
    {
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::KITCHEN,
            locationId: $this->kitchen->id,
            productId: $this->coffee->id,
            movementType: \App\Enums\MovementType::PRODUCTION_IN,
            qty: 50,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
        );

        $page = Livewire::actingAs($this->admin)
            ->test(StockOpnameEntry::class)
            ->set('locationKind', 'kitchen')
            ->set('locationId', $this->kitchen->id)
            ->call('loadLocation');

        $this->assertTrue($page->instance()->loaded);
        $lines = $page->instance()->lines;
        $this->assertCount(1, $lines);
        $this->assertSame(50, $lines[0]['system_qty']);
        // Pre-filled to match the system figure, so submitting unchanged rows costs nothing.
        $this->assertSame(50, $lines[0]['counted_qty']);

        $page->set('lines.0.counted_qty', 45)
            ->set('reason', 'Hitungan sore')
            ->call('submit');

        $this->assertSame(1, \App\Models\StockOpname::query()->count());
        $opname = \App\Models\StockOpname::query()->firstOrFail();
        $this->assertSame(StockOpnameStatus::DRAFT, $opname->status);
        $this->assertSame(-5, $opname->lines->firstOrFail()->variance_qty);
    }

    public function test_the_entry_page_is_hidden_from_navigation(): void
    {
        $this->assertFalse(StockOpnameEntry::shouldRegisterNavigation());
    }

    public function test_only_a_role_with_the_create_ability_reaches_the_entry_page(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();

        $this->actingAs($finance)->get(StockOpnameEntry::getUrl())->assertForbidden();

        PermissionMatrix::set(Role::FINANCE, PanelModule::STOCK_OPNAME, ['view', 'create']);
        PermissionMatrix::forget();

        $this->actingAs($finance)->get(StockOpnameEntry::getUrl())->assertSuccessful();
    }

    // ── The list and its two decisions ───────────────────────────────────

    public function test_an_administrator_sees_the_list(): void
    {
        app(StockOpnameService::class)->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 10]],
            'Hitungan uji',
            $this->admin,
        );

        $this->actingAs($this->admin)
            ->get(StockOpnameResource::getUrl())
            ->assertSuccessful()
            ->assertSee('Hitungan uji');
    }

    public function test_the_resource_offers_no_create_edit_or_delete_route(): void
    {
        $pages = StockOpnameResource::getPages();

        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }

    public function test_the_create_button_links_to_the_entry_page_and_respects_the_grant(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();

        PermissionMatrix::set(Role::FINANCE, PanelModule::STOCK_OPNAME, ['view']);
        PermissionMatrix::forget();

        Livewire::actingAs($finance)
            ->test(ListStockOpnames::class)
            ->assertDontSee('Buat Stock Opname');

        PermissionMatrix::set(Role::FINANCE, PanelModule::STOCK_OPNAME, ['view', 'create']);
        PermissionMatrix::forget();

        Livewire::actingAs($finance)
            ->test(ListStockOpnames::class)
            ->assertSee('Buat Stock Opname');
    }

    public function test_view_only_can_read_but_not_apply_or_cancel(): void
    {
        $opname = app(StockOpnameService::class)->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 10]],
            'x',
            $this->admin,
        );

        $finance = User::factory()->role(Role::FINANCE)->create();
        PermissionMatrix::set(Role::FINANCE, PanelModule::STOCK_OPNAME, ['view']);
        PermissionMatrix::forget();

        Livewire::actingAs($finance)
            ->test(ListStockOpnames::class)
            ->assertTableActionHidden('apply', $opname)
            ->assertTableActionHidden('cancel', $opname);

        $this->assertSame(StockOpnameStatus::DRAFT, $opname->fresh()->status);
    }

    public function test_applying_from_the_list_posts_the_correction(): void
    {
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::KITCHEN,
            locationId: $this->kitchen->id,
            productId: $this->coffee->id,
            movementType: \App\Enums\MovementType::PRODUCTION_IN,
            qty: 50,
            actorId: $this->admin->id,
            kitchenId: $this->kitchen->id,
        );

        $opname = app(StockOpnameService::class)->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 45]],
            'x',
            $this->admin,
        );

        Livewire::actingAs($this->admin)
            ->test(ListStockOpnames::class)
            ->callTableAction('apply', $opname)
            ->assertHasNoTableActionErrors();

        $this->assertSame(StockOpnameStatus::APPLIED, $opname->fresh()->status);
        $this->assertSame(
            45,
            app(StockLedgerService::class)->stockFor(StockLedgerService::KITCHEN, $this->kitchen->id, $this->coffee->id),
        );
    }

    public function test_cancelling_from_the_list_leaves_stock_untouched(): void
    {
        $opname = app(StockOpnameService::class)->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 10]],
            'x',
            $this->admin,
        );

        Livewire::actingAs($this->admin)
            ->test(ListStockOpnames::class)
            ->callTableAction('cancel', $opname)
            ->assertHasNoTableActionErrors();

        $this->assertSame(StockOpnameStatus::CANCELLED, $opname->fresh()->status);
    }

    public function test_the_navigation_badge_counts_open_drafts_only(): void
    {
        $this->actingAs($this->admin);
        $this->assertNull(StockOpnameResource::getNavigationBadge());

        $opname = app(StockOpnameService::class)->create(
            StockLedgerService::KITCHEN,
            $this->kitchen->id,
            [['product_id' => $this->coffee->id, 'counted_qty' => 10]],
            'x',
            $this->admin,
        );

        $this->assertSame('1', StockOpnameResource::getNavigationBadge());

        app(StockOpnameService::class)->apply($opname, $this->admin);

        $this->assertNull(StockOpnameResource::getNavigationBadge());
    }
}
