<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Cart;
use App\Models\DailyCartAllowance;
use App\Models\Location;
use App\Models\Media;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\CentralStockService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The showcase flow: brew into central stock, hand cups to a cart, reconcile what comes back.
 *
 * Seeded fixtures (see AllocationTest): cart 0018 has Rider Uji Dua (STAFF) assigned today,
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
        $attendance = app(AttendanceService::class);
        $attendance->clockIn($this->barista);
        $attendance->openStaffWindow($this->barista);
        $attendance->clockIn($this->staff);
        StaffAssignment::query()->create([
            'user_id' => $this->staff->id,
            'cart_id' => $this->cart->id,
            'location_id' => Location::query()->firstOrFail()->id,
            'operating_date' => today(),
            'assigned_by' => User::query()->where('role', Role::ADMINISTRATOR)->firstOrFail()->id,
            'kitchen_id' => $this->cart->kitchen_id,
            'source' => 'hand_to_cart',
        ]);
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

        DB::transaction(function () use ($locationId): void {
            $this->service->handToCart(
                $this->barista,
                $this->cart,
                $this->staff,
                [$this->product->id => 10],
                locationId: $locationId,
            );
            $this->assertNull(OutboxEvent::query()->where('name', 'CartAssignmentChanged')->sole()->published_at);
        });

        $assignment = StaffAssignment::query()
            ->where('cart_id', $this->cart->id)
            ->whereDate('operating_date', now()->toDateString())
            ->first();

        $this->assertNotNull($assignment, 'Handing stock over should have put this cart on today\'s roster.');
        $this->assertSame((int) $this->staff->id, (int) $assignment->user_id);
        $this->assertSame((int) $this->barista->id, (int) $assignment->assigned_by);

        $this->actingAs($this->staff, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.today_cart.id', $this->cart->id)
            ->assertJsonPath('data.today_cart.code', $this->cart->code);

        $event = OutboxEvent::query()->where('name', 'CartAssignmentChanged')->sole();
        $this->assertSame(['user.'.$this->staff->id], $event->payload_json['channels']);
        $this->assertSame($this->cart->id, $event->payload_json['cart_id']);
        $this->assertNotNull($event->published_at, 'Outbox delivery follows the transaction commit.');
        $stockEvent = OutboxEvent::query()->where('name', 'CartStockHandedOver')->sole();
        $this->assertContains('user.'.$this->staff->id, $stockEvent->payload_json['channels']);
        $this->assertNotContains('cart.'.$this->cart->id, $stockEvent->payload_json['channels']);
    }

    public function test_me_has_no_cart_before_handover_and_after_operating_day_changes(): void
    {
        $locationId = $this->clearAssignmentsKeepingLocation();
        $this->actingAs($this->staff, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.today_cart', null);

        StaffAssignment::query()->create([
            'user_id' => $this->staff->id,
            'cart_id' => $this->cart->id,
            'location_id' => $locationId,
            'operating_date' => today()->subDay(),
            'assigned_by' => $this->barista->id,
            'kitchen_id' => $this->barista->kitchen_id,
        ]);
        $this->actingAs($this->staff, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.today_cart', null);
    }

    public function test_sale_and_refill_without_todays_cart_return_stable_code_and_friendly_copy(): void
    {
        $this->clearAssignmentsKeepingLocation();
        $saleMessage = 'Produksi belum kasih stok ke gerobak kamu hari ini. Minta Produksi Add Stock dulu ya.';
        $refillMessage = 'Produksi belum kasih stok ke Coffee Bike kamu hari ini. Minta Produksi Add Stock dulu ya.';
        $this->actingAs($this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', [
                'uuid' => (string) Str::uuid(),
                'lines' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])
            ->assertForbidden()->assertJsonPath('code', 'NO_CART_TODAY')->assertJsonPath('message', $saleMessage);

        $media = Media::query()->create([
            'kind' => 'evidence', 'path' => 'evidence/test.jpg', 'mime' => 'image/jpeg',
            'bytes' => 100, 'sha256' => hash('sha256', 'test'), 'uploaded_by' => $this->staff->id,
        ]);
        $this->actingAs($this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/refills', [
                'uuid' => (string) Str::uuid(), 'cart_id' => $this->cart->id,
                'evidence_media_id' => $media->id,
                'lines' => [['product_id' => $this->product->id, 'qty_requested' => 1]],
            ])
            ->assertForbidden()->assertJsonPath('code', 'NO_CART_TODAY')->assertJsonPath('message', $refillMessage);
    }

    public function test_a_second_handover_to_the_same_cart_reuses_the_same_assignment(): void
    {
        $locationId = $this->clearAssignmentsKeepingLocation();

        $first = $this->service->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 10], locationId: $locationId);
        $second = $this->service->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 5], locationId: $locationId);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StaffAssignment::query()->where('cart_id', $this->cart->id)->count());
        $this->assertSame(1, OutboxEvent::query()->where('name', 'CartAssignmentChanged')->count());
    }

    public function test_a_new_handover_replaces_the_riders_previous_cart_without_erasing_history(): void
    {
        $locationId = $this->clearAssignmentsKeepingLocation();
        $otherCart = Cart::query()->where('code', '!=', $this->cart->code)->firstOrFail();

        $old = $this->service->handToCart($this->barista, $otherCart, $this->staff, [$this->product->id => 5], locationId: $locationId);
        $new = $this->service->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 5], locationId: $locationId);
        $this->assertNotSame($old->id, $new->id);
        $this->assertNotNull($old->fresh()->superseded_at);
        $this->actingAs($this->staff, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.today_cart.id', $this->cart->id);
    }

    public function test_a_cart_handover_to_another_rider_preserves_old_row_and_notifies_both(): void
    {
        $otherStaff = User::factory()->role(Role::STAFF)->create([
            'kitchen_id' => $this->barista->kitchen_id,
        ]);
        app(AttendanceService::class)->clockIn($otherStaff);

        $old = $this->service->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 5]);
        $new = $this->service->handToCart($this->barista, $this->cart, $otherStaff, [$this->product->id => 5]);
        $this->assertNotSame($old->id, $new->id);
        $this->assertNotNull($old->fresh()->superseded_at);
        $this->assertSame(2, StaffAssignment::query()->where('cart_id', $this->cart->id)->count());
        $this->actingAs($this->staff, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.today_cart', null);
        $this->actingAs($otherStaff, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.today_cart.id', $this->cart->id);
        $events = OutboxEvent::query()->where('name', 'CartAssignmentChanged')->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(['user.'.$otherStaff->id], $events[0]->payload_json['channels']);
        $this->assertSame(['user.'.$this->staff->id], $events[1]->payload_json['channels']);
    }

    public function test_manual_row_does_not_become_todays_cart_or_block_first_location_free_handover(): void
    {
        $this->clearAssignmentsKeepingLocation();
        $otherStaff = User::factory()->role(Role::STAFF)->create(['kitchen_id' => $this->barista->kitchen_id]);
        app(AttendanceService::class)->clockIn($otherStaff);
        $manual = StaffAssignment::query()->create([
            'user_id' => $otherStaff->id,
            'cart_id' => $this->cart->id,
            'location_id' => null,
            'operating_date' => today(),
            'assigned_by' => $this->barista->id,
            'kitchen_id' => $this->barista->kitchen_id,
            'source' => 'manual',
        ]);
        $this->actingAs($otherStaff, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.today_cart', null);
        $active = $this->service->handToCart($this->barista, $this->cart, $this->staff, [$this->product->id => 5]);
        $this->assertNull($active->location_id);
        $this->assertSame('manual', $manual->fresh()->source);
        $this->assertNull($manual->fresh()->superseded_at);
        $this->actingAs($this->staff, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.today_cart.id', $this->cart->id);
    }

    public function test_provenance_backfill_preserves_old_add_stock_and_excludes_manual_row(): void
    {
        $locationId = $this->clearAssignmentsKeepingLocation();
        $otherCart = Cart::query()->where('id', '!=', $this->cart->id)->firstOrFail();
        $otherStaff = User::factory()->role(Role::STAFF)->create(['kitchen_id' => $this->barista->kitchen_id]);
        $manual = StaffAssignment::query()->create([
            'user_id' => $otherStaff->id,
            'cart_id' => $otherCart->id,
            'location_id' => $locationId,
            'operating_date' => today(),
            'assigned_by' => $this->barista->id,
            'kitchen_id' => $this->barista->kitchen_id,
            'source' => 'manual',
        ]);
        $handover = $this->service->handToCart(
            $this->barista, $this->cart, $this->staff, [$this->product->id => 5], locationId: $locationId,
        );
        (require database_path('migrations/2026_10_18_120000_add_staff_assignment_provenance.php'))->backfill();
        $this->assertSame('hand_to_cart', $handover->fresh()->source);
        $this->assertSame('manual', $manual->fresh()->source);
        $this->actingAs($this->staff, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.today_cart.id', $this->cart->id);
        $this->actingAs($otherStaff, 'sanctum')->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.today_cart', null);
    }

    public function test_rollback_refuses_to_discard_same_day_assignment_history(): void
    {
        $old = StaffAssignment::query()->where('cart_id', $this->cart->id)->firstOrFail();
        $otherStaff = User::factory()->role(Role::STAFF)->create(['kitchen_id' => $this->barista->kitchen_id]);
        StaffAssignment::query()->create([
            'user_id' => $otherStaff->id,
            'cart_id' => $this->cart->id,
            'location_id' => $old->location_id,
            'operating_date' => $old->operating_date,
            'assigned_by' => $this->barista->id,
            'kitchen_id' => $this->barista->kitchen_id,
            'source' => 'hand_to_cart',
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('audit-preserving plan');
        (require database_path('migrations/2026_10_18_120000_add_staff_assignment_provenance.php'))
            ->down();
    }

    public function test_rollback_refuses_a_null_assignment_location(): void
    {
        StaffAssignment::query()->where('cart_id', $this->cart->id)->update(['location_id' => null]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('audit-preserving plan');
        (require database_path('migrations/2026_10_18_120000_add_staff_assignment_provenance.php'))
            ->down();
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
        $closeEvent = OutboxEvent::query()->where('name', 'CartClosedOut')->sole();
        $this->assertContains('user.'.$this->staff->id, $closeEvent->payload_json['channels']);
        $this->assertNotContains('cart.'.$this->cart->id, $closeEvent->payload_json['channels']);
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
        $this->expectExceptionMessage('Hanya Produksi');

        $this->service->handToCart($this->staff, $this->cart, $this->staff, [$this->product->id => 5]);
    }

    public function test_the_receiving_user_must_be_staff(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('harus rider');

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
