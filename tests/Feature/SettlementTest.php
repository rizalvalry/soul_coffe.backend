<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Events\SoulEvent;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\Sale;
use App\Models\Settlement;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\CentralStockService;
use App\Services\DirectSaleService;
use App\Services\Push\PushNotifier;
use App\Services\SaleService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
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

        $this->staff = User::factory()->role(Role::STAFF)->create(['name' => 'Rider Uji Satu']);
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

    public function test_recording_and_approving_a_settlement_broadcasts_queue_changes_after_commit(): void
    {
        $this->issue(10);
        Event::fake([SoulEvent::class]);

        $settlementId = $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id, 'cash' => 0, 'qris' => 0, 'transfer' => 0,
            ])->assertCreated()->json('data.id');

        $recorded = OutboxEvent::query()->where('name', 'SettlementRecorded')->sole();
        $this->assertSame('SUBMITTED', $recorded->payload_json['status']);
        $this->assertSame(['role.FINANCE', 'role.MANAGEMENT'], $recorded->payload_json['channels']);
        $this->assertSame($this->cart->id, $recorded->payload_json['cart_id']);
        $this->assertSame(Carbon::today()->toDateString(), $recorded->payload_json['operating_date']);
        $this->assertSame($settlementId, $recorded->payload_json['source_id']);
        $this->assertSame([], array_intersect(['cash', 'qris', 'transfer', 'variance'], array_keys($recorded->payload_json)));
        $this->assertNotNull($recorded->published_at);
        Event::assertDispatched(SoulEvent::class, fn (SoulEvent $event): bool => $event->payload['event_id'] === $recorded->event_id);

        $this->asFinance()->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/settlements/{$settlementId}/approve", [])
            ->assertOk();

        $approved = OutboxEvent::query()->where('name', 'SettlementApproved')->sole();
        $this->assertSame('RECONCILED', $approved->payload_json['status']);
        $this->assertSame(['role.FINANCE', 'role.MANAGEMENT'], $approved->payload_json['channels']);
        $this->assertSame($this->cart->id, $approved->payload_json['cart_id']);
        $this->assertSame(Carbon::today()->toDateString(), $approved->payload_json['operating_date']);
        $this->assertSame($settlementId, $approved->payload_json['source_id']);
        $this->assertNotNull($approved->published_at);
        Event::assertDispatched(SoulEvent::class, fn (SoulEvent $event): bool => $event->payload['event_id'] === $approved->event_id);

        $this->asFinance()->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/settlements/{$settlementId}/approve", [])->assertStatus(422);
        $this->assertSame(1, OutboxEvent::query()->where('name', 'SettlementApproved')->count());
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
        $this->assertSame('Rider Uji Satu', $response->json('data.staff_name'));
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
        $this->assertSame('Rider Uji Satu', $row['staff_name']);
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

        $this->assertSame(0, AuditLog::query()->where('action', 'settlement.variance_flagged')->count());
        $this->assertSame(0, OutboxEvent::query()->where('name', 'SettlementVarianceFlagged')->count());
    }

    public function test_management_can_read_the_queue_and_record_a_deposit(): void
    {
        $this->issue(10);
        $this->sell(1);

        $management = User::factory()->role(Role::MANAGEMENT)->create();
        $this->actingAs($management, 'sanctum')
            ->getJson('/api/v1/settlements/queue')
            ->assertSuccessful();

        $this->actingAs($management, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id,
                'cash' => 20000,
                'qris' => 0,
                'transfer' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('data.expected_total', 20000)
            ->assertJsonPath('data.status', 'SUBMITTED');
    }

    public function test_staff_and_runner_cannot_use_finance_settlement_endpoints(): void
    {
        foreach ([Role::STAFF, Role::RIDER] as $role) {
            $user = User::factory()->role($role)->create();

            $this->actingAs($user, 'sanctum')
                ->getJson('/api/v1/settlements/queue')
                ->assertForbidden();

            $this->actingAs($user, 'sanctum')
                ->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson('/api/v1/settlements', [
                    'cart_id' => $this->cart->id,
                    'cash' => 0,
                    'qris' => 0,
                    'transfer' => 0,
                ])
                ->assertForbidden();
        }
    }

    public function test_a_short_deposit_without_a_reason_is_flagged_audited_and_can_be_approved_once(): void
    {
        $this->issue(20);
        $this->sell(5); // Rp 100.000

        $settlementId = $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id,
                'cash' => 90000,
                'qris' => 0,
                'transfer' => 0,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'VARIANCE_FLAGGED')
            ->assertJsonPath('data.variance', -10000)
            ->assertJsonPath('data.variance_reason', null)
            ->json('data.id');

        $audit = AuditLog::query()->where('action', 'settlement.variance_flagged')->sole();
        $this->assertSame($this->finance->id, $audit->actor_id);
        $this->assertSame(Role::FINANCE->value, $audit->actor_role);
        $this->assertSame(Settlement::class, $audit->subject_type);
        $this->assertSame($settlementId, $audit->subject_id);
        $this->assertEquals([
            'cart_id' => $this->cart->id,
            'cart_code' => $this->cart->code,
            'operating_date' => Carbon::today()->toDateString(),
            'expected_total_minor' => 100000,
            'declared_total_minor' => 90000,
            'variance_minor' => -10000,
            'variance_reason' => null,
        ], $audit->after_json);

        $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/settlements/{$settlementId}/approve", [])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'RECONCILED')
            ->assertJsonPath('data.variance', -10000)
            ->assertJsonPath('data.reconciled_by', $this->finance->name);

        $this->assertNotNull(Settlement::query()->findOrFail($settlementId)->reconciled_at);
        $this->actingAs($this->staff, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/settlements/{$settlementId}/approve", [])
            ->assertForbidden();
        $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/settlements/{$settlementId}/approve", [])
            ->assertStatus(422);
    }

    public function test_voids_stay_blocked_after_a_flagged_deposit_is_reconciled(): void
    {
        $this->issue(20);
        $this->sell(5);
        $sale = Sale::query()->sole();
        $directSale = app(DirectSaleService::class)->record(
            actor: $this->finance,
            cartId: $this->cart->id,
            lines: [['product_id' => $this->coffee->id, 'qty' => 1]],
        );

        $settlementId = $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id,
                'cash' => 90000,
                'qris' => 0,
                'transfer' => 0,
            ])->assertCreated()->assertJsonPath('data.status', 'VARIANCE_FLAGGED')->json('data.id');

        $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/settlements/{$settlementId}/approve", [])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'RECONCILED')
            ->assertJsonPath('data.variance', -10000);

        $stockBefore = $this->cartStock();
        $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/sales/{$sale->id}/void", ['reason' => 'Salah catat'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'sudah direkonsiliasi'));

        $this->assertNull($sale->fresh()->voided_at);
        $this->assertSame($stockBefore, $this->cartStock());

        try {
            app(DirectSaleService::class)->void($directSale, $this->finance, 'Salah catat');
            $this->fail('Direct sale void should be rejected after reconciliation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('sudah direkonsiliasi', $exception->getMessage());
        }

        $this->assertNull($directSale->fresh()->voided_at);
        $this->assertSame($stockBefore, $this->cartStock());
    }

    public function test_an_over_deposit_with_a_reason_notifies_only_finance_management_and_administrators(): void
    {
        $this->issue(20);
        $this->sell(5);

        $otherFinance = User::factory()->role(Role::FINANCE)->create();
        $management = User::factory()->role(Role::MANAGEMENT)->create();
        $administrator = User::factory()->role(Role::ADMINISTRATOR)->create();
        $inactiveFinance = User::factory()->role(Role::FINANCE)->create(['is_active' => false]);
        $runner = User::factory()->role(Role::RIDER)->create();
        $recipients = [$this->finance->id, $otherFinance->id, $management->id, $administrator->id];
        $channels = array_map(fn (int $id): string => "user.{$id}", $recipients);

        Event::fake([SoulEvent::class]);
        $this->mock(PushNotifier::class)
            ->shouldReceive('notifyUsers')
            ->once()
            ->withArgs(function (array $ids, string $title, string $body, array $data, ?int $excludeUserId) use ($recipients): bool {
                sort($ids);
                sort($recipients);

                return $ids === $recipients
                    && $excludeUserId === null
                    && $data['type'] === 'SettlementVarianceFlagged'
                    && $data['status'] === 'VARIANCE_FLAGGED'
                    && $title !== '' && str_contains($body, 'Gerobak 0018');
            });

        $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id,
                'cash' => 120000,
                'qris' => 0,
                'transfer' => 0,
                'variance_reason' => 'Pelanggan membayar lebih.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'VARIANCE_FLAGGED')
            ->assertJsonPath('data.variance', 20000)
            ->assertJsonPath('data.variance_reason', 'Pelanggan membayar lebih.');

        $outbox = OutboxEvent::query()->where('name', 'SettlementVarianceFlagged')->sole();
        $this->assertSame(
            $channels,
            $outbox->payload_json['channels'],
        );
        $this->assertNotNull($outbox->published_at);
        Event::assertDispatched(SoulEvent::class, fn (SoulEvent $event): bool => $event->payload['event_id'] === $outbox->event_id
            && $event->payload['status'] === 'VARIANCE_FLAGGED'
            && $event->channels === $channels);
        $this->assertEqualsCanonicalizing($recipients, AppNotification::query()
            ->where('event_id', $outbox->event_id)->pluck('user_id')->all());
        $this->assertDatabaseMissing('notifications', ['user_id' => $this->staff->id, 'event_id' => $outbox->event_id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $inactiveFinance->id, 'event_id' => $outbox->event_id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $runner->id, 'event_id' => $outbox->event_id]);
    }

    public function test_retrying_a_flagged_deposit_key_does_not_duplicate_the_settlement_or_alert(): void
    {
        $this->issue(10);
        $this->sell(1);

        $payload = ['cart_id' => $this->cart->id, 'cash' => 15000, 'qris' => 0, 'transfer' => 0];
        $key = (string) Str::uuid();
        $first = $this->asFinance()->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/settlements', $payload)->assertCreated();
        $this->asFinance()->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/settlements', $payload)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, Settlement::query()->count());
        $this->assertSame(1, OutboxEvent::query()->where('name', 'SettlementVarianceFlagged')->count());
        $this->assertSame(1, OutboxEvent::query()->where('name', 'SettlementRecorded')->count());
        $this->assertSame('VARIANCE_FLAGGED', OutboxEvent::query()->where('name', 'SettlementRecorded')->sole()->payload_json['status']);
        $this->assertSame(1, AuditLog::query()->where('action', 'settlement.variance_flagged')->count());
        $this->assertSame(1, AppNotification::query()->where('type', 'SettlementVarianceFlagged')->count());
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

    public function test_approval_rejects_any_status_other_than_submitted_or_variance_flagged(): void
    {
        $this->issue(10);
        $this->sell(2);

        $settlementId = $this->asFinance()
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/settlements', [
                'cart_id' => $this->cart->id, 'cash' => 40000, 'qris' => 0, 'transfer' => 0,
            ])->assertCreated()->json('data.id');

        $stockBefore = $this->cartStock();
        foreach (['RECONCILED', 'UNKNOWN'] as $status) {
            Settlement::query()->findOrFail($settlementId)->update([
                'status' => $status,
                'reconciled_at' => null,
            ]);

            $this->asFinance()
                ->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson("/api/v1/settlements/{$settlementId}/approve", [
                    'lines' => [['product_id' => $this->coffee->id, 'qty_returned' => 4, 'qty_rejected' => 0]],
                ])
                ->assertStatus(422);

            $this->assertSame($status, Settlement::query()->findOrFail($settlementId)->status);
            $this->assertSame($stockBefore, $this->cartStock());
        }
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
        app(CentralStockService::class)->closeOutCart(
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
