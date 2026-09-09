<?php

namespace Tests\Feature\Reporting;

use App\Enums\Role;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\User;
use App\Services\Reporting\SalesActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The numbers behind Aktivitas Staff and the dashboard list.
 *
 * The area × hour grid is the one that matters: the whole request rests on being able to say
 * "Pulomas at 09:00 against Cempaka Mas at 10:00", so the fixture below builds exactly that
 * situation and asserts the grid reports it.
 *
 * Sales are written directly here rather than through SaleService — the service's own rules are
 * proven in SaleTest, and this test is about aggregation, so it needs to place transactions at
 * chosen hours that no live flow would produce in one run.
 */
class SalesActivityServiceTest extends TestCase
{
    use RefreshDatabase;

    private Cart $pulomasCart;

    private Cart $cempakaCart;

    private Location $pulomas;

    private Location $cempaka;

    private User $staffA;

    private User $staffB;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);

        $this->pulomasCart = Cart::create(['code' => '0018', 'status' => 'active', 'kitchen_id' => $kitchen->id]);
        $this->cempakaCart = Cart::create(['code' => '0019', 'status' => 'active', 'kitchen_id' => $kitchen->id]);

        $this->pulomas = Location::create(['name' => 'Pulomas', 'lat' => -6.18, 'lng' => 106.88]);
        $this->cempaka = Location::create(['name' => 'Cempaka Mas', 'lat' => -6.17, 'lng' => 106.87]);

        $this->staffA = User::factory()->role(Role::STAFF)->create(['name' => 'Mufit']);
        $this->staffB = User::factory()->role(Role::STAFF)->create(['name' => 'Dimas']);

        $this->product = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    private function sale(
        Cart $cart,
        User $staff,
        Location $location,
        int $hour,
        int $qty,
        bool $suspect = false,
        ?Carbon $date = null,
    ): Sale {
        $date = ($date ?? Carbon::today())->startOfDay();

        $sale = Sale::query()->create([
            'uuid' => (string) Str::uuid(),
            'operating_date' => $date->toDateString(),
            'cart_id' => $cart->id,
            'staff_id' => $staff->id,
            'location_id' => $location->id,
            'occurred_at' => $date->copy()->setTime($hour, 15),
            'total_qty' => $qty,
            'total_amount_minor' => $qty * 20000,
            'payment_method' => 'cash',
            'is_suspect' => $suspect,
            'suspect_reason' => $suspect ? 'uji' : null,
        ]);

        SaleLine::query()->create([
            'sale_id' => $sale->id,
            'product_id' => $this->product->id,
            'qty' => $qty,
            'unit_price_minor' => 20000,
            'subtotal_minor' => $qty * 20000,
        ]);

        return $sale;
    }

    private function service(): SalesActivityService
    {
        return app(SalesActivityService::class);
    }

    // ── Per cart ─────────────────────────────────────────────────────────

    public function test_each_cart_is_reported_with_its_staff_and_area(): void
    {
        $this->sale($this->pulomasCart, $this->staffA, $this->pulomas, 9, 6);
        $this->sale($this->pulomasCart, $this->staffA, $this->pulomas, 10, 4);
        $this->sale($this->cempakaCart, $this->staffB, $this->cempaka, 10, 3);

        $rows = $this->service()->perCart();

        $this->assertCount(2, $rows);

        // Ordered by cups, so the busiest cart is the first thing an owner reads.
        $this->assertSame('0018', $rows[0]['cart_code']);
        $this->assertSame('Mufit', $rows[0]['staff_name']);
        $this->assertSame('Pulomas', $rows[0]['area']);
        $this->assertSame(2, $rows[0]['transactions']);
        $this->assertSame(10, $rows[0]['cups']);
        $this->assertSame(200000, $rows[0]['revenue']);
        $this->assertSame('10:15', $rows[0]['last_sale_at']->format('H:i'));

        $this->assertSame('0019', $rows[1]['cart_code']);
        $this->assertSame(3, $rows[1]['cups']);
    }

    public function test_flagged_transactions_are_counted_per_cart(): void
    {
        $this->sale($this->pulomasCart, $this->staffA, $this->pulomas, 9, 5);
        $this->sale($this->pulomasCart, $this->staffA, $this->pulomas, 11, 20, suspect: true);

        $rows = $this->service()->perCart();

        $this->assertSame(1, $rows[0]['flagged']);
    }

    public function test_another_days_sales_are_not_mixed_in(): void
    {
        $this->sale($this->pulomasCart, $this->staffA, $this->pulomas, 9, 5, date: Carbon::yesterday());

        $this->assertSame([], $this->service()->perCart());
        $this->assertCount(1, $this->service()->perCart(Carbon::yesterday()));
    }

    // ── Area × hour: the engagement question ─────────────────────────────

    public function test_the_grid_says_which_area_was_busy_at_which_hour(): void
    {
        // The exact situation the request described.
        $this->sale($this->pulomasCart, $this->staffA, $this->pulomas, 9, 10);
        $this->sale($this->cempakaCart, $this->staffB, $this->cempaka, 10, 14);
        $this->sale($this->pulomasCart, $this->staffA, $this->pulomas, 12, 22);

        $grid = $this->service()->areaHours();

        $this->assertSame(['Cempaka Mas', 'Pulomas'], $grid['areas']);
        $this->assertSame([9, 10, 12], $grid['hours']);

        $this->assertSame(10, $grid['cells']['Pulomas|9']);
        $this->assertSame(14, $grid['cells']['Cempaka Mas|10']);
        $this->assertSame(22, $grid['cells']['Pulomas|12']);
        // An hour an area did not trade in has no cell at all, rather than a zero that would
        // read as "we were there and sold nothing".
        $this->assertArrayNotHasKey('Cempaka Mas|9', $grid['cells']);

        $this->assertSame(32, $grid['area_totals']['Pulomas']);
        $this->assertSame(14, $grid['hour_totals'][10]);

        $this->assertSame('Pulomas', $grid['peak']['area']);
        $this->assertSame(12, $grid['peak']['hour']);
        $this->assertSame(22, $grid['peak']['cups']);
    }

    public function test_the_grid_can_span_several_days(): void
    {
        $this->sale($this->pulomasCart, $this->staffA, $this->pulomas, 9, 5);
        $this->sale($this->pulomasCart, $this->staffA, $this->pulomas, 9, 7, date: Carbon::today()->subDays(3));

        $this->assertSame(5, $this->service()->areaHours()['cells']['Pulomas|9']);
        // Seven days back, so both mornings land in the same 09:00 cell.
        $this->assertSame(12, $this->service()->areaHours(null, 7)['cells']['Pulomas|9']);
    }

    public function test_an_empty_day_produces_an_empty_grid_rather_than_an_error(): void
    {
        $grid = $this->service()->areaHours();

        $this->assertSame([], $grid['areas']);
        $this->assertSame([], $grid['hours']);
        $this->assertNull($grid['peak']['area']);
        $this->assertSame(0, $grid['peak']['cups']);
    }

    // ── Flagged queue and totals ─────────────────────────────────────────

    public function test_only_flagged_transactions_appear_in_the_review_queue(): void
    {
        $this->sale($this->pulomasCart, $this->staffA, $this->pulomas, 9, 5);
        $this->sale($this->cempakaCart, $this->staffB, $this->cempaka, 13, 25, suspect: true);

        $suspects = $this->service()->suspects();

        $this->assertCount(1, $suspects);
        $this->assertSame('0019', $suspects[0]['cart_code']);
        $this->assertSame('Dimas', $suspects[0]['staff_name']);
        $this->assertSame(25, $suspects[0]['cups']);
        $this->assertSame('uji', $suspects[0]['reason']);
    }

    public function test_day_totals_add_up_across_carts(): void
    {
        $this->sale($this->pulomasCart, $this->staffA, $this->pulomas, 9, 6);
        $this->sale($this->cempakaCart, $this->staffB, $this->cempaka, 10, 4, suspect: true);

        $totals = $this->service()->dayTotals();

        $this->assertSame(2, $totals['transactions']);
        $this->assertSame(10, $totals['cups']);
        $this->assertSame(200000, $totals['revenue']);
        $this->assertSame(1, $totals['flagged']);
        $this->assertSame(2, $totals['carts']);
    }

    public function test_totals_are_zero_rather_than_null_on_a_quiet_day(): void
    {
        $this->assertSame(
            ['transactions' => 0, 'cups' => 0, 'revenue' => 0, 'flagged' => 0, 'carts' => 0],
            $this->service()->dayTotals(),
        );
    }
}
