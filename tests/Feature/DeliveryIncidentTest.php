<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Enums\MovementType;
use App\Enums\Role;
use App\Models\AppNotification;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\DeliveryIncident;
use App\Models\Location;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\RefillRequest;
use App\Models\StaffAssignment;
use App\Models\StockLedger;
use App\Models\User;
use App\Services\StockLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cups broken on the way to a cart: the rider's report and Finance's decision.
 *
 * The tests to read are the two decision tests. They assert where the loss lands — a WASTE_OUT
 * against the KITCHEN, because a refill's cups are still the kitchen's until a delivery posts the
 * transfer — and, for the partial case, that `qty_prepared` comes down so the delivery that
 * follows is measured against what is actually still on the bike.
 *
 * The notification test asserts an exclusion rather than an inclusion: a staff member at another
 * cart must not hear about somebody else's accident. That was explicit, and it is the kind of
 * rule that quietly breaks the moment someone reaches for the convenient `role.STAFF` channel.
 */
class DeliveryIncidentTest extends TestCase
{
    use RefreshDatabase;

    private CentralKitchen $kitchen;

    private Cart $cart;

    private Product $product;

    private User $staff;

    private User $finance;

    private User $barista;

    private User $rider;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kitchen = CentralKitchen::create([
            'name' => 'Dapur Uji', 'address' => 'Jl. Uji 1',
            'open_at' => '00:00:00', 'close_at' => '23:59:59', 'is_active' => true,
        ]);

        $this->cart = Cart::create(['code' => '0099', 'status' => 'active', 'kitchen_id' => $this->kitchen->id]);

        $location = Location::create(['name' => 'Titik Uji', 'lat' => -6.2, 'lng' => 106.8, 'geofence_m' => 100]);

        $this->product = Product::create([
            'code' => 'KOPI', 'name' => 'Kopi Susu', 'unit' => 'cup',
            'is_sellable' => true, 'sort_order' => 1, 'is_active' => true,
        ]);

        ProductPriceVersion::create([
            'product_id' => $this->product->id,
            'cost_price_minor' => 8000,
            'sell_price_minor' => 20000,
            'effective_from' => now()->subDay(),
        ]);

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->staff = User::factory()->role(Role::STAFF)->create(['name' => 'Mufit']);
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->barista = User::factory()->role(Role::BARISTA)->create(['kitchen_id' => $this->kitchen->id]);
        $this->rider = User::factory()->role(Role::RIDER)->create(['name' => 'Agung']);

        StaffAssignment::create([
            'user_id' => $this->staff->id,
            'cart_id' => $this->cart->id,
            'location_id' => $location->id,
            'operating_date' => Carbon::today(),
            'assigned_by' => $this->admin->id,
            'kitchen_id' => $this->kitchen->id,
        ]);

        // The kitchen has to hold the cups it is about to send, or the write-off would be
        // measured against nothing.
        app(StockLedgerService::class)->post(
            locationType: StockLedgerService::KITCHEN,
            locationId: $this->kitchen->id,
            productId: $this->product->id,
            movementType: MovementType::PRODUCTION_IN,
            qty: 100,
            actorId: $this->barista->id,
            kitchenId: $this->kitchen->id,
        );
    }

    // ── fixture: a request in transit ────────────────────────────────────

    private function pickedUpRefill(int $qty = 10): RefillRequest
    {
        $evidence = Media::create([
            'kind' => 'evidence',
            'path' => 'evidence/'.Str::uuid().'.jpg',
            'mime' => 'image/jpeg',
            'bytes' => 1234,
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'exif_taken_at' => now(),
            'uploaded_by' => $this->staff->id,
        ]);

        $created = $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/refills', [
                'uuid' => (string) Str::uuid(),
                'cart_id' => $this->cart->id,
                'evidence_media_id' => $evidence->id,
                'lines' => [['product_id' => $this->product->id, 'qty_requested' => $qty]],
            ])->assertCreated();

        $refill = RefillRequest::findOrFail($created->json('data.id'));
        $line = $refill->lines()->first();

        $this->actingAs($this->finance)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/approve", [
                'version' => $refill->fresh()->version,
                'lines' => [['line_id' => $line->id, 'qty_approved' => $qty]],
            ])->assertOk();

        $this->actingAs($this->barista)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/start-preparing")->assertOk();

        $this->actingAs($this->barista)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/ready", [
                'lines' => [['line_id' => $line->id, 'qty_prepared' => $qty]],
            ])->assertOk();

        $this->actingAs($this->rider)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/claim")->assertOk();

        return $refill->fresh();
    }

    private function report(RefillRequest $refill, int $damaged, array $overrides = [], ?User $as = null)
    {
        $line = $refill->lines()->first();

        $payload = array_merge([
            'uuid' => (string) Str::uuid(),
            'photo' => UploadedFile::fake()->image('insiden-'.Str::uuid().'.jpg', 640, 480),
            'photo_taken_at' => now()->toIso8601String(),
            'lines' => json_encode([['line_id' => $line->id, 'qty_damaged' => $damaged]]),
            'note' => 'Jatuh di Jalan Pemuda, beberapa cup pecah.',
        ], $overrides);

        return $this->actingAs($as ?? $this->rider)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->withHeader('Accept', 'application/json')
            ->post("/api/v1/refills/{$refill->id}/incident", $payload);
    }

    private function kitchenStock(): int
    {
        return app(StockLedgerService::class)
            ->stockFor(StockLedgerService::KITCHEN, $this->kitchen->id, $this->product->id);
    }

    // ── Reporting ────────────────────────────────────────────────────────

    public function test_a_rider_reports_damaged_cups_with_a_photo(): void
    {
        $refill = $this->pickedUpRefill(10);

        $this->report($refill, 4)
            ->assertCreated()
            ->assertJsonPath('data.status', 'REPORTED')
            ->assertJsonPath('data.damaged_qty', 4)
            ->assertJsonPath('data.rider_name', 'Agung');

        $incident = DeliveryIncident::query()->firstOrFail();
        $this->assertSame(IncidentStatus::REPORTED, $incident->status);
        $this->assertNotNull($incident->photo_media_id);
        $this->assertSame('incident', $incident->photo->kind);

        // Reporting decides nothing: the request is still in transit and no stock has moved.
        $this->assertSame('PICKED_UP', $refill->fresh()->status->value);
        $this->assertSame(100, $this->kitchenStock());
    }

    public function test_a_report_without_a_photo_is_refused(): void
    {
        $refill = $this->pickedUpRefill(10);
        $line = $refill->lines()->first();

        $this->actingAs($this->rider)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->withHeader('Accept', 'application/json')
            ->post("/api/v1/refills/{$refill->id}/incident", [
                'uuid' => (string) Str::uuid(),
                'photo_taken_at' => now()->toIso8601String(),
                'lines' => json_encode([['line_id' => $line->id, 'qty_damaged' => 2]]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');

        $this->assertSame(0, DeliveryIncident::query()->count());
    }

    public function test_more_damaged_than_was_sent_is_refused(): void
    {
        $refill = $this->pickedUpRefill(5);

        $this->report($refill, 6)
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');
    }

    public function test_only_the_rider_carrying_it_may_report(): void
    {
        $refill = $this->pickedUpRefill(5);
        $otherRider = User::factory()->role(Role::RIDER)->create();

        $this->report($refill, 2, as: $otherRider)->assertStatus(403);
        // And a staff member cannot file one at all.
        $this->report($refill, 2, as: $this->staff)->assertStatus(403);
    }

    public function test_a_second_open_report_for_the_same_delivery_is_refused(): void
    {
        $refill = $this->pickedUpRefill(10);

        $this->report($refill, 2)->assertCreated();
        $this->report($refill, 2)->assertStatus(409);
    }

    /** R14: a retried submit must not file the accident twice. */
    public function test_replaying_the_same_uuid_returns_the_same_report(): void
    {
        $refill = $this->pickedUpRefill(10);
        $uuid = (string) Str::uuid();

        $this->report($refill, 3, ['uuid' => $uuid])->assertCreated();
        $this->report($refill, 3, ['uuid' => $uuid])->assertCreated();

        $this->assertSame(1, DeliveryIncident::query()->count());
    }

    public function test_the_report_reaches_supervisors_the_kitchen_and_the_waiting_staff_only(): void
    {
        $otherStaff = User::factory()->role(Role::STAFF)->create();
        $refill = $this->pickedUpRefill(10);

        $this->report($refill, 3)->assertCreated();

        $notified = AppNotification::query()
            ->where('type', 'DeliveryIncidentReported')
            ->pluck('user_id')
            ->all();

        $this->assertContains($this->admin->id, $notified);
        $this->assertContains($this->finance->id, $notified);
        $this->assertContains($this->barista->id, $notified);
        $this->assertContains($this->rider->id, $notified);
        // The staff member waiting for this delivery is told — it is their cups.
        $this->assertContains($this->staff->id, $notified);
        // A staff member at another cart is not. This is the exclusion that was asked for.
        $this->assertNotContains($otherStaff->id, $notified);
    }

    // ── Deciding ─────────────────────────────────────────────────────────

    public function test_cancelling_writes_off_the_damaged_cups_and_stops_the_delivery(): void
    {
        $refill = $this->pickedUpRefill(10);
        $this->report($refill, 10)->assertCreated();
        $incident = DeliveryIncident::query()->firstOrFail();

        $this->actingAs($this->finance)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/incidents/{$incident->id}/resolve", [
                'mode' => 'cancel',
                'note' => 'Semua cups pecah, rider kembali ke dapur.',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'RESOLVED_CANCELLED')
            ->assertJsonPath('data.written_off_qty', 10);

        $this->assertSame('CANCELLED', $refill->fresh()->status->value);

        // The loss lands on the kitchen, because that is where these cups still were.
        $this->assertSame(90, $this->kitchenStock());
        $this->assertTrue(
            StockLedger::query()
                ->where('movement_type', MovementType::WASTE_OUT)
                ->where('ref_type', 'delivery_incident')
                ->where('ref_id', $incident->id)
                ->exists(),
        );
    }

    public function test_continuing_partially_reduces_what_the_rider_can_deliver(): void
    {
        $refill = $this->pickedUpRefill(10);
        $line = $refill->lines()->first();

        $this->report($refill, 4)->assertCreated();
        $incident = DeliveryIncident::query()->firstOrFail();

        $this->actingAs($this->finance)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/incidents/{$incident->id}/resolve", [
                'mode' => 'partial',
                'note' => 'Empat cup dipisahkan, sisanya masih layak.',
            ])
            ->assertSuccessful()
            ->assertJsonPath('data.status', 'RESOLVED_PARTIAL')
            ->assertJsonPath('data.written_off_qty', 4);

        // The delivery carries on.
        $this->assertSame('PICKED_UP', $refill->fresh()->status->value);
        // And is now measured against what is actually on the bike, so R4 cannot let the rider
        // record receiving cups that no longer exist.
        $this->assertSame(6, (int) $line->fresh()->qty_prepared);
        $this->assertSame(96, $this->kitchenStock());
        $this->assertNotNull($refill->fresh()->shortfall_reason);
    }

    public function test_a_rider_cannot_decide_their_own_incident(): void
    {
        $refill = $this->pickedUpRefill(10);
        $this->report($refill, 4)->assertCreated();
        $incident = DeliveryIncident::query()->firstOrFail();

        $this->actingAs($this->rider)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/incidents/{$incident->id}/resolve", ['mode' => 'partial'])
            ->assertStatus(403);

        // Nor may the staff member who is waiting for the delivery.
        $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/incidents/{$incident->id}/resolve", ['mode' => 'cancel', 'note' => 'x'])
            ->assertStatus(403);

        $this->assertSame(IncidentStatus::REPORTED, $incident->fresh()->status);
        $this->assertSame(100, $this->kitchenStock());
    }

    public function test_an_incident_cannot_be_decided_twice(): void
    {
        $refill = $this->pickedUpRefill(10);
        $this->report($refill, 4)->assertCreated();
        $incident = DeliveryIncident::query()->firstOrFail();

        foreach (['partial', 'cancel'] as $index => $mode) {
            $response = $this->actingAs($this->admin)
                ->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson("/api/v1/incidents/{$incident->id}/resolve", ['mode' => $mode, 'note' => 'uji']);

            $index === 0
                ? $response->assertSuccessful()
                : $response->assertStatus(409);
        }

        // Only the first decision's write-off happened.
        $this->assertSame(96, $this->kitchenStock());
    }

    public function test_an_unknown_mode_is_a_validation_error(): void
    {
        $refill = $this->pickedUpRefill(10);
        $this->report($refill, 4)->assertCreated();
        $incident = DeliveryIncident::query()->firstOrFail();

        $this->actingAs($this->finance)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/incidents/{$incident->id}/resolve", ['mode' => 'ignore'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');
    }

    // ── Reading ──────────────────────────────────────────────────────────

    public function test_a_rider_sees_their_own_reports_and_a_staff_member_their_own_deliveries(): void
    {
        $refill = $this->pickedUpRefill(10);
        $this->report($refill, 3)->assertCreated();

        $this->actingAs($this->rider)->getJson('/api/v1/incidents')
            ->assertSuccessful()->assertJsonCount(1, 'data');

        $this->actingAs($this->staff)->getJson('/api/v1/incidents')
            ->assertSuccessful()->assertJsonCount(1, 'data');

        $this->actingAs($this->finance)->getJson('/api/v1/incidents?open=1')
            ->assertSuccessful()->assertJsonCount(1, 'data');

        // Someone with no part in it sees nothing, rather than being told a 403 that would
        // itself confirm the row exists.
        $otherRider = User::factory()->role(Role::RIDER)->create();
        $this->actingAs($otherRider)->getJson('/api/v1/incidents')
            ->assertSuccessful()->assertJsonCount(0, 'data');
    }
}
