<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Cart;
use App\Models\DailyCartAllowance;
use App\Models\Product;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\CentralStockService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The showcase flow: brew into central stock, hand cups to a cart, reconcile what comes back.
 *
 * Seeded fixtures (see AllocationTest): cart 0018 has Maufu (STAFF) assigned today,
 * kitchen stock = 200 per product.
 */
class CentralStockTest extends TestCase
{
    use RefreshDatabase;

    private User $barista;

    private User $staff;

    private Cart $cart;

    private Product $product;

    private CentralStockService $service;

    private StockLedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->barista = User::query()->where('phone_e164', '081100000003')->firstOrFail();
        $this->staff = User::query()->where('phone_e164', '081100000005')->firstOrFail();
        $this->cart = Cart::query()->where('code', '0018')->firstOrFail();
        $this->product = Product::query()->where('name', 'Soul Coffee')->firstOrFail();
        $this->service = app(CentralStockService::class);
        $this->ledger = app(StockLedgerService::class);
    }

    private function kitchenStock(): int
    {
        return $this->ledger->stockFor(
            StockLedgerService::KITCHEN,
            $this->barista->kitchen_id,
            $this->product->id,
        );
    }

    private function cartStock(): int
    {
        return $this->ledger->stockFor(StockLedgerService::CART, $this->cart->id, $this->product->id);
    }

    /**
     * Wipes today's roster so a handover has to create it, returning the location to hand back
     * in — `staff_assignments.location_id` is NOT NULL, and deleting the seeded row also deletes
     * the only record of where this cart stands.
     */
    private function clearAssignmentsKeepingLocation(): int
    {
        $locationId = (int) StaffAssignment::query()->firstOrFail()->location_id;
        StaffAssignment::query()->delete();

        return $locationId;
    }

    public function test_brewing_raises_showcase_stock_by_exactly_what_was_brewed(): void
    {
        $before = $this->kitchenStock();

        $this->service->brewIntoShowcase(
            $this->barista,
            $this->barista->kitchen,
            [$this->product->id => 40],
        );

        $this->assertSame($before + 40, $this->kitchenStock());
    }

    public function test_handing_cups_to_a_cart_moves_stock_and_leaves_the_total_unchanged(): void
    {
        $kitchenBefore = $this->kitchenStock();
        $cartBefore = $this->cartStock();

        $this->service->handToCart(
            $this->barista,
            $this->cart,
            $this->staff,
            [$this->product->id => 25],
        );

        $this->assertSame($kitchenBefore - 25, $this->kitchenStock());
        $this->assertSame($cartBefore + 25, $this->cartStock());
        // Nothing is created or destroyed by a handover — only moved.
        $this->assertSame(
            $kitchenBefore + $cartBefore,
            $this->kitchenStock() + $this->cartStock(),
        );
    }

    /** The whole reason the assignment is created here — see CentralStockService::handToCart(). */
    public function test_handing_cups_to_a_cart_creates_todays_assignment_when_there_isnt_one(): void
    {
        $locationId = $this->clearAssignmentsKeepingLocation();

        $this->service->handToCart(
            $this->barista,
            $this->cart,
            $this->staff,
            [$this->product->id => 10],
            locationId: $locationId,
        );

        $assignment = StaffAssignment::query()
            ->where('cart_id', $this->cart->id)
            ->whereDate('operating_date', now()->toDateString())
            ->first();

        $this->assertNotNull($assignment, 'Handing stock over should have put this cart on today\'s roster.');
        $this->assertSame((int) $this->staff->id, (int) $assignment->user_id);
        $this->assertSame((int) $this->barista->id, (int) $assignment->assigned_by);
    }

    public function test_a_second_handover_to_the_same_cart_reuses_the_same_assignment(): void
    {
        $locationId = $this->clearAssignmentsKeepingLocation();

        $first = $this->service->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 10], locationId: $locationId);
        $second = $this->service->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 5], locationId: $locationId);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StaffAssignment::query()->where('cart_id', $this->cart->id)->count());
    }

    /** R11: one cart per staff per operating day. */
    public function test_a_staff_already_on_another_cart_today_is_refused(): void
    {
        $locationId = $this->clearAssignmentsKeepingLocation();
        $otherCart = Cart::query()->where('code', '!=', $this->cart->code)->firstOrFail();

        $this->service->handToCart($this->barista, $otherCart, $this->staff, [$this->product->id => 5], locationId: $locationId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah ditugaskan di gerobak lain');

        $this->service->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 5], locationId: $locationId);
    }

    public function test_a_cart_already_assigned_to_someone_else_today_is_refused(): void
    {
        $otherStaff = User::factory()->role(Role::STAFF)->create([
            'kitchen_id' => $this->barista->kitchen_id,
        ]);

        $this->service->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 5]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah ditugaskan ke staff lain');

        $this->service->handToCart($this->barista, $this->cart, $otherStaff, [$this->product->id => 5]);
    }

    public function test_handing_over_more_than_the_showcase_holds_is_refused_and_moves_nothing(): void
    {
        $kitchenBefore = $this->kitchenStock();
        $cartBefore = $this->cartStock();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stok showcase tidak cukup');

        try {
            $this->service->handToCart(
                $this->barista,
                $this->cart,
                $this->staff,
                [$this->product->id => $kitchenBefore + 1],
            );
        } finally {
            $this->assertSame($kitchenBefore, $this->kitchenStock());
            $this->assertSame($cartBefore, $this->cartStock());
        }
    }

    public function test_the_daily_allowance_is_recorded_at_the_configured_default(): void
    {
        config(['soul.daily_cart_allowance' => 50000]);

        $this->service->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 5]);

        $allowance = DailyCartAllowance::query()
            ->where('cart_id', $this->cart->id)
            ->whereDate('operating_date', now()->toDateString())
            ->firstOrFail();

        $this->assertSame(50000, $allowance->amount_minor);
        $this->assertFalse($allowance->is_edited, 'An untouched default should not look like a deliberate override.');
    }

    public function test_a_barista_overriding_the_allowance_is_recorded_as_edited(): void
    {
        $this->service->handToCart(
            $this->barista,
            $this->cart,
            $this->staff,
            [$this->product->id => 5],
            allowanceAmount: 75000,
        );

        $allowance = DailyCartAllowance::query()
            ->where('cart_id', $this->cart->id)
            ->whereDate('operating_date', now()->toDateString())
            ->firstOrFail();

        $this->assertSame(75000, $allowance->amount_minor);
        $this->assertTrue($allowance->is_edited);
        $this->assertSame((int) $this->barista->id, (int) $allowance->set_by);
    }

    public function test_submitting_the_prefilled_allowance_unchanged_is_not_marked_as_edited(): void
    {
        config(['soul.daily_cart_allowance' => 50000]);

        $this->service->handToCart(
            $this->barista,
            $this->cart,
            $this->staff,
            [$this->product->id => 5],
            allowanceAmount: 50000,
        );

        $allowance = DailyCartAllowance::query()
            ->where('cart_id', $this->cart->id)
            ->firstOrFail();

        $this->assertFalse($allowance->is_edited);
    }

    public function test_returned_cups_go_back_to_the_showcase_and_leave_the_cart(): void
    {
        $this->service->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 30]);

        $kitchenAfterHandover = $this->kitchenStock();
        $cartAfterHandover = $this->cartStock();

        $this->service->closeOutCart(
            $this->barista,
            $this->cart,
            returnedByProductId: [$this->product->id => 8],
        );

        $this->assertSame($kitchenAfterHandover + 8, $this->kitchenStock());
        $this->assertSame($cartAfterHandover - 8, $this->cartStock());
    }

    public function test_rejected_cups_leave_the_cart_without_returning_to_the_showcase(): void
    {
        $this->service->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 30]);

        $kitchenAfterHandover = $this->kitchenStock();
        $cartAfterHandover = $this->cartStock();

        $this->service->closeOutCart(
            $this->barista,
            $this->cart,
            rejectedByProductId: [$this->product->id => 3],
        );

        // A thrown-away cup has no receiving location — it leaves the system entirely.
        $this->assertSame($kitchenAfterHandover, $this->kitchenStock());
        $this->assertSame($cartAfterHandover - 3, $this->cartStock());
    }

    public function test_reporting_more_cups_than_the_cart_holds_is_refused(): void
    {
        $this->service->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 10]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('melebihi stok gerobak');

        $this->service->closeOutCart(
            $this->barista,
            $this->cart,
            returnedByProductId: [$this->product->id => 6],
            rejectedByProductId: [$this->product->id => 6],
        );
    }

    public function test_only_a_barista_may_move_showcase_stock(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Hanya barista');

        $this->service->handToCart($this->staff, $this->cart, $this->staff, [$this->product->id => 5]);
    }

    public function test_the_receiving_user_must_be_staff(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('harus staff');

        $this->service->handToCart($this->barista, $this->cart, $this->barista, [$this->product->id => 5]);
    }

    public function test_zero_quantities_are_dropped_rather_than_written_as_ledger_noise(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tidak ada cups yang diinput');

        $this->service->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 0]);
    }

    public function test_negative_quantities_are_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak boleh negatif');

        $this->service->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => -5]);
    }
}
