<?php

namespace Tests\Feature;

use App\Enums\MovementType;
use App\Enums\Role;
use App\Events\SoulEvent;
use App\Jobs\PublishOutboxEvent;
use App\Models\AppNotification;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\Sale;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FailingBroadcaster;
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
        config(['outbox.publish_mode' => 'queue']);

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

        $this->staff = User::factory()->role(Role::STAFF)->create(['name' => 'Rider Uji Satu']);
        $this->barista = User::factory()->role(Role::BARISTA)->create([
            'kitchen_id' => $this->kitchen->id,
        ]);

        StaffAssignment::create([
            'source' => 'hand_to_cart',
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

    public function test_recorded_sale_broadcasts_one_finance_queue_event_without_money(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 10);
        Event::fake([SoulEvent::class]);

        $saleId = $this->sell([['product_id' => $this->coffee->id, 'qty' => 2]])
            ->assertCreated()->json('data.id');

        $event = OutboxEvent::query()->where('name', 'SaleRecorded')->sole();
        $payload = $event->payload_json;
        $this->assertSame(['role.FINANCE', 'role.MANAGEMENT'], $payload['channels']);
        $this->assertSame($this->cart->id, $payload['cart_id']);
        $this->assertSame(Carbon::today()->toDateString(), $payload['operating_date']);
        $this->assertSame($saleId, $payload['source_id']);
        $this->assertSame([], array_intersect(['total_amount_minor', 'cash', 'qris', 'transfer', 'variance'], array_keys($payload)));
        $this->assertNull($event->published_at);
        (new PublishOutboxEvent($event->id))->handle();
        $this->assertNotNull($event->fresh()->published_at);
        Event::assertDispatched(SoulEvent::class, fn (SoulEvent $sent): bool => $sent->payload['event_id'] === $event->event_id);
    }

    public function test_sale_request_succeeds_while_pusher_times_out_in_queued_job(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 10);
        Queue::fake();
        FailingBroadcaster::activate();

        $this->sell([['product_id' => $this->coffee->id, 'qty' => 1]])->assertCreated();

        $row = OutboxEvent::query()->where('name', 'SaleRecorded')->sole();
        $this->assertNull($row->published_at);
        Queue::assertPushed(PublishOutboxEvent::class);
        $this->expectExceptionMessage('pusher timeout');
        (new PublishOutboxEvent($row->id))->handle();
    }

    public function test_sale_request_succeeds_while_inline_pusher_times_out(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 10);
        config(['outbox.publish_mode' => 'inline']);
        Queue::fake();
        FailingBroadcaster::activate();

        $this->sell([['product_id' => $this->coffee->id, 'qty' => 1]])->assertCreated();

        $this->assertSame(1, Sale::query()->count());
        $this->assertNull(OutboxEvent::query()->where('name', 'SaleRecorded')->sole()->published_at);
        Queue::assertNothingPushed();
    }

    public function test_rolled_back_sale_has_no_outbox_row_or_broadcast(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 10);
        Event::fake([SoulEvent::class]);

        try {
            DB::transaction(function (): void {
                $this->sell([['product_id' => $this->coffee->id, 'qty' => 1]])->assertCreated();
                throw new \RuntimeException('rollback test');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback test', $e->getMessage());
        }

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, OutboxEvent::query()->where('name', 'SaleRecorded')->count());
        Event::assertNotDispatched(SoulEvent::class, fn (SoulEvent $sent): bool => $sent->payload['type'] === 'SaleRecorded');
    }

    public function test_only_finance_can_subscribe_to_the_finance_role_channel(): void
    {
        // The test suite's null broadcaster skips channel authorization entirely.
        // Pusher signs auth locally, so this exercises the real callback without network I/O.
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
        ]);
        require base_path('routes/channels.php');
        $body = ['socket_id' => '123.456', 'channel_name' => 'private-role.FINANCE'];

        $this->actingAs(User::factory()->role(Role::FINANCE)->create(), 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', $body)->assertOk();

        foreach ([Role::STAFF, Role::RIDER, Role::BARISTA, Role::ADMINISTRATOR, Role::MANAGEMENT, Role::CONTENT_CREATOR] as $role) {
            $this->actingAs(User::factory()->role($role)->create(), 'sanctum')
                ->postJson('/api/v1/broadcasting/auth', $body)->assertForbidden();
        }

        $this->actingAs(User::factory()->role(Role::MANAGEMENT)->create(), 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '123.456', 'channel_name' => 'private-role.MANAGEMENT',
            ])->assertOk();
        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', [
                'socket_id' => '123.456', 'channel_name' => 'private-role.MANAGEMENT',
            ])->assertForbidden();

        foreach ([Role::FINANCE, Role::MANAGEMENT] as $role) {
            $this->actingAs(User::factory()->role($role)->create(['is_active' => false]), 'sanctum')
                ->postJson('/api/v1/broadcasting/auth', [
                    'socket_id' => '123.456', 'channel_name' => 'private-role.'.$role->value,
                ])->assertForbidden();
        }
    }

    public function test_manual_and_superseded_rider_cannot_subscribe_to_cart_channel(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'test-key',
            'broadcasting.connections.pusher.secret' => 'test-secret',
            'broadcasting.connections.pusher.app_id' => 'test-app',
        ]);
        require base_path('routes/channels.php');
        $body = ['socket_id' => '123.456', 'channel_name' => 'private-cart.'.$this->cart->id];
        $assignment = StaffAssignment::query()->where('user_id', $this->staff->id)->firstOrFail();
        $this->actingAs($this->staff, 'sanctum')->postJson('/api/v1/broadcasting/auth', $body)->assertOk();
        $assignment->update(['source' => 'manual']);
        $this->actingAs($this->staff, 'sanctum')->postJson('/api/v1/broadcasting/auth', $body)->assertForbidden();
        $assignment->update(['source' => 'hand_to_cart', 'superseded_at' => now()]);
        $this->actingAs($this->staff, 'sanctum')->postJson('/api/v1/broadcasting/auth', $body)->assertForbidden();
        $this->actingAs(User::factory()->role(Role::RIDER)->create(), 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', $body)->assertForbidden();
        $assignment->update(['superseded_at' => null]);
        $this->staff->forceFill(['role' => Role::RIDER])->save();
        $this->actingAs($this->staff, 'sanctum')
            ->postJson('/api/v1/broadcasting/auth', $body)->assertForbidden();
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

    public function test_sale_pins_cost_from_the_same_effective_version_as_the_selling_price(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 3);

        $response = $this->sell([['product_id' => $this->coffee->id, 'qty' => 2]])->assertCreated();
        $line = Sale::query()->firstOrFail()->lines()->sole();
        $this->assertSame(20000, $line->unit_price_minor);
        $this->assertSame(10000, $line->unit_cost_minor);
        $this->assertArrayNotHasKey('unit_cost_minor', $response->json('data.lines.0'));

        ProductPriceVersion::create([
            'product_id' => $this->coffee->id,
            'cost_price_minor' => 12000,
            'sell_price_minor' => 25000,
            'effective_from' => now(),
        ]);

        $this->assertSame(10000, $line->fresh()->unit_cost_minor);
    }

    public function test_sale_cost_is_null_when_no_price_version_exists(): void
    {
        $unpriced = Product::create([
            'code' => 'NO-PRICE', 'name' => 'Belum ada harga', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 3, 'is_active' => true,
        ]);
        $this->clockIn();
        $this->stockCart($unpriced, 1);

        $this->sell([['product_id' => $unpriced->id, 'qty' => 1]])->assertCreated();

        $this->assertNull(Sale::query()->firstOrFail()->lines()->sole()->unit_cost_minor);
    }

    public function test_tied_effective_price_versions_match_finance_resource_and_sale_snapshot(): void
    {
        $effectiveFrom = now()->subHours(12);
        foreach ([[11000, 22000], [12000, 23000]] as [$cost, $price]) {
            ProductPriceVersion::create([
                'product_id' => $this->coffee->id, 'cost_price_minor' => $cost,
                'sell_price_minor' => $price, 'effective_from' => $effectiveFrom,
            ]);
        }

        $products = $this->actingAs(User::factory()->role(Role::FINANCE)->create(), 'sanctum')
            ->getJson('/api/v1/products')->assertOk()->json('data');
        $shown = collect($products)->firstWhere('code', 'KOPI');
        $this->assertSame(12000, $shown['cost_price']);
        $this->assertSame(23000, $shown['sell_price']);

        $this->clockIn();
        $this->stockCart($this->coffee, 1);
        $this->sell([['product_id' => $this->coffee->id, 'qty' => 1]])->assertCreated();
        $line = Sale::query()->firstOrFail()->lines()->sole();
        $this->assertSame($shown['cost_price'], $line->unit_cost_minor);
        $this->assertSame($shown['sell_price'], $line->unit_price_minor);
    }

    public function test_field_roles_never_receive_cost_in_product_resources(): void
    {
        foreach ([Role::BARISTA, Role::RIDER, Role::STAFF] as $role) {
            $response = $this->actingAs(User::factory()->role($role)->create(), 'sanctum')
                ->getJson('/api/v1/products')->assertOk();

            foreach ($response->json('data') as $product) {
                $this->assertArrayNotHasKey('cost_price', $product);
                $this->assertArrayNotHasKey('unit_cost_minor', $product);
            }
        }
    }

    public function test_barista_and_runner_cannot_read_sale_resource_through_the_rider_route(): void
    {
        foreach ([Role::BARISTA, Role::RIDER] as $role) {
            $this->actingAs(User::factory()->role($role)->create(), 'sanctum')
                ->getJson('/api/v1/sales')->assertForbidden();
        }
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
        $this->assertSame(1, OutboxEvent::query()->where('name', 'SaleRecorded')->count());
    }

    // ── The gates ────────────────────────────────────────────────────────────

    public function test_sale_is_closed_until_production_opens_today(): void
    {
        $this->stockCart($this->coffee, 10);

        $this->sell([['product_id' => $this->coffee->id, 'qty' => 1]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DAY_NOT_OPEN')
            ->assertJsonPath('message', 'Produksi belum buka absen hari ini, jadi transaksi belum bisa jalan dulu ya.');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_selling_before_absen_is_refused(): void
    {
        $attendance = app(AttendanceService::class);
        $attendance->clockIn($this->barista);
        $attendance->openStaffWindow($this->barista);
        $this->stockCart($this->coffee, 10);

        $this->sell([['product_id' => $this->coffee->id, 'qty' => 1]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Absen dulu sebelum mencatat penjualan.');

        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(10, $this->cartStock($this->coffee));
    }

    public function test_yesterdays_window_does_not_open_todays_sale(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 10);
        Carbon::setTestNow(now()->addDay());

        try {
            $this->sell([['product_id' => $this->coffee->id, 'qty' => 1]], [
                'operating_date' => Carbon::yesterday()->toDateString(),
            ])
                ->assertStatus(422)->assertJsonPath('code', 'DAY_NOT_OPEN');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_unsent_offline_sale_waits_for_todays_window_then_records_at_todays_price(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 5);
        Carbon::setTestNow(now()->addDay());

        try {
            $today = Carbon::today()->toDateString();
            $payload = ['uuid' => (string) Str::uuid(), 'lines' => [['product_id' => $this->coffee->id, 'qty' => 1]]];
            $key = $payload['uuid'];
            $this->actingAs($this->staff, 'sanctum')->withHeader('Idempotency-Key', $key)
                ->postJson('/api/v1/sales', $payload)
                ->assertStatus(422)->assertJsonPath('code', 'DAY_NOT_OPEN');
            ProductPriceVersion::create([
                'product_id' => $this->coffee->id,
                'cost_price_minor' => 15000,
                'sell_price_minor' => 30000,
                'effective_from' => now(),
            ]);
            $attendance = app(AttendanceService::class);
            $attendance->clockIn($this->barista);
            $attendance->openStaffWindow($this->barista);
            $attendance->clockIn($this->staff);
            StaffAssignment::create([
                'source' => 'hand_to_cart',
                'user_id' => $this->staff->id,
                'cart_id' => $this->cart->id,
                'location_id' => $this->location->id,
                'operating_date' => $today,
                'assigned_by' => $this->barista->id,
                'kitchen_id' => $this->kitchen->id,
            ]);

            $this->actingAs($this->staff, 'sanctum')->withHeader('Idempotency-Key', $key)
                ->postJson('/api/v1/sales', $payload)
                ->assertCreated()->assertJsonPath('data.operating_date', $today)
                ->assertJsonPath('data.total_amount', 30000);
            $this->assertSame(4, $this->cartStock($this->coffee));
            $this->assertDatabaseCount('sales', 1);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_recorded_uuid_replays_after_the_window_closes(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 10);
        $payload = ['uuid' => (string) Str::uuid(), 'lines' => [['product_id' => $this->coffee->id, 'qty' => 1]]];
        $first = $this->actingAs($this->staff, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', $payload)->assertCreated();
        Carbon::setTestNow(now()->addDay());

        try {
            $this->actingAs($this->staff, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson('/api/v1/sales', $payload)->assertCreated()
                ->assertJsonPath('data.id', $first->json('data.id'));
            $this->assertDatabaseCount('sales', 1);
            $this->assertSame(9, $this->cartStock($this->coffee));
            $this->assertSame(1, OutboxEvent::query()->where('name', 'SaleRecorded')->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_another_rider_cannot_replay_a_sale_uuid(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 10);
        $payload = ['uuid' => (string) Str::uuid(), 'lines' => [['product_id' => $this->coffee->id, 'qty' => 1]]];
        $this->actingAs($this->staff, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', $payload)->assertCreated();

        $other = User::factory()->role(Role::STAFF)->create();
        $this->actingAs($other, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales', $payload)->assertForbidden();
    }

    public function test_rider_void_is_closed_but_finance_void_still_works(): void
    {
        $this->clockIn();
        $this->stockCart($this->coffee, 10);
        $saleId = $this->sell([['product_id' => $this->coffee->id, 'qty' => 1]])->assertCreated()->json('data.id');
        Carbon::setTestNow(now()->addDay());

        try {
            $this->actingAs($this->staff, 'sanctum')->postJson("/api/v1/sales/{$saleId}/void", ['reason' => 'Salah tekan'])
                ->assertStatus(422)->assertJsonPath('code', 'DAY_NOT_OPEN');
            $this->actingAs(User::factory()->role(Role::FINANCE)->create(), 'sanctum')
                ->postJson("/api/v1/sales/{$saleId}/void", ['reason' => 'Koreksi Finance'])
                ->assertOk();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_non_rider_roles_get_403_for_sale_creation(): void
    {
        foreach ([Role::RIDER, Role::BARISTA, Role::FINANCE, Role::MANAGEMENT, Role::ADMINISTRATOR] as $role) {
            $this->sell([['product_id' => $this->coffee->id, 'qty' => 1]], as: User::factory()->role($role)->create())
                ->assertForbidden();
        }
    }

    public function test_selling_without_a_cart_today_is_refused(): void
    {
        $this->clockIn();
        StaffAssignment::query()->delete();

        $this->sell([['product_id' => $this->coffee->id, 'qty' => 1]])
            ->assertForbidden()
            ->assertJsonPath('code', 'NO_CART_TODAY')
            ->assertJsonPath('message', 'Produksi belum kasih stok ke gerobak kamu hari ini. Minta Produksi Add Stock dulu ya.');
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
        $management = User::factory()->role(Role::MANAGEMENT)->create();
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
        $this->assertNotContains($management->id, $notified);
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
