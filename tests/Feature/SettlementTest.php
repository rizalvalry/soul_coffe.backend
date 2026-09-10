<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\Settlement;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\SaleService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Setoran — the money at the Finance desk, and the cups that came back with it.
 *
 * The two tests that carry the weight: Finance is never asked to retype a number the system
 * already holds (the draft computes issued, sold, remaining and expected), and approving a
 * deposit settles the leftover cups against LIVE cart stock rather than a snapshot — which is
 * what stops this flow and the barista's "Tutup Gerobak" from both writing off the same cups.
 */
class SettlementTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Cart $cart;

    private Product $coffee;

    private User $staff;

    private User $barista;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '05:00', 'close_at' => '20:00', 'is_active' => true,
        ]);

        $this->cart = Cart::create(['code' => '0018', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);
        $location = Location::create(['name' => 'Pulomas', 'lat' => -6.18, 'lng' => 106.88]);

        $this->coffee = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);

        ProductPriceVersion::create([
            'product_id' => $this->coffee->id,
            'cost_price_minor' => 8000,
            'sell_price_minor' => 20000,
            'effective_from' => now()->subDay(),
        ]);

        $this->staff = User::factory()->role(Role::STAFF)->create(['name' => 'Mufit']);
        $this->barista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
        $this->finance = User::factory()->role(Role::FINANCE)->create();

        StaffAssignment::create([
            'user_id' => $this->staff->id,
            'cart_id' => $this->cart->id,
            'location_id' => $location->id,
            'operating_date' => Carbon::today()->toDateString(),
            'assigned_by' => $this->barista->id,
            'kitchen_id' => $this->kitchen->id,
        ]);
    }

    /** Issues cups to the cart the way the morning hand-over does. */
    private function issue(int $qty): void
    {
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::CART,
            locationId: $this->cart->id,
            productId: $this->coffee->id,
            movementType: MovementType::ALLOCATION_IN,
            qty: $qty,
            actorId: $this->barista->id,
            kitchenId: $this->kitchen->id,
        );
    }

    /** Sells cups through the real service, so the sales figures are the real ones. */
    private function sell(int $qty): void
    {
        $attendance = app(AttendanceService::class);

        if (! $attendance->hasClockedIn($this->staff)) {
            $attendance->clockIn($this->barista);
            $attendance->openStaffWindow($this->barista);
            $attendance->clockIn($this->staff);
        }

        app(SaleService::class)->record(
            staff: $this->staff,
            lines: [['product_id' => $this->coffee->id, 'qty' => $qty]],
            uuid: (string) Str::uuid(),
        );
    }

    private function cartStock(): int
    {
        return app(StockLedgerService::class)
            ->stockFor(StockLedgerService::CART, $this->cart->id, $this->coffee->id);
    }

    private function kitchenStock(): int
    {
        return app(StockLedgerService::class)
            ->stockFor(StockLedgerService::KITCHEN, $this->kitchen->id, $this->coffee->id);
    }

    private function asFinance(): self
    {
        $this->actingAs($this->finance, 'sanctum');

        return $this;
    }

    // ── What Finance is told before typing anything ──────────────────────

    public function test_the_draft_computes_issued_sold_and_remaining_without_being_asked(): void
    {
        $this->issue(50);
        $this->sell(12);

        $response = $this->asFinance()
            ->getJson("/api/v1/settlements/draft/{$this->cart->id}")
            ->assertSuccessful();

        $this->assertSame('0018', $response->json('data.cart_code'));
        $this->assertSame('Mufit', $response->json('data.staff_name'));
        // 12 × 20.000 — the money Finance should be handed, from the transactions themselves.
        $this->assertSame(240000, $response->json('data.expected_total'));

        $line = $response->json('data.lines.0');
        $this->assertSame(50, $line['qty_issued']);
        $this->assertSame(12, $line['qty_sold']);
        $this->assertSame(38, $line['qty_remaining']);
    }

    public function test_the_queue_lists_todays_carts_with_the_undeposited_ones_first(): void
    {
        $this->issue(20);
        $this->sell(5);

        $response = $this->asFinance()->getJson('/api/v1/settlements/queue')->assertSuccessful();

        $row = $response->json('data.0');
        $this->assertSame('0018', $row['cart_code']);
        $this->assertSame('Mufit', $row['staff_name']);
        $this->assertSame(5, $row['cups_sold']);
        $this->assertSame(15, $row['cups_remaining']);
        $this->assertSame(100000, $row['expected_total']);
        $this->assertNull($row['settlement_id']);
    }

    // ── Taking the money ─────────────────────────────────────────────────

    public function test_a_matching_deposit_is_recorded(): void
    {
        $this->issue(20);
        $this->sell(5); // Rp 100.000

        $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id,
                'cash' => 60000,
                'qris' => 40000,
                'transfer' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('data.declared_total', 100000)
            ->assertJsonPath('data.expected_total', 100000)
            ->assertJsonPath('data.variance', 0)
            ->assertJsonPath('data.status', 'SUBMITTED');
    }

    /**
     * A gap is recorded, not refused — but it has to be explained.
     *
     * Refusing it would push the difference into somebody's pocket or into a rounded-off number;
     * accepting it silently would leave a figure nobody can act on next month.
     */
    public function test_a_short_deposit_needs_a_reason_and_then_is_accepted(): void
    {
        $this->issue(20);
        $this->sell(5); // Rp 100.000

        $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id,
                'cash' => 90000,
                'qris' => 0,
                'transfer' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'Isi alasan selisihnya'));

        $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id,
                'cash' => 90000,
                'qris' => 0,
                'transfer' => 0,
                'variance_reason' => 'Uang kembalian kurang, staff ganti besok.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.variance', -10000);
    }

    public function test_one_deposit_per_cart_per_day(): void
    {
        $this->issue(20);
        $this->sell(1);

        $payload = ['cart_id' => $this->cart->id, 'cash' => 20000, 'qris' => 0, 'transfer' => 0];

        $this->asFinance()->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', $payload)->assertCreated();

        $this->asFinance()->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', $payload)->assertStatus(422);

        $this->assertSame(1, Settlement::query()->count());
    }

    public function test_staff_cannot_record_their_own_deposit(): void
    {
        $this->issue(20);

        $this->actingAs($this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', ['cart_id' => $this->cart->id, 'cash' => 0, 'qris' => 0, 'transfer' => 0])
            ->assertStatus(403);

        $this->actingAs($this->staff, 'sanctum')->getJson('/api/v1/settlements/queue')->assertStatus(403);
    }

    // ── Settling the cups ────────────────────────────────────────────────

    public function test_approving_returns_good_cups_to_the_kitchen_and_writes_off_the_rest(): void
    {
        $this->issue(20);
        $this->sell(5);

        $settlement = $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id, 'cash' => 100000, 'qris' => 0, 'transfer' => 0,
            ])->assertCreated()->json('data.id');

        $kitchenBefore = $this->kitchenStock();

        $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/settlements/{$settlement}/approve", [
                'lines' => [[
                    'product_id' => $this->coffee->id,
                    // 15 left on the cart: 11 still good, 4 no longer sellable.
                    'qty_returned' => 11,
                    'qty_rejected' => 4,
                ]],
                'note' => '4 cup tutupnya bocor.',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'RECONCILED');

        // The cart is empty, the good cups are back in the kitchen, the rejects are gone from
        // both — a write-off has no receiving location.
        $this->assertSame(0, $this->cartStock());
        $this->assertSame($kitchenBefore + 11, $this->kitchenStock());

        $line = Settlement::query()->findOrFail($settlement)->lines()->firstOrFail();
        $this->assertSame(11, $line->qty_remaining);
        $this->assertSame(4, $line->qty_wasted);
    }

    public function test_a_deposit_cannot_be_approved_twice(): void
    {
        $this->issue(10);
        $this->sell(2);

        $settlement = $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id, 'cash' => 40000, 'qris' => 0, 'transfer' => 0,
            ])->json('data.id');

        $lines = [['product_id' => $this->coffee->id, 'qty_returned' => 4, 'qty_rejected' => 0]];

        $this->asFinance()->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/settlements/{$settlement}/approve", ['lines' => $lines])->assertSuccessful();

        $this->asFinance()->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/settlements/{$settlement}/approve", ['lines' => $lines])->assertStatus(422);

        // Only the first approval moved anything: 8 left after selling 2, minus 4 returned.
        $this->assertSame(4, $this->cartStock());
    }

    /**
     * The overlap with the barista's "Tutup Gerobak", which is the failure this design exists to
     * avoid: two flows writing off the same cups and driving the ledger negative.
     */
    public function test_cups_already_closed_out_by_the_barista_cannot_be_settled_again(): void
    {
        $this->issue(10);
        $this->sell(2);

        $settlement = $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id, 'cash' => 40000, 'qris' => 0, 'transfer' => 0,
            ])->json('data.id');

        // The barista gets there first and empties the cart.
        app(\App\Services\CentralStockService::class)->closeOutCart(
            $this->barista,
            $this->cart,
            [$this->coffee->id => 8],
            [],
        );

        $this->assertSame(0, $this->cartStock());

        $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/settlements/{$settlement}/approve", [
                'lines' => [['product_id' => $this->coffee->id, 'qty_returned' => 8, 'qty_rejected' => 0]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'sudah ditutup barista'));

        // And nothing went negative.
        $this->assertSame(0, $this->cartStock());
    }

    /** A cart that sold out has nothing to settle, and approving it is not an error. */
    public function test_approving_with_nothing_left_is_fine(): void
    {
        $this->issue(3);
        $this->sell(3);

        $settlement = $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id, 'cash' => 60000, 'qris' => 0, 'transfer' => 0,
            ])->json('data.id');

        $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/settlements/{$settlement}/approve", [])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'RECONCILED');
    }

    public function test_settling_more_cups_than_the_cart_holds_is_refused(): void
    {
        $this->issue(10);
        $this->sell(2);

        $settlement = $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id, 'cash' => 40000, 'qris' => 0, 'transfer' => 0,
            ])->json('data.id');

        $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/settlements/{$settlement}/approve", [
                'lines' => [['product_id' => $this->coffee->id, 'qty_returned' => 6, 'qty_rejected' => 5]],
            ])
            ->assertStatus(422);

        $this->assertSame(8, $this->cartStock());
    }

    public function test_todays_settlements_are_listed_for_finance(): void
    {
        $this->issue(10);
        $this->sell(2);

        $this->asFinance()->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id, 'cash' => 40000, 'qris' => 0, 'transfer' => 0,
            ])->assertCreated();

        $this->asFinance()
            ->getJson('/api/v1/settlements')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.cart_code', '0018');
    }
}
