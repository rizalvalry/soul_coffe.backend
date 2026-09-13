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
use App\Models\Settlement;
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
 * Undoing a sale — the correction that did not exist until now.
 *
 * The tests worth reading closely are the two boundaries: the window that lets a staff member fix
 * their own mis-tap but not quietly erase a shift after the fact, and the refusal once the day is
 * already settled — because by then the cart's leftover cups have already been moved through the
 * ledger against live stock, and resurrecting a voided sale's cups onto an emptied, reconciled
 * cart would create stock nobody is expecting.
 */
class SaleVoidTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Cart $cart;

    private Location $location;

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
        $this->location = Location::create(['name' => 'Pulomas', 'lat' => -6.18, 'lng' => 106.88]);

        $this->coffee = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);

        ProductPriceVersion::create([
            'product_id' => $this->coffee->id,
            'cost_price_minor' => 10000,
            'sell_price_minor' => 20000,
            'effective_from' => now()->subDay(),
        ]);

        $this->staff = User::factory()->role(Role::STAFF)->create(['name' => 'Mufit']);
        $this->barista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
        $this->finance = User::factory()->role(Role::FINANCE)->create();

        StaffAssignment::create([
            'user_id' => $this->staff->id,
            'cart_id' => $this->cart->id,
            'location_id' => $this->location->id,
            'operating_date' => Carbon::today()->toDateString(),
            'assigned_by' => $this->barista->id,
            'kitchen_id' => $this->kitchen->id,
        ]);
    }

    private function stockCart(int $qty): void
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

    private function cartStock(): int
    {
        return app(StockLedgerService::class)
            ->stockFor(StockLedgerService::CART, $this->cart->id, $this->coffee->id);
    }

    private function clockIn(): void
    {
        $attendance = app(AttendanceService::class);
        $attendance->clockIn($this->barista);
        $attendance->openStaffWindow($this->barista);
        $attendance->clockIn($this->staff);
    }

    private function sell(int $qty): Sale
    {
        $response = $this->actingAs($this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'uuid' => (string) Str::uuid(),
                'lines' => [['product_id' => $this->coffee->id, 'qty' => $qty]],
            ]);

        $response->assertCreated();

        return Sale::query()->findOrFail($response->json('data.id'));
    }

    private function void(Sale $sale, string $reason = 'Salah tekan produk', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->staff, 'sanctum')
            ->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => $reason]);
    }

    // ── The happy path ───────────────────────────────────────────────────────

    public function test_the_staff_member_can_void_their_own_recent_sale(): void
    {
        $this->clockIn();
        $this->stockCart(10);
        $sale = $this->sell(3);

        $this->assertSame(7, $this->cartStock());

        $this->void($sale)
            ->assertSuccessful()
            ->assertJsonPath('data.is_voided', true)
            ->assertJsonPath('data.void_reason', 'Salah tekan produk');

        // The cups are given back through the ledger, not by editing the row.
        $this->assertSame(10, $this->cartStock());

        $fresh = $sale->fresh();
        $this->assertNotNull($fresh->voided_at);
        $this->assertSame($this->staff->id, $fresh->voided_by);
    }

    public function test_voiding_posts_a_compensating_ledger_entry_not_a_rewrite(): void
    {
        $this->clockIn();
        $this->stockCart(10);
        $sale = $this->sell(3);

        $this->void($sale)->assertSuccessful();

        $this->assertTrue(
            \App\Models\StockLedger::query()
                ->where('movement_type', MovementType::SALE_VOID_IN)
                ->where('ref_type', 'sale_void')
                ->where('ref_id', $sale->id)
                ->where('qty_delta', 3)
                ->exists(),
        );

        // The original SALE_OUT row is untouched — the ledger is append-only.
        $this->assertTrue(
            \App\Models\StockLedger::query()
                ->where('movement_type', MovementType::SALE_OUT)
                ->where('ref_type', 'sale')
                ->where('ref_id', $sale->id)
                ->where('qty_delta', -3)
                ->exists(),
        );
    }

    public function test_a_reason_is_required(): void
    {
        $this->clockIn();
        $this->stockCart(10);
        $sale = $this->sell(3);

        $this->actingAs($this->staff, 'sanctum')
            ->postJson("/api/v1/sales/{$sale->id}/void", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertFalse($sale->fresh()->isVoided());
    }

    public function test_administrator_and_finance_are_notified_of_every_void(): void
    {
        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();

        $this->clockIn();
        $this->stockCart(10);
        $sale = $this->sell(3);

        $this->void($sale)->assertSuccessful();

        $notified = AppNotification::query()->where('type', 'SaleVoided')->pluck('user_id')->all();

        $this->assertContains($admin->id, $notified);
        $this->assertContains($this->finance->id, $notified);
    }

    // ── The window ───────────────────────────────────────────────────────────

    public function test_a_staff_member_cannot_void_after_the_window_closes(): void
    {
        config(['soul.sale_void_window_minutes' => 10]);

        $this->clockIn();
        $this->stockCart(10);
        $sale = $this->sell(3);

        $sale->forceFill(['occurred_at' => now()->subMinutes(11)])->save();

        $this->void($sale)
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'dalam 10 menit'));

        $this->assertSame(7, $this->cartStock());
        $this->assertFalse($sale->fresh()->isVoided());
    }

    public function test_a_staff_member_can_still_void_right_at_the_edge_of_the_window(): void
    {
        config(['soul.sale_void_window_minutes' => 10]);

        $this->clockIn();
        $this->stockCart(10);
        $sale = $this->sell(3);

        $sale->forceFill(['occurred_at' => now()->subMinutes(9)])->save();

        $this->void($sale)->assertSuccessful();
    }

    public function test_administrator_is_not_bound_by_the_window(): void
    {
        config(['soul.sale_void_window_minutes' => 10]);

        $admin = User::factory()->role(Role::ADMINISTRATOR)->create();

        $this->clockIn();
        $this->stockCart(10);
        $sale = $this->sell(3);

        $sale->forceFill(['occurred_at' => now()->subDay()])->save();

        $this->void($sale, as: $admin)->assertSuccessful();
        $this->assertSame(10, $this->cartStock());
    }

    public function test_finance_is_not_bound_by_the_window_either(): void
    {
        $this->clockIn();
        $this->stockCart(10);
        $sale = $this->sell(3);

        $sale->forceFill(['occurred_at' => now()->subDay()])->save();

        $this->void($sale, as: $this->finance)->assertSuccessful();
    }

    // ── Who else may not void ────────────────────────────────────────────────

    public function test_a_staff_member_cannot_void_someone_elses_sale(): void
    {
        $otherStaff = User::factory()->role(Role::STAFF)->create();

        $this->clockIn();
        $this->stockCart(10);
        $sale = $this->sell(3);

        $this->void($sale, as: $otherStaff)->assertStatus(422);

        $this->assertSame(7, $this->cartStock());
    }

    public function test_a_barista_cannot_void_a_sale(): void
    {
        $this->clockIn();
        $this->stockCart(10);
        $sale = $this->sell(3);

        $this->actingAs($this->barista, 'sanctum')
            ->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'x'])
            ->assertStatus(403);
    }

    // ── Cannot void twice ────────────────────────────────────────────────────

    public function test_a_sale_cannot_be_voided_twice(): void
    {
        $this->clockIn();
        $this->stockCart(10);
        $sale = $this->sell(3);

        $this->void($sale)->assertSuccessful();
        $this->void($sale)->assertStatus(422);

        // Only one compensating entry, not two.
        $this->assertSame(10, $this->cartStock());
    }

    // ── The settlement boundary ──────────────────────────────────────────────

    public function test_voiding_is_refused_once_the_day_is_reconciled(): void
    {
        $this->clockIn();
        $this->stockCart(10);
        $sale = $this->sell(3);

        Settlement::query()->create([
            'operating_date' => Carbon::today()->toDateString(),
            'cart_id' => $this->cart->id,
            'staff_id' => $this->staff->id,
            'status' => 'RECONCILED',
            'cash_minor' => 60000,
            'declared_total_minor' => 60000,
            'expected_total_minor' => 60000,
            'variance_minor' => 0,
        ]);

        $this->void($sale, as: $this->finance)
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, 'Stock Opname'));

        $this->assertFalse($sale->fresh()->isVoided());
        $this->assertSame(7, $this->cartStock());
    }

    public function test_voiding_is_still_allowed_when_the_settlement_is_not_yet_reconciled(): void
    {
        $this->clockIn();
        $this->stockCart(10);
        $sale = $this->sell(3);

        Settlement::query()->create([
            'operating_date' => Carbon::today()->toDateString(),
            'cart_id' => $this->cart->id,
            'staff_id' => $this->staff->id,
            'status' => 'SUBMITTED',
            'cash_minor' => 60000,
            'declared_total_minor' => 60000,
            'expected_total_minor' => 60000,
            'variance_minor' => 0,
        ]);

        $this->void($sale, as: $this->finance)->assertSuccessful();
    }

    // ── A voided sale drops out of every money/stock aggregate ──────────────

    public function test_a_voided_sale_is_excluded_from_the_dashboard_totals(): void
    {
        $this->clockIn();
        $this->stockCart(10);
        $kept = $this->sell(2);
        $voided = $this->sell(3);

        $this->void($voided, as: $this->finance)->assertSuccessful();

        $totals = app(\App\Services\Reporting\SalesActivityService::class)->dayTotals();

        $this->assertSame(1, $totals['transactions']);
        $this->assertSame(2, $totals['cups']);
        $this->assertSame(40000, $totals['revenue']);
    }

    public function test_a_voided_sale_is_excluded_from_the_settlement_draft(): void
    {
        $this->clockIn();
        $this->stockCart(10);
        $kept = $this->sell(2);
        $voided = $this->sell(3);

        $this->void($voided, as: $this->finance)->assertSuccessful();

        $draft = app(\App\Services\SettlementService::class)->draft($this->cart);

        $this->assertSame(40000, $draft['expected_total']);

        $line = collect($draft['lines'])->firstWhere('product_id', $this->coffee->id);
        $this->assertSame(2, $line['qty_sold']);
    }

    /**
     * The reversal itself must not be miscounted as a fresh morning hand-over — otherwise the
     * settlement draft would tell Finance more cups arrived on the cart today than actually did.
     */
    public function test_the_voids_own_stock_reversal_does_not_inflate_qty_issued(): void
    {
        $this->clockIn();
        $this->stockCart(10);
        $sale = $this->sell(3);

        $this->void($sale)->assertSuccessful();

        $draft = app(\App\Services\SettlementService::class)->draft($this->cart);
        $line = collect($draft['lines'])->firstWhere('product_id', $this->coffee->id);

        // 10 issued via the morning stock, not 13 — the void's SALE_VOID_IN does not count.
        $this->assertSame(10, $line['qty_issued']);
    }

    public function test_a_voided_sale_is_excluded_from_the_suspect_badge(): void
    {
        $this->clockIn();
        $this->stockCart(20);
        $sale = $this->sell(16); // above the default threshold of 15

        $this->assertTrue($sale->fresh()->is_suspect);

        $this->void($sale, as: $this->finance)->assertSuccessful();

        $this->assertNull(\App\Filament\Resources\Sales\SaleResource::getNavigationBadge());
    }

    // ── The staff member's own list still shows a voided sale ────────────────

    public function test_the_staff_members_own_list_still_shows_the_voided_sale(): void
    {
        $this->clockIn();
        $this->stockCart(10);
        $sale = $this->sell(3);
        $this->void($sale)->assertSuccessful();

        $this->actingAs($this->staff, 'sanctum')
            ->getJson('/api/v1/sales')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_voided', true);
    }
}
