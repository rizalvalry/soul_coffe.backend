<?php

namespace Tests\Feature\Filament;

use App\Enums\PanelModule;
use App\Enums\Role;
use App\Filament\Pages\StaffActivity;
use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Widgets\CartSalesTodayWidget;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StaffAssignment;
use App\Models\StaffLocationPing;
use App\Models\User;
use App\Services\Access\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two new panel screens: "Penjualan Gerobak" and "Aktivitas Staff".
 *
 * Two things here are worth more than the render assertions. First, that the sales list refuses
 * to create, edit or delete — a panel that can rewrite a transaction would put a second hand on
 * the revenue figures with no record of it. Second, that both screens are governed by the access
 * matrix, because a GPS trail visible to anyone who can log in is a different product from the
 * one that was asked for.
 */
class ActivityAndSalesPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $staff;

    private Cart $cart;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionMatrix::forget();

        $kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);

        $this->cart = Cart::create(['code' => '0018', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $this->location = Location::create(['name' => 'Pulomas', 'lat' => -6.18, 'lng' => 106.88]);

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->staff = User::factory()->role(Role::STAFF)->create(['name' => 'Mufit']);

        StaffAssignment::create([
            'user_id' => $this->staff->id,
            'cart_id' => $this->cart->id,
            'location_id' => $this->location->id,
            'operating_date' => Carbon::today()->toDateString(),
            'assigned_by' => $this->admin->id,
            'kitchen_id' => $kitchen->id,
        ]);
    }

    private function sale(int $hour, int $qty, bool $suspect = false): Sale
    {
        $product = Product::query()->firstOr(fn (): Product => Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]));

        $sale = Sale::query()->create([
            'uuid' => (string) Str::uuid(),
            'operating_date' => Carbon::today()->toDateString(),
            'cart_id' => $this->cart->id,
            'staff_id' => $this->staff->id,
            'location_id' => $this->location->id,
            'occurred_at' => Carbon::today()->setTime($hour, 5),
            'total_qty' => $qty,
            'total_amount_minor' => $qty * 20000,
            'payment_method' => 'cash',
            'is_suspect' => $suspect,
            'suspect_reason' => $suspect ? $qty.' cups dalam satu transaksi (batas 15).' : null,
        ]);

        SaleLine::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $product->id,
            'qty' => $qty,
            'unit_price_minor' => 20000,
            'subtotal_minor' => $qty * 20000,
        ]);

        return $sale;
    }

    private function ping(float $lat, float $lng, string $source = 'ping', ?Carbon $at = null): StaffLocationPing
    {
        $at ??= now();

        return StaffLocationPing::query()->create([
            'user_id' => $this->staff->id,
            'operating_date' => $at->toDateString(),
            'recorded_at' => $at,
            'lat' => $lat,
            'lng' => $lng,
            'accuracy_m' => 12,
            'battery_pct' => 77,
            'source' => $source,
            'cart_id' => $this->cart->id,
            'location_id' => $this->location->id,
        ]);
    }

    // ── Penjualan Gerobak ────────────────────────────────────────────────

    public function test_an_administrator_sees_todays_transactions(): void
    {
        $this->sale(9, 6);

        $this->actingAs($this->admin)
            ->get(SaleResource::getUrl())
            ->assertSuccessful()
            ->assertSee('0018')
            ->assertSee('Mufit');
    }

    public function test_the_sales_list_cannot_create_edit_or_delete(): void
    {
        $sale = $this->sale(9, 6);

        $this->actingAs($this->admin);

        $this->assertFalse(SaleResource::canCreate());
        $this->assertFalse(SaleResource::canEdit($sale));
        $this->assertFalse(SaleResource::canDelete($sale));

        // And there is no route to reach for either, so this is structural rather than a matter
        // of which buttons are on screen.
        $this->assertArrayNotHasKey('create', SaleResource::getPages());
        $this->assertArrayNotHasKey('edit', SaleResource::getPages());
    }

    public function test_the_flagged_filter_shows_only_flagged_transactions(): void
    {
        $normal = $this->sale(9, 5);
        $flagged = $this->sale(11, 20, suspect: true);

        Livewire::actingAs($this->admin)
            ->test(ListSales::class)
            ->filterTable('perlu_ditinjau')
            ->assertCanSeeTableRecords([$flagged])
            ->assertCanNotSeeTableRecords([$normal]);
    }

    public function test_the_menu_badge_counts_todays_flags_only(): void
    {
        $this->assertNull(SaleResource::getNavigationBadge());

        $this->sale(11, 20, suspect: true);

        $this->assertSame('1', SaleResource::getNavigationBadge());
    }

    public function test_a_role_without_the_sales_module_cannot_see_the_list(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();

        $this->actingAs($finance)->get(SaleResource::getUrl())->assertForbidden();

        PermissionMatrix::set(Role::FINANCE, PanelModule::SALES, ['view']);
        PermissionMatrix::forget();

        $this->actingAs($finance)->get(SaleResource::getUrl())->assertSuccessful();
    }

    // ── Aktivitas Staff ──────────────────────────────────────────────────

    public function test_the_activity_page_renders_with_no_data_at_all(): void
    {
        $this->actingAs($this->admin)->get(StaffActivity::getUrl())->assertSuccessful();
    }

    public function test_the_activity_page_shows_the_area_hour_grid_and_the_cart_list(): void
    {
        $this->sale(9, 10);
        $this->ping(-6.1751, 106.865);

        Livewire::actingAs($this->admin)
            ->test(StaffActivity::class)
            ->assertSuccessful()
            ->assertSee('Kapan area mana yang ramai')
            ->assertSee('Pulomas')
            ->assertSee('Mufit')
            ->assertSee('0018');
    }

    public function test_the_map_payload_carries_the_live_position_and_the_days_takings(): void
    {
        $this->sale(9, 10);
        $this->ping(-6.1751, 106.865);

        $payload = Livewire::actingAs($this->admin)
            ->test(StaffActivity::class)
            ->instance()
            ->mapPayload();

        $this->assertCount(1, $payload['staff']);
        $this->assertSame('Mufit', $payload['staff'][0]['name']);
        $this->assertSame('0018', $payload['staff'][0]['cart_code']);
        $this->assertTrue($payload['staff'][0]['is_live']);
        $this->assertSame(10, $payload['staff'][0]['cups']);
        // No trail until somebody is selected — the map opens on everyone.
        $this->assertSame([], $payload['trail']);
    }

    public function test_a_staff_member_with_no_position_is_left_off_the_map_but_stays_on_the_list(): void
    {
        $page = Livewire::actingAs($this->admin)->test(StaffActivity::class);

        $this->assertSame([], $page->instance()->mapPayload()['staff']);
        // Still listed, because "no signal since this morning" is exactly what a supervisor
        // opens this screen to find out.
        $this->assertCount(1, $page->instance()->board());
        $page->assertSee('tidak ada sinyal');
    }

    public function test_selecting_a_staff_member_draws_their_trail_and_a_second_click_clears_it(): void
    {
        $this->ping(-6.1751, 106.865);
        $this->ping(-6.1760, 106.866, source: 'sale');

        $page = Livewire::actingAs($this->admin)
            ->test(StaffActivity::class)
            ->call('selectStaff', $this->staff->id);

        $trail = $page->instance()->mapPayload()['trail'];
        $this->assertCount(2, $trail);
        $this->assertSame('ping', $trail[0]['source']);
        // A sale-sourced point is marked as such, so the map can show where a cup was actually
        // bought rather than merely where the phone was.
        $this->assertSame('sale', $trail[1]['source']);

        $page->call('selectStaff', $this->staff->id);
        $this->assertNull($page->instance()->selectedStaffId);
    }

    public function test_a_past_date_stops_the_page_refreshing_itself(): void
    {
        $page = Livewire::actingAs($this->admin)->test(StaffActivity::class);

        $this->assertTrue($page->instance()->isToday());

        $page->set('date', Carbon::yesterday()->toDateString());

        $this->assertFalse($page->instance()->isToday());
        $page->assertSee('tidak menyegarkan sendiri');
    }

    public function test_the_grid_range_control_only_accepts_the_offered_ranges(): void
    {
        $page = Livewire::actingAs($this->admin)->test(StaffActivity::class);

        $page->call('setGridDays', 7);
        $this->assertSame(7, $page->instance()->gridDays);

        // Anything else falls back to the day rather than becoming an unbounded scan.
        $page->call('setGridDays', 9999);
        $this->assertSame(1, $page->instance()->gridDays);
    }

    public function test_a_role_without_the_activity_module_cannot_open_the_page(): void
    {
        $finance = User::factory()->role(Role::FINANCE)->create();

        $this->actingAs($finance)->get(StaffActivity::getUrl())->assertForbidden();

        PermissionMatrix::set(Role::FINANCE, PanelModule::STAFF_ACTIVITY, ['view']);
        PermissionMatrix::forget();

        $this->actingAs($finance)->get(StaffActivity::getUrl())->assertSuccessful();
    }

    // ── The dashboard list ───────────────────────────────────────────────

    public function test_the_dashboard_widget_lists_each_cart_at_its_location(): void
    {
        $this->sale(9, 10);

        Livewire::actingAs($this->admin)
            ->test(CartSalesTodayWidget::class)
            ->assertSuccessful()
            ->assertSee('Penjualan gerobak')
            ->assertSee('0018')
            ->assertSee('Pulomas')
            ->assertSee('Mufit');
    }

    public function test_the_dashboard_widget_says_so_when_nothing_has_sold_yet(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CartSalesTodayWidget::class)
            ->assertSuccessful()
            ->assertSee('Belum ada transaksi hari ini');
    }

    public function test_a_role_without_the_dashboard_module_does_not_get_the_widget(): void
    {
        $rider = User::factory()->role(Role::RIDER)->create();

        $this->actingAs($rider);

        $this->assertFalse(CartSalesTodayWidget::canView());
    }

    public function test_an_invalid_date_falls_back_to_today_instead_of_throwing(): void
    {
        $page = Livewire::actingAs($this->admin)
            ->test(StaffActivity::class)
            ->set('date', 'bukan-tanggal');

        $page->assertSuccessful();
        $this->assertCount(1, $page->instance()->board());
    }
}
