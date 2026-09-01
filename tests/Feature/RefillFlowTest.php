<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Cart;
use App\Models\CentralKitchen;
use App\Models\Location;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductPriceVersion;
use App\Models\RefillRequest;
use App\Models\StaffAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Flow B (Refill Request) — the core deliverable. Each test is titled with
 * the invariant/edge-case id it proves, per docs/02 §15 Definition of Done:
 * "every invariant has at least one automated test".
 */
class RefillFlowTest extends TestCase
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
            'name' => 'Dapur Test',
            'address' => 'Jl. Uji Coba No. 1',
            'open_at' => '00:00:00',
            'close_at' => '23:59:59',
            'is_active' => true,
        ]);

        $this->cart = Cart::create([
            'code' => '0099',
            'plate' => null,
            'status' => 'active',
            'kitchen_id' => $this->kitchen->id,
        ]);

        $location = Location::create([
            'name' => 'Test Point',
            'lat' => -6.2,
            'lng' => 106.8,
            'geofence_m' => 100,
            'notes' => null,
        ]);

        $this->product = Product::create([
            'code' => 'TEST-COFFEE',
            'name' => 'Test Coffee',
            'unit' => 'cup',
            'is_sellable' => true,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        ProductPriceVersion::create([
            'product_id' => $this->product->id,
            'cost_price_minor' => 8000,
            'sell_price_minor' => 20000,
            'effective_from' => now()->subDay(),
        ]);

        $this->admin = User::factory()->role(Role::ADMINISTRATOR)->create();
        $this->staff = User::factory()->role(Role::STAFF)->create([
            'pin_hash' => Hash::make('123456'),
        ]);
        $this->finance = User::factory()->role(Role::FINANCE)->create();
        $this->barista = User::factory()->role(Role::BARISTA)->create([
            'kitchen_id' => $this->kitchen->id,
        ]);
        $this->rider = User::factory()->role(Role::RIDER)->create();

        StaffAssignment::create([
            'user_id' => $this->staff->id,
            'cart_id' => $this->cart->id,
            'location_id' => $location->id,
            'operating_date' => Carbon::today(),
            'assigned_by' => $this->admin->id,
            'kitchen_id' => $this->kitchen->id,
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeEvidenceMedia(User $uploader): Media
    {
        return Media::create([
            'kind' => 'evidence',
            'path' => 'evidence/'.Str::uuid().'.jpg',
            'mime' => 'image/jpeg',
            'bytes' => 12345,
            'sha256' => hash('sha256', Str::uuid()->toString()),
            'phash' => null,
            'exif_taken_at' => now(),
            'uploaded_by' => $uploader->id,
        ]);
    }

    /**
     * Submits a refill request as $this->staff for $this->product and
     * returns the created RefillRequest model.
     */
    private function submitRefill(int $qtyRequested = 5): RefillRequest
    {
        $evidence = $this->makeEvidenceMedia($this->staff);

        $response = $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/refills', [
                'uuid' => (string) Str::uuid(),
                'cart_id' => $this->cart->id,
                'evidence_media_id' => $evidence->id,
                'gps_lat' => -6.2,
                'gps_lng' => 106.8,
                'gps_unavailable' => false,
                'client_submitted_at' => now()->toIso8601String(),
                'lines' => [
                    ['product_id' => $this->product->id, 'qty_requested' => $qtyRequested],
                ],
            ]);

        $response->assertCreated();

        return RefillRequest::findOrFail($response->json('data.id'));
    }

    private function approveRefill(RefillRequest $refill, int $qtyApproved, ?int $version = null, ?string $partialReason = null)
    {
        $line = $refill->lines()->first();

        if ($partialReason === null && $qtyApproved < $line->qty_requested) {
            $partialReason = 'Stok terbatas hari ini, disetujui sebagian.';
        }

        return $this->actingAs($this->finance)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/approve", [
                'version' => $version ?? $refill->fresh()->version,
                'lines' => [
                    ['line_id' => $line->id, 'qty_approved' => $qtyApproved],
                ],
                'partial_reason' => $partialReason,
            ]);
    }

    /**
     * Drives a fresh refill request all the way to READY_TO_PICK using the
     * full qty chain, and returns it fresh from the DB.
     */
    private function driveToReadyToPick(int $qty = 5): RefillRequest
    {
        $refill = $this->submitRefill($qty);
        $this->approveRefill($refill, $qty)->assertOk();

        $this->actingAs($this->barista)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/start-preparing")
            ->assertOk();

        $line = $refill->lines()->first();

        $this->actingAs($this->barista)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/ready", [
                'lines' => [
                    ['line_id' => $line->id, 'qty_prepared' => $qty],
                ],
            ])
            ->assertOk();

        return $refill->fresh();
    }

    private function driveToPickedUp(int $qty = 5): RefillRequest
    {
        $refill = $this->driveToReadyToPick($qty);

        $this->actingAs($this->rider)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/claim")
            ->assertOk();

        return $refill->fresh();
    }

    // ── R1 ───────────────────────────────────────────────────────────────

    /**
     * R1: the single most important test in the codebase. A BARISTA calling
     * start-preparing on a SUBMITTED (not yet approved) request must get 409
     * — the server gate, not merely a disabled UI button.
     */
    public function test_r1_barista_cannot_start_preparing_before_finance_approval(): void
    {
        $refill = $this->submitRefill();

        $this->assertSame('SUBMITTED', $refill->status->value);

        $response = $this->actingAs($this->barista)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/start-preparing");

        $response->assertStatus(409);

        $this->assertSame('SUBMITTED', $refill->fresh()->status->value);
    }

    // ── R4 monotonic chain ───────────────────────────────────────────────

    public function test_r4_qty_approved_greater_than_requested_returns_422(): void
    {
        $refill = $this->submitRefill(5);

        $this->approveRefill($refill, 6)->assertStatus(422);
    }

    public function test_r4_qty_prepared_greater_than_approved_returns_422(): void
    {
        $refill = $this->submitRefill(5);
        $this->approveRefill($refill, 3)->assertOk();

        $this->actingAs($this->barista)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/start-preparing")
            ->assertOk();

        $line = $refill->lines()->first();

        $response = $this->actingAs($this->barista)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/ready", [
                'lines' => [['line_id' => $line->id, 'qty_prepared' => 4]],
            ]);

        $response->assertStatus(422);
    }

    public function test_r4_qty_received_greater_than_prepared_returns_422(): void
    {
        $refill = $this->driveToPickedUp(5);
        $line = $refill->lines()->first();

        $response = $this->deliver($refill, [
            ['line_id' => $line->id, 'qty_received' => 6],
        ]);

        $response->assertStatus(422);
    }

    // ── R2 ───────────────────────────────────────────────────────────────

    public function test_r2_second_open_refill_for_same_cart_returns_409(): void
    {
        $this->submitRefill(3);

        $evidence = $this->makeEvidenceMedia($this->staff);

        $response = $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/refills', [
                'uuid' => (string) Str::uuid(),
                'cart_id' => $this->cart->id,
                'evidence_media_id' => $evidence->id,
                'lines' => [['product_id' => $this->product->id, 'qty_requested' => 2]],
            ]);

        $response->assertStatus(409);
        $response->assertJson(['message' => 'Masih ada request yang belum selesai untuk gerobak ini.']);
    }

    // ── R12 idempotency ──────────────────────────────────────────────────

    public function test_r12_same_idempotency_key_twice_creates_one_request_and_replays(): void
    {
        $evidence = $this->makeEvidenceMedia($this->staff);
        $key = (string) Str::uuid();

        $payload = [
            'uuid' => (string) Str::uuid(),
            'cart_id' => $this->cart->id,
            'evidence_media_id' => $evidence->id,
            'lines' => [['product_id' => $this->product->id, 'qty_requested' => 4]],
        ];

        $first = $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/refills', $payload);
        $first->assertCreated();

        $second = $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/refills', $payload);

        $second->assertCreated();
        $second->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        $this->assertSame(1, RefillRequest::query()->where('cart_id', $this->cart->id)->count());
    }

    // ── E1 concurrent approval ───────────────────────────────────────────

    public function test_e1_two_approvals_with_stale_version_one_wins_one_conflicts(): void
    {
        $refill = $this->submitRefill(5);
        $staleVersion = $refill->version;

        $winner = $this->approveRefill($refill, 5, $staleVersion);
        $winner->assertOk();

        $loser = $this->approveRefill($refill, 5, $staleVersion);
        $loser->assertStatus(409);
    }

    // ── E2 concurrent claim ──────────────────────────────────────────────

    public function test_e2_two_riders_claiming_only_one_wins(): void
    {
        $refill = $this->driveToReadyToPick(5);
        $secondRider = User::factory()->role(Role::RIDER)->create();

        $first = $this->actingAs($this->rider)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/claim");
        $first->assertOk();

        $second = $this->actingAs($secondRider)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/claim");
        $second->assertStatus(409);
    }

    // ── R15 cost visibility ──────────────────────────────────────────────

    public function test_r15_barista_response_never_contains_cost_fields(): void
    {
        $refill = $this->submitRefill(5);
        $this->approveRefill($refill, 5)->assertOk();

        $response = $this->actingAs($this->barista)->getJson("/api/v1/refills/{$refill->id}");

        $response->assertOk();

        $body = $response->getContent();
        $this->assertStringNotContainsString('total_cost', $body);
        $this->assertStringNotContainsString('unit_cost', $body);
        $this->assertStringNotContainsString('line_cost', $body);
    }

    public function test_r15_finance_response_contains_cost_fields(): void
    {
        $refill = $this->submitRefill(5);

        $response = $this->actingAs($this->finance)->getJson("/api/v1/refills/{$refill->id}");

        $response->assertOk();
        $response->assertJsonPath('data.total_cost', 40000);
        $response->assertJsonPath('data.lines.0.unit_cost', 8000);
    }

    // ── E7 PIN fallback ────────────────────────────────────────────────

    public function test_e7_pin_fallback_with_correct_pin_succeeds(): void
    {
        $refill = $this->driveToPickedUp(5);
        $line = $refill->lines()->first();

        $response = $this->deliver($refill, [
            ['line_id' => $line->id, 'qty_received' => 5],
        ], [
            'signature_method' => 'pin_fallback',
            'staff_pin' => '123456',
            'stroke_count' => 0,
        ]);

        $response->assertSuccessful(); // 200 (ledger posted) or 202 (E19 retry) — never a failure.
        $this->assertContains($response->status(), [200, 202]);

        $refill->refresh();
        $this->assertContains($refill->status->value, ['DELIVERED', 'CLOSED']);
        $this->assertSame('pin_fallback', $refill->signature_method->value);
    }

    public function test_e7_pin_fallback_with_wrong_pin_returns_422(): void
    {
        $refill = $this->driveToPickedUp(5);
        $line = $refill->lines()->first();

        $response = $this->deliver($refill, [
            ['line_id' => $line->id, 'qty_received' => 5],
        ], [
            'signature_method' => 'pin_fallback',
            'staff_pin' => '000000',
            'stroke_count' => 0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('staff_pin');

        $this->assertSame('PICKED_UP', $refill->fresh()->status->value);
    }

    // ── role isolation ───────────────────────────────────────────────────

    public function test_staff_token_calling_approve_is_forbidden(): void
    {
        $refill = $this->submitRefill(5);

        $response = $this->actingAs($this->staff)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/refills/{$refill->id}/approve", [
                'lines' => [['line_id' => $refill->lines()->first()->id, 'qty_approved' => 5]],
            ]);

        $response->assertStatus(403);
    }

    // ── deliver helper ───────────────────────────────────────────────────

    /**
     * @param  array<int, array{line_id:int, qty_received:int}>  $lines
     * @param  array<string, mixed>  $overrides
     */
    private function deliver(RefillRequest $refill, array $lines, array $overrides = [])
    {
        $signature = UploadedFile::fake()->image('signature.png', 100, 60);

        $payload = array_merge([
            'signature_method' => 'staff_signature',
            'staff_id' => $refill->staff_id,
            'stroke_count' => 5,
            'lines' => json_encode($lines),
            'gps_lat' => -6.2,
            'gps_lng' => 106.8,
            'gps_unavailable' => false,
        ], $overrides);

        // lines may be overridden as a raw array by callers — re-encode either way.
        if (is_array($payload['lines'])) {
            $payload['lines'] = json_encode($payload['lines']);
        }

        return $this->actingAs($this->rider)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->withHeader('Accept', 'application/json')
            ->post("/api/v1/refills/{$refill->id}/deliver", array_merge($payload, [
                'signature' => $signature,
            ]));
    }
}
