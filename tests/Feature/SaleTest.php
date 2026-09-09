<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\AppNotification;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\Sale;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cups sold from a cart — the movement that closes the loop the morning hand-over opened.
 *
 * Read the suspect tests first. The requirement was explicit and unusual: a large transaction is
 * RECORDED and reported, never refused. Several tests assert exactly that, because the tempting
 * implementation (refuse it) only looks like a control — it would block an honest staff member
 * mid-queue while a dishonest one simply taps twice.
 */
class SaleTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Cart $cart;

    private Location $location;

    private Product $coffee;

    private Product $tea;

    private User $staff;

    private User $barista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji',
            'address' => 'Jl. Uji 1',
            'open_at' => '05:00',
            'close_at' => '20:00',
            'is_active' => true,
        ]);

        $this->cart = Cart::create([
            'code' => '0018',
            'status' => 'active',
            'kitchen_id' => $this->kitchen->id,
        ]);

        $this->location = Location::create([
            'name' => 'Pulomas',
            'lat' => -6.18,
            'lng' => 106.88,
        ]);

        $this->coffee = $this->product('KOPI', 'Kopi Susu', 1, sellPrice: 20000);
        $this->tea = $this->product('TEH', 'Teh Manis', 2, sellPrice: 10000);

        $this->staff = User::factory()->role(Role::STAFF)->create(['name' => 'Mufit']);
        $this->barista = User::factory()->role(Role::BARISTA)->create([
            'kitchen_id' => $this->kitchen->id,
        ]);

        StaffAssignment::create([
            'user_id' => $this->staff->id,
            'cart_id' => $this->cart->id,
            'location_id' => $this->location->id,
            'operating_date' => Carbon::today()->toDateString(),
            'assigned_by' => $this->barista->id,
            'kitchen_id' => $this->kitchen->id,
        ]);
    }

    private function product(string $code, string $name, int $sort, int $sellPrice): Product
    {
        $product = Product::create([
            'code' => $code,
            'name' => $name,
            'unit' => 'cup',
            'is_sellable' => true,
            'sort_order' => $sort,
            'is_active' => true,
        ]);

        ProductPriceVersion::create([
            'product_id' => $product->id,
            'cost_price_minor' => (int) ($sellPrice / 2),
            'sell_price_minor' => $sellPrice,
            'effective_from' => now()->subDay(),
        ]);

        return $product;
    }

    private function stockCart(Product $product, int $qty): void
    {
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::CART,
            locationId: $this->cart->id,
            productId: $product->id,
            movementType: MovementType::ALLOCATION_IN,
            qty: $qty,
            actorId: $this->barista->id,
            kitchenId: $this->kitchen->id,
        );
    }

    private function cartStock(Product $product): int
    {
        return app(StockLedgerService::class)
            ->stockFor(StockLedgerService::CART, $this->cart->id, $product->id);
    }

    /**
     * The real absen sequence, not a faked row: a barista clocks in, opens the staff window
     * because the coffee is ready, and only then can the staff member clock in.
     */
    private function clockIn(): void
    {
        $attendance = app(AttendanceService::class);
        $attendance->clockIn($this->barista);
        $attendance->openStaffWindow($this->barista);
        $attendance->clockIn($this->staff);
    }

    /**
     * @param  array<int, array{product_id:int, qty:int}>  $lines
     * @param  array<string, mixed>  $extra
     */
    private function sell(array $lines, array $extra = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', array_merge([
                'uuid' => (string) Str::uuid(),
                'lines' => $lines,
            ], $extra));
    }

    // ── The happy path ───────────────────────────────────────────────────────

    public function test_a_sale_records_the_cups_and_reduces_the_cart_stock(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 30);

        $this->sell([['product_id' => $this->coffee->id, 'qty' => 3]])
            ->assertCreated()
            ->assertJsonPath('data.total_qty', 3)
            // Price pinned from the current version at sale time: 3 × 20.000.
            ->assertJsonPath('data.total_amount', 60000)
            ->assertJsonPath('data.cart_code', '0018')
            ->assertJsonPath('data.location_name', 'Pulomas');

        $this->assertSame(27, $this->cartStock($this->coffee));
    }

    public function test_several_products_in_one_transaction_are_all_posted(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 10);
        $this->stockCart($this->tea, 10);

        $this->sell([
            ['product_id' => $this->coffee->id, 'qty' => 2],
            ['product_id' => $this->tea->id, 'qty' => 1],
        ])->assertCreated()->assertJsonPath('data.total_qty', 3);

        $this->assertSame(8, $this->cartStock($this->coffee));
        $this->assertSame(9, $this->cartStock($this->tea));
        // 2 × 20.000 + 1 × 10.000, each line pinned to its own product's price.
        $this->assertSame(50000, Sale::query()->firstOrFail()->total_amount_minor);
    }

    /** The app sends one row per tile touched, and touching the same tile twice is ordinary. */
    public function test_repeating_a_product_merges_instead_of_erroring(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 10);

        $this->sell([
            ['product_id' => $this->coffee->id, 'qty' => 2],
            ['product_id' => $this->coffee->id, 'qty' => 3],
        ])->assertCreated()->assertJsonPath('data.total_qty', 5);

        $this->assertSame(1, Sale::query()->firstOrFail()->lines()->count());
        $this->assertSame(5, $this->cartStock($this->coffee));
    }

    /** R14: a retried tap on a failing connection must not sell the same cups twice. */
    public function test_replaying_the_same_uuid_does_not_sell_twice(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 10);

        $payload = [
            'uuid' => (string) Str::uuid(),
            'lines' => [['product_id' => $this->coffee->id, 'qty' => 2]],
        ];

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($this->staff, 'sanctum')
                ->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson('/api/v1/sales', $payload)
                ->assertCreated();
        }

        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(8, $this->cartStock($this->coffee));
    }

    // ── The gates ────────────────────────────────────────────────────────────

    public function test_selling_before_absen_is_refused(): void
    {
        $this->stockCart($this->coffee, 10);

        $this->sell([['product_id' => $this->coffee->id, 'qty' => 1]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Absen dulu sebelum mencatat penjualan.');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(10, $this->cartStock($this->coffee));
    }

    public function test_selling_without_a_cart_today_is_refused(): void
    {
        $this->clockIn();
        StaffAssignment::query()->delete();

        $this->sell([['product_id' => $this->coffee->id, 'qty' => 1]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Anda belum ditugaskan ke gerobak mana pun hari ini.');
    }

    /**
     * Overselling is refused because the ledger is append-only: a negative cart would poison
     * every total built on it, including the area analytics this feature exists to feed. The
     * message names the product and what is actually left — "insufficient stock" is useless to
     * someone holding a queue.
     */
    public function test_selling_more_than_the_cart_holds_is_refused_with_the_real_number(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 3);

        $this->sell([['product_id' => $this->coffee->id, 'qty' => 5]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Stok gerobak untuk Kopi Susu hanya 3 cup.');

        $this->assertSame(0, Sale::query()->count());
        // Nothing partial was written — the whole transaction rolled back.
        $this->assertSame(3, $this->cartStock($this->coffee));
    }

    /** A mixed transaction where only the second line is short must write nothing at all. */
    public function test_one_short_line_rolls_back_the_whole_transaction(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 10);
        $this->stockCart($this->tea, 1);

        $this->sell([
            ['product_id' => $this->coffee->id, 'qty' => 2],
            ['product_id' => $this->tea->id, 'qty' => 4],
        ])->assertStatus(422);

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(10, $this->cartStock($this->coffee));
        $this->assertSame(1, $this->cartStock($this->tea));
    }

    public function test_a_barista_cannot_record_a_cart_sale(): void
    {
        $this->sell(
            [['product_id' => $this->coffee->id, 'qty' => 1]],
            as: $this->barista,
        )->assertStatus(403);
    }

    // ── Suspect: a flag, never a veto ────────────────────────────────────────

    public function test_a_large_transaction_is_recorded_and_flagged_not_refused(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 40);

        // 16 > the default threshold of 15.
        $this->sell([['product_id' => $this->coffee->id, 'qty' => 16]])
            ->assertCreated()
            ->assertJsonPath('data.total_qty', 16);

        $sale = Sale::query()->firstOrFail();
        $this->assertTrue($sale->is_suspect);
        $this->assertSame('16 cups dalam satu transaksi (batas 15).', $sale->suspect_reason);
        // The cups really left the cart: flagging changes nothing about the stock.
        $this->assertSame(24, $this->cartStock($this->coffee));
    }

    /** The threshold is on the transaction, not the line — two products can cross it together. */
    public function test_the_threshold_counts_the_whole_transaction(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 20);
        $this->stockCart($this->tea, 20);

        $this->sell([
            ['product_id' => $this->coffee->id, 'qty' => 9],
            ['product_id' => $this->tea->id, 'qty' => 9],
        ])->assertCreated();

        $this->assertTrue(Sale::query()->firstOrFail()->is_suspect);
    }

    public function test_the_flag_notifies_administrator_and_finance_and_nobody_else(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $finance = User::factory()->role(Role::FINANCE)->create();
        $otherStaff = User::factory()->role(Role::STAFF)->create();

        $this->clockIn();
        $this->stockCart($this->coffee, 40);

        $this->sell([['product_id' => $this->coffee->id, 'qty' => 20]])->assertCreated();

        $notified = AppNotification::query()
            ->where('type', 'SaleFlaggedSuspect')
            ->pluck('user_id')
            ->all();

        $this->assertContains($admin->id, $notified);
        $this->assertContains($finance->id, $notified);
        // Not the colleague, and not the staff member who made the sale: a flag is a prompt for
        // someone to look, not an accusation to broadcast.
        $this->assertNotContains($otherStaff->id, $notified);
        $this->assertNotContains($this->staff->id, $notified);
    }

    public function test_exactly_the_threshold_is_not_flagged(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 40);

        $this->sell([['product_id' => $this->coffee->id, 'qty' => 15]])->assertCreated();

        $this->assertFalse(Sale::query()->firstOrFail()->is_suspect);
        $this->assertSame(0, AppNotification::query()->where('type', 'SaleFlaggedSuspect')->count());
    }

    /** The per-cart exemption an Administrator sets in the panel. */
    public function test_a_high_volume_cart_is_not_flagged_but_still_records_the_sale(): void
    {
        $this->cart->update(['high_volume_zone' => true]);

        $this->clockIn();
        $this->stockCart($this->coffee, 40);

        $this->sell([['product_id' => $this->coffee->id, 'qty' => 25]])
            ->assertCreated()
            ->assertJsonPath('data.total_qty', 25);

        $sale = Sale::query()->firstOrFail();
        $this->assertFalse($sale->is_suspect);
        $this->assertNull($sale->suspect_reason);
        $this->assertSame(0, AppNotification::query()->where('type', 'SaleFlaggedSuspect')->count());
        $this->assertSame(15, $this->cartStock($this->coffee));
    }

    public function test_the_threshold_is_configurable(): void
    {
        config(['soul.sale_suspect_qty_threshold' => 5]);

        $this->clockIn();
        $this->stockCart($this->coffee, 40);

        $this->sell([['product_id' => $this->coffee->id, 'qty' => 6]])->assertCreated();

        $this->assertTrue(Sale::query()->firstOrFail()->is_suspect);
    }

    // ── What the area/time analytics needs ───────────────────────────────────

    public function test_the_sale_records_where_and_when_it_happened(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 10);

        $this->sell(
            [['product_id' => $this->coffee->id, 'qty' => 2]],
            ['gps_lat' => -6.1751, 'gps_lng' => 106.865, 'payment_method' => 'qris'],
        )->assertCreated();

        $sale = Sale::query()->firstOrFail();
        $this->assertSame('-6.1751000', $sale->gps_lat);
        $this->assertSame('106.8650000', $sale->gps_lng);
        $this->assertSame($this->location->id, $sale->location_id);
        $this->assertSame('qris', $sale->payment_method);
        // Server clock (R16), because the analysis compares hours across carts.
        $this->assertNotNull($sale->occurred_at);
    }

    /** E10: a lost GPS signal must never block the transaction. */
    public function test_a_sale_without_gps_is_still_accepted(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 10);

        $this->sell(
            [['product_id' => $this->coffee->id, 'qty' => 1]],
            ['gps_unavailable' => true],
        )->assertCreated();

        $sale = Sale::query()->firstOrFail();
        $this->assertTrue($sale->gps_unavailable);
        $this->assertNull($sale->gps_lat);
        // The area is still known from the day's assignment.
        $this->assertSame($this->location->id, $sale->location_id);
    }

    public function test_todays_sales_are_listed_for_the_staff_member(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 20);

        $this->sell([['product_id' => $this->coffee->id, 'qty' => 2]])->assertCreated();
        $this->sell([['product_id' => $this->coffee->id, 'qty' => 3]])->assertCreated();

        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/sales')
            ->assertSuccessful()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_staff_member_never_sees_another_carts_sales(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 20);
        $this->sell([['product_id' => $this->coffee->id, 'qty' => 2]])->assertCreated();

        $otherStaff = User::factory()->role(Role::STAFF)->create();

        $this->actingAs($otherStaff, 'sanctum')
            ->getJson('/api/v1/sales')
            ->assertSuccessful()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_empty_transaction_is_rejected_by_validation(): void
    {
        $this->clockIn();

        $this->sell([])
            ->assertStatus(422)
            ->assertJsonPath('errors.lines.0', 'Pilih dulu cups yang terjual.');
    }
}
